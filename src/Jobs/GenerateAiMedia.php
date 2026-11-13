<?php

namespace Dashed\DashedFiles\Jobs;

use Throwable;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Dashed\DashedFiles\Classes\MediaLibrary;
use Dashed\DashedFiles\Models\AiImageOperation;
use Dashed\DashedFiles\Classes\Sources\AiSource;
use Illuminate\Support\Carbon;
use Dashed\DashedFiles\Services\AiSpend;
use Dashed\DashedFiles\Services\AiCeilingReached;
use Dashed\DashedFiles\Services\AiImageGenerator;
use Dashed\DashedFiles\Classes\Sources\MediaSourceException;
use Dashed\DashedFiles\Classes\Sources\MediaSourceRegistry;

/**
 * Maakt de afbeelding van één aanvraag uit het keuzevenster of de
 * beheerpagina. Uniek per aanvraag, één poging (een tweede poging is een
 * tweede betaalde aanroep).
 *
 * De tijd, opgeteld: de aanroep naar de AI-dienst hooguit HTTP_TIMEOUT (50 s),
 * het ophalen van het resultaat hooguit DOWNLOAD_TIMEOUT (15 s, over alle
 * sprongen samen), en de inname (conversies staan in de wachtrij) met
 * INGEST_MARGIN (15 s) marge: 80 s, onder de `timeout` van de taak (85 s).
 * Eis aan de wachtrij: `retry_after` moet boven die 85 s liggen (de
 * standaard 90 s volstaat), anders pakt een tweede werker de taak op
 * terwijl de eerste nog loopt. Ook dan betaalt de tweede niet: de wissel
 * naar "bezig" is voorwaardelijk (alleen vanuit "in de wachtrij").
 *
 * De taak gooit zelf niets: de uitkomst staat op de aanvraag, met een code
 * in plaats van de ruwe fout. Bij een synchrone wachtrij loopt dit in het
 * verzoek van de beheerder; dan stemt de taak de time-out af op de tijd die
 * PHP het verzoek nog geeft, of weigert hij, zodat een aanvraag nooit op
 * "bezig" blijft hangen.
 */
