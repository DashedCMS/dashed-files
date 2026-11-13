<?php

namespace Dashed\DashedFiles\Commands;

use Illuminate\Console\Command;
use Dashed\DashedFiles\Classes\MediaLibrary;

class MediaEngineCommand extends Command
{
    protected $signature = 'dashed:media-engine {engine? : legacy or dashed; leave out to show the current engine}';

    protected $description = 'Show or set which media library runs in the panel: the vendor one (legacy) or our own (dashed)';

    private function filamentHasCachedComponents(): bool
    {
        return (bool) glob(app()->bootstrapPath('cache/filament/panels') . '/*.php');
    }

    public function handle(): int
    {
        $engine = $this->argument('engine');

        if ($engine !== null) {
            if (! in_array($engine, MediaLibrary::ENGINES, true)) {
                $this->error('Unknown engine "' . $engine . '". Use one of: ' . implode(', ', MediaLibrary::ENGINES) . '.');

                return self::FAILURE;
            }

            // De slug van de pagina en de pluginlijst liggen bij het
            // opstarten vast: gecachete routes horen daarom opnieuw gebouwd.
            $routesWereCached = app()->routesAreCached();

            // Het oude pakket kent de prullenbak niet: wat erin ligt gaat eerst terug.
            if ($engine === MediaLibrary::ENGINE_LEGACY) {
                $restored = app(\Dashed\DashedFiles\Classes\Browse\MediaBulk::class)->restoreEverything();

                if ($restored['items'] + $restored['folders'] > 0) {
                    $this->warn('Restored ' . $restored['items'] . ' item(s) and ' . $restored['folders'] . ' folder(s) from the trash: the legacy package has no trash and would show them as ordinary items.');
                    \Illuminate\Support\Facades\Log::warning('dashed:media-engine legacy: restored ' . $restored['items'] . ' item(s) and ' . $restored['folders'] . ' folder(s) from the media trash.');
                }
            }

            MediaLibrary::setEngine($engine);
            $this->info('Stored: ' . $engine . '.');

            if ($routesWereCached) {
                $this->callSilently('route:clear');
                $this->warn('Route cache cleared; run route:cache again as part of your deploy.');
            }

            if ($this->filamentHasCachedComponents() && $this->getApplication()?->has('filament:clear-cached-components')) {
                $this->callSilently('filament:clear-cached-components');
                $this->warn('Filament component cache cleared; run filament:cache-components again as part of your deploy.');
            }

            $this->line('Restart queue workers (queue:restart, Horizon) and reload php-fpm (opcache) so every process boots with the new engine.');
        }

        $forced = config('dashed-files.media_engine');

        if (in_array($forced, MediaLibrary::ENGINES, true)) {
            $this->warn('DASHED_MEDIA_ENGINE forces "' . $forced . '"; the stored setting is ignored while it is set.');
        }

        $this->line('Media engine: ' . MediaLibrary::engine());
        $this->line('Source: ' . match (MediaLibrary::engineSource()) {
            'env' => 'DASHED_MEDIA_ENGINE',
            'setting' => 'stored setting',
            default => 'default (nothing set, the default is ' . MediaLibrary::ENGINE_DEFAULT . ')',
        });

        return self::SUCCESS;
    }
}
