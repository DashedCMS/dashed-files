<?php

namespace Dashed\DashedFiles\Jobs;

use Throwable;
use Illuminate\Bus\Queueable;
use Dashed\DashedFiles\Models\MediaItem;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Dashed\DashedFiles\Classes\Queue\WorkBudget;
use Dashed\DashedFiles\Classes\ConversionDefinition;

/**
 * Eenmalig herstel: `heighten` zette tot november 2026 een breedte in plaats
 * van een hoogte, dus de bestaande heighten-bestanden hebben de verkeerde
 * maat. Ze staan als gegenereerd op de mediarij en worden daarom nooit
 * vanzelf opnieuw gemaakt.
 *
 * Eén taak doet een paar items en plant zichzelf met vertraging opnieuw in,
 * zodat een grote bibliotheek de wachtrij niet overspoelt. Is het werkbudget
 * op voordat de portie klaar is, dan gaat de volgende taak verder bij het
 * eerste item dat nog niet aan de beurt was.
 */
class RegenerateHeightenConversionsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const PER_ROUND = 5;

    public const DELAY_SECONDS = 60;

    public int $tries = 1;

    public int $timeout = WorkBudget::MAX_JOB_TIMEOUT;

    public int $uniqueFor = 3600;

    public function __construct(
        public int $afterId = 0,
        public int $perRound = self::PER_ROUND,
    ) {
    }

    // Met het beginpunt erin: de taak plant zijn opvolger in terwijl zijn
    // eigen slot nog vastzit, en die zou anders geweigerd worden.
    public function uniqueId(): string
    {
        return 'regenerate-heighten-conversions-' . $this->afterId;
    }

    public static function pending(int $afterId = 0): Builder
    {
        return MediaItem::query()
            ->where('id', '>', $afterId)
            ->where('conversions', 'like', '%heighten%')
            ->orderBy('id');
    }

    /**
     * De heighten-formaten van een item, uit de kolom `conversions`.
     *
     * @return array<int, string>
     */
    public static function heightenNames(?string $conversionsJson): array
    {
        $stored = json_decode($conversionsJson ?: '[]', true);
        $names = [];

        foreach (is_array($stored) ? $stored : [] as $key) {
            $definition = is_string($key) || is_array($key) ? ConversionDefinition::fromLegacy($key) : null;

            if ($definition?->mode === ConversionDefinition::MODE_HEIGHT) {
                $names[] = $definition->name;
            }
        }

        return array_values(array_unique($names));
    }

    public function handle(FileManipulator $manipulator): void
    {
        $items = static::pending($this->afterId)->limit($this->perRound)->get();

        if ($items->isEmpty()) {
            return;
        }

        $budget = WorkBudget::start();
        $last = $this->afterId;

        foreach ($items as $item) {
            // Minstens één item per taak, anders komt een trage bibliotheek nooit vooruit.
            if ($last !== $this->afterId && $budget->spent()) {
                break;
            }

            $last = (int) $item->getKey();
            $file = $item->file();

            // Alleen wat er al staat: een formaat dat nog niet gegenereerd
            // is, wordt op aanvraag meteen met de juiste maat gemaakt.
            $names = array_values(array_filter(
                static::heightenNames($item->conversions),
                fn (string $name) => $file?->hasGeneratedConversion($name),
            ));

            if (! $file || $names === []) {
                continue;
            }

            try {
                $manipulator->createDerivedFiles($file, $names);
            } catch (Throwable $exception) {
                // Eén kapot bestand mag de rest van de ronde niet tegenhouden.
                report($exception);
            }
        }

        static::dispatch($last, $this->perRound)
            ->onQueue($this->queue)
            ->delay(now()->addSeconds(self::DELAY_SECONDS));
    }
}
