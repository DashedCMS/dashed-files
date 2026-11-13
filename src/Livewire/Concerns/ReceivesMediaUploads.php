<?php

namespace Dashed\DashedFiles\Livewire\Concerns;

use Throwable;
use Livewire\WithFileUploads;
use Illuminate\Http\UploadedFile;
use Livewire\Attributes\Renderless;
use Illuminate\Support\Facades\DB;
use Dashed\DashedFiles\Classes\MediaLibrary;
use Dashed\DashedFiles\Classes\Browse\BrowseSchema;
use Dashed\DashedFiles\Classes\Ingest\IncomingMedia;
use Dashed\DashedFiles\Classes\Browse\MediaItemPayload;
use Dashed\DashedFiles\Classes\Ingest\MediaRejectedException;

/**
 * Ontvangt bestanden die de browser per stuk met $wire.upload('uploads.<sleutel>')
 * stuurt en zet ze via de inname in de bibliotheek. Los van de lijst, zodat
 * een uploadvenster zonder raster hem ook kan gebruiken.
 */
trait ReceivesMediaUploads
{
    use WithFileUploads {
        _uploadErrored as protected livewireUploadErrored;
    }

    /** @var array<string, mixed> tijdelijke uploads, op de sleutel van de browser */
    public array $uploads = [];

    abstract protected function authorizeMedia(string $ability): void;

    /**
     * Livewire roept dit aan als de uploadroute een bestand weigert. De
     * foutcallback in de browser krijgt zelf niets mee, dus de reden gaat
     * als eigen event mee, vóór het event dat die callback afvuurt. Alleen
     * voor de uploads van de bibliotheek; elk ander veld houdt Livewire's gedrag.
     */
    public function _uploadErrored($name, $errorsInJson, $isMultiple)
    {
        if (! is_string($name) || ! str_starts_with($name, 'uploads.')) {
            return $this->livewireUploadErrored($name, $errorsInJson, $isMultiple);
        }

        $this->authorizeMedia('upload');

        $errors = is_string($errorsInJson) ? (json_decode($errorsInJson, true)['errors'] ?? []) : [];
        $message = collect(is_array($errors) ? $errors : [])->flatten()->first(fn ($error) => is_string($error) && $error !== '');

        // Laravels eigen regels noemen het veld bij zijn technische naam
        // ("files.0"); zo'n zin is voor een beheerder geen uitleg.
        if (is_string($message) && preg_match('/files(\.\d+)?|uploads\./i', $message)) {
            $message = null;
        }

        $this->dispatch('dashed-media-upload-errored', name: $name, message: $message, refused: is_string($errorsInJson))->self();
        $this->dispatch('upload:errored', name: $name)->self();
        $this->skipRender();
    }

    /**
     * $posters: per sleutel van een video de sleutel van een poster die de
     * browser zelf maakte (alleen als de server geen ffmpeg heeft). De
     * poster hoort bij het bestand dat in dit verzoek is ingenomen: de
     * browser stuurt nooit een item-id mee.
     *
     * @param  array<int, string>  $keys
     * @param  array<string, string>  $posters
     * @return array<string, array{ok: bool, item?: array, duplicate?: bool, restored?: bool, error?: string}> per sleutel
     */
    #[Renderless]
    public function storeUploads(array $keys, int|string|null $folderId = null, string $onDuplicate = 'reuse', array $posters = []): array
    {
        $this->authorizeMedia('upload');

        // 'create' is de bewuste keuze "toch als nieuw bestand toevoegen" na een melding dat het bestand al bestond.
        $onDuplicate = $onDuplicate === 'create' ? 'create' : 'reuse';

        $keys = array_slice(array_values(array_unique(array_filter($keys, fn ($key) => is_string($key) && preg_match('/^[a-z0-9]{1,40}$/i', $key)))), 0, 50);

        $library = app(MediaLibrary::class);
        $userId = is_numeric(auth()->id()) ? (int) auth()->id() : null;
        $folder = is_int($folderId) || (is_string($folderId) && ctype_digit($folderId)) ? (int) $folderId : null;
        $folderGone = $folder !== null && ! $library->folder($folder);

        $results = [];

        foreach ($keys as $key) {
            $file = $this->uploads[$key] ?? null;

            try {
                if (! $file instanceof UploadedFile) {
                    $results[$key] = ['ok' => false, 'error' => __('Het bestand is niet aangekomen. Probeer het opnieuw.')];

                    continue;
                }

                if ($folderGone) {
                    $results[$key] = ['ok' => false, 'error' => __('De doelmap bestaat niet meer.')];

                    continue;
                }

                $result = $library->ingest(IncomingMedia::fromUploadedFile($file, 'upload', $folder)->uploadedBy($userId), $onDuplicate);

                // Alleen voor een bestand dat hier net nieuw is binnengekomen, nooit voor een bestaand item.
                if (! $result->wasDuplicate) {
                    $this->attachBrowserPoster($result->item, $posters[$key] ?? null);
                }

                $item = $result->item->loadMissing(BrowseSchema::hasRelations() ? ['media', 'tags'] : ['media']);

                // Een nieuw bestand kan nog geen favoriet zijn, een bestaand wel.
                $favorite = $result->wasDuplicate && $userId && BrowseSchema::hasRelations()
                    && DB::table(BrowseSchema::FAVORITES)->where('user_id', $userId)->where('media_item_id', $item->getKey())->exists();

                $results[$key] = [
                    'ok' => true,
                    'item' => MediaItemPayload::for($item, $favorite),
                    'duplicate' => $result->wasDuplicate,
                    'restored' => $result->wasRestored,
                ];
            } catch (MediaRejectedException $exception) {
                $results[$key] = ['ok' => false, 'error' => $exception->getMessage()];
            } catch (Throwable $exception) {
                report($exception);

                $results[$key] = ['ok' => false, 'error' => __('Het bestand kon niet worden opgeslagen.')];
            } finally {
                if ($file instanceof UploadedFile) {
                    try {
                        method_exists($file, 'delete') ? $file->delete() : @unlink((string) $file->getRealPath());
                    } catch (Throwable) {
                        // Al weg: de inname ruimt het bestand na een geslaagde opslag zelf op.
                    }
                }

                // Alleen de behandelde sleutel: een ander bestand kan in
                // hetzelfde verzoek net binnen zijn en wacht nog op zijn beurt.
                unset($this->uploads[$key]);

                $this->discardUpload($posters[$key] ?? null);
            }
        }

        return $results;
    }