class GenerateAiMedia implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 85;

    public int $uniqueFor = 600;

    /** De time-out van de aanroep naar de AI-dienst zelf. */
    public const HTTP_TIMEOUT = 50;

    /** De totale tijd voor het ophalen van het resultaat. */
    public const DOWNLOAD_TIMEOUT = 15;

    /** Ruimte voor de inname en de rest van de taak. */
    public const INGEST_MARGIN = 15;

    /** Korter dan dit heeft de aanroep naar de dienst geen zin. */
    public const MIN_HTTP_TIMEOUT = 20;

    public function __construct(public string $request)
    {
    }

    public function uniqueId(): string
    {
        return $this->request;
    }

    public function handle(): void
    {
        $operation = AiSource::operation($this->request);

        // Alleen een aanvraag die nog wacht: nooit twee keer betalen voor dezelfde.
        if (! $operation || $operation->status !== AiImageOperation::STATUS_QUEUED) {
            return;
        }

        // Te laat opgepakt: het venster heeft hem al als mislukt getoond.
        if ($operation->updated_at && $operation->updated_at->lt(Carbon::now()->subSeconds(AiSpend::staleSeconds()))) {
            AiImageOperation::query()->whereKey($operation->getKey())->where('status', AiImageOperation::STATUS_QUEUED)
                ->update(['status' => AiImageOperation::STATUS_FAILED, 'error' => 'expired']);

            return;
        }

        $httpTimeout = $this->httpTimeout();

        // Voorwaardelijk: een tweede uitvoering van dezelfde aanvraag (twee
        // werkers, of een herhaalde taak) wint dit niet en doet niets.
        $claimed = AiImageOperation::query()
            ->whereKey($operation->getKey())
            ->where('status', AiImageOperation::STATUS_QUEUED)
            ->update($httpTimeout === null
                ? ['status' => AiImageOperation::STATUS_FAILED, 'error' => 'time']
                : ['status' => AiImageOperation::STATUS_PROCESSING, 'updated_at' => Carbon::now()]);

        if ($claimed !== 1 || $httpTimeout === null) {
            return;
        }

        $operation->refresh();

        try {
            $source = app(MediaSourceRegistry::class)->find(AiSource::KEY, $operation->site_id);

            if (! $source instanceof AiSource) {
                $this->markFailed($operation, 'unavailable');

                return;
            }

            $params = (array) $operation->params;
            $reference = null;

            if (is_numeric($params['reference_item_id'] ?? null)) {
                // Opnieuw getoetst: het item kan sinds de aanvraag privé zijn geworden.
                $reference = $source->referenceUrl((int) $params['reference_item_id']);
            }

            $folderId = is_numeric($params['folder_id'] ?? null) && app(MediaLibrary::class)->folder((int) $params['folder_id']) ? (int) $params['folder_id'] : null;
            $userId = is_numeric($operation->context['user_id'] ?? null) ? (int) $operation->context['user_id'] : null;

            $itemId = app(AiImageGenerator::class)->generateInto(
                (string) ($params['prompt'] ?? ''),
                (string) ($params['ratio'] ?? '1:1'),
                $reference,
                $folderId,
                $operation->site_id,
                [
                    'purpose' => 'media-source-ai',
                    'timeout' => $httpTimeout,
                    'download_timeout' => self::DOWNLOAD_TIMEOUT,
                    'user_id' => $userId,
                    // Deze aanvraag is de betaalde aanroep zelf; ze telt niet nog eens.
                    'operation' => $operation,
                    'throw_on_limit' => true,
                ],
            );

            if (! $itemId) {
                $this->markFailed($operation, 'failed');

                return;
            }

            $operation->update(['status' => AiImageOperation::STATUS_DONE, 'result_media_id' => $itemId, 'error' => null]);
        } catch (AiCeilingReached) {
            $this->markFailed($operation, 'limit');
        } catch (MediaSourceException $exception) {
            $this->markFailed($operation, $exception->reason === 'reference' ? 'reference' : 'failed');
        } catch (Throwable $exception) {
            report($exception);
            $this->markFailed($operation, 'failed');
        }
    }

    /**
     * De time-out voor de aanroep naar de dienst, of null als er niet
     * genoeg tijd is. Alleen bij een synchrone wachtrij is er een grens
     * van buiten: de max_execution_time van het verzoek waarin hij draait.
     */
    protected function httpTimeout(): ?int
    {
        $left = $this->secondsLeftInRequest();

        if ($left === null) {
            return self::HTTP_TIMEOUT;
        }

        $available = (int) floor($left - self::DOWNLOAD_TIMEOUT - self::INGEST_MARGIN);

        return $available >= self::MIN_HTTP_TIMEOUT ? min(self::HTTP_TIMEOUT, $available) : null;
    }

    /** Hoeveel seconden PHP dit verzoek nog geeft, of null zonder grens (een werker, of geen limiet). */
    protected function secondsLeftInRequest(): ?float
    {
        $sync = $this->job === null
            ? config('queue.connections.' . config('queue.default') . '.driver') === 'sync' && ! app()->runningInConsole()
            : $this->job instanceof \Illuminate\Queue\Jobs\SyncJob;

        $limit = (int) ini_get('max_execution_time');

        if (! $sync || $limit <= 0) {
            return null;
        }

        $started = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? (defined('LARAVEL_START') ? LARAVEL_START : microtime(true)));

        return $limit - (microtime(true) - $started);
    }

    /** Een time-out of een werker die wegvalt. */
    public function failed(?Throwable $exception): void
    {
        $operation = AiSource::operation($this->request);

        if ($operation && ! $operation->isFinished()) {
            $this->markFailed($operation, 'failed');
        }
    }

    private function markFailed(AiImageOperation $operation, string $code): void
    {
        $operation->update(['status' => AiImageOperation::STATUS_FAILED, 'error' => $code]);
    }
}