    /**
     * Neemt de poster die de browser meestuurde in als voorvertoning van
     * een item dat in dit verzoek is geupload. Doet niets als de server
     * zelf posters maakt, en weigert alles wat geen kleine jpeg is.
     */
    protected function attachBrowserPoster(\Dashed\DashedFiles\Models\MediaItem $item, mixed $posterKey): bool
    {
        $poster = is_string($posterKey) && preg_match('/^[a-z0-9]{1,40}$/i', $posterKey) ? ($this->uploads[$posterKey] ?? null) : null;

        if (! $poster instanceof UploadedFile) {
            return false;
        }

        return (bool) rescue(fn () => app(\Dashed\DashedFiles\Classes\Preview\MediaPreviews::class)
            ->acceptBrowserPoster($item, (string) ($poster->getRealPath() ?: $poster->getPathname())), false);
    }

    /** Ruimt een tijdelijke upload op die niet (meer) nodig is. */
    protected function discardUpload(mixed $key): void
    {
        if (! is_string($key) || ! preg_match('/^[a-z0-9]{1,40}$/i', $key) || ! array_key_exists($key, $this->uploads)) {
            return;
        }

        $file = $this->uploads[$key];

        if ($file instanceof UploadedFile) {
            try {
                method_exists($file, 'delete') ? $file->delete() : @unlink((string) $file->getRealPath());
            } catch (Throwable) {
                // Al weg.
            }
        }

        unset($this->uploads[$key]);
    }

    /**
     * Vervangt het bestand van een bestaand item door een upload. Het nieuwe
     * bestand krijgt dezelfde controles als elke upload.
     *
     * @return array{ok: bool, item?: array, error?: string}
     */
    #[Renderless]
    public function replaceWithUpload(int $id, string $key, ?string $posterKey = null): array
    {
        // Eerst het recht dat een keuzevenster niet heeft.
        $this->authorizeMedia('edit');
        $this->authorizeMedia('upload');

        $file = preg_match('/^[a-z0-9]{1,40}$/i', $key) ? ($this->uploads[$key] ?? null) : null;
        $library = app(MediaLibrary::class);

        try {
            if (! $file instanceof UploadedFile) {
                return ['ok' => false, 'error' => __('Het bestand is niet aangekomen. Probeer het opnieuw.')];
            }

            $item = $library->find($id);

            if (! $item) {
                return ['ok' => false, 'error' => __('Dit bestand bestaat niet meer.')];
            }

            abort_unless($library->canUpdate(auth()->user(), $item), 403);

            $userId = is_numeric(auth()->id()) ? (int) auth()->id() : null;
            $item = $library->replace($item, IncomingMedia::fromUploadedFile($file, 'upload', $item->folder_id === null ? null : (int) $item->folder_id)->uploadedBy($userId));

            // De poster die de browser van het nieuwe bestand maakte (alleen zonder ffmpeg op de server).
            $this->attachBrowserPoster($item, $posterKey);

            $item->loadMissing(BrowseSchema::hasRelations() ? ['media', 'tags'] : ['media']);

            return ['ok' => true, 'item' => MediaItemPayload::for($item)];
        } catch (MediaRejectedException $exception) {
            return ['ok' => false, 'error' => $exception->getMessage()];
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return ['ok' => false, 'error' => __('Het bestand kon niet worden vervangen.')];
        } finally {
            if ($file instanceof UploadedFile) {
                try {
                    method_exists($file, 'delete') ? $file->delete() : @unlink((string) $file->getRealPath());
                } catch (Throwable) {
                    // Al weg: de inname ruimt het bestand na een geslaagde opslag zelf op.
                }
            }

            unset($this->uploads[$key]);

            $this->discardUpload($posterKey);
        }
    }
}
