<?php

namespace Dashed\DashedFiles\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Dashed\DashedFiles\Jobs\BackfillMediaUsages;
use Dashed\DashedFiles\Classes\Browse\BrowseSchema;
use Dashed\DashedFiles\Classes\Usage\MediaUsageIndexer;
use Dashed\DashedFiles\Classes\Usage\MediaUsageRegistry;

class MediaUsagesCommand extends Command
{
    protected $signature = 'dashed:media-usages
        {--model= : Only this model class (full class name or its short name)}
        {--fresh : Empty the index (of that model) first; the index counts as not ready until the run is done}
        {--queue : Queue one job per model instead of running here}
        {--recent : Only rows changed in the last two days (the nightly round); never touches the ready flag}
        {--audit : List the media fields in app/ and tell which models are registered; changes nothing}';

    protected $description = 'Rebuild the "where used" index of the media library';

    public function handle(MediaUsageIndexer $indexer): int
    {
        if ($this->option('audit')) {
            return $this->audit($indexer);
        }

        if (! BrowseSchema::hasUsages()) {
            $this->error('The table dashed__media_usages does not exist yet: run the migrations first.');

            return self::FAILURE;
        }

        $classes = $indexer->indexableModels();

        if ($only = $this->option('model')) {
            $only = ltrim((string) $only, '\\');
            $classes = array_values(array_filter($classes, fn (string $class) => $class === $only || class_basename($class) === $only));

            if ($classes === []) {
                $this->error("No registered model matches [{$only}].");

                return self::FAILURE;
            }
        }

        if ($this->option('recent')) {
            $this->option('queue')
                ? $indexer->queueRecent($classes)
                : $this->run_($indexer, $classes, now()->subDays(MediaUsageIndexer::RECENT_DAYS)->toDateTimeString());

            $this->info(count($classes) . ' model(s) ' . ($this->option('queue') ? 'queued' : 'done') . ' for recent changes.');

            return self::SUCCESS;
        }

        // Een ronde voor één model laat de vlag zoals hij was; een volledige
        // ronde zet hem aan het eind.
        $markReady = ! $only || $indexer->isReady();

        if ($this->option('fresh')) {
            // Eerst de vlag weg: tussen leegmaken en klaar zegt een lege
            // index niets, en dan mag niets "ongebruikt" heten of samengevoegd worden.
            $indexer->clearReady();

            $only
                ? DB::table(BrowseSchema::USAGES)->whereIn('model_type', $classes)->delete()
                : DB::table(BrowseSchema::USAGES)->delete();
        }

        if ($this->option('queue')) {
            // De laatste taak zet de vlag.
            $only && ! $this->option('fresh')
                ? array_map(fn (string $class) => BackfillMediaUsages::dispatch($class), $classes)
                : $indexer->queueBaseline($classes, $markReady);

            $this->info(count($classes) . ' model(s) queued.');

            return self::SUCCESS;
        }

        $this->run_($indexer, $classes, null);

        if ($markReady && (! $only || $this->option('fresh'))) {
            $indexer->markReady();
        }

        $this->info(DB::table(BrowseSchema::USAGES)->count() . ' reference(s) in the index.');

        foreach ($indexer->unbuildable() as $type => $reason) {
            $this->warn("Block [{$type}] has no buildable schema; its references are guessed: {$reason}");
        }

        return self::SUCCESS;
    }

    private function run_(MediaUsageIndexer $indexer, array $classes, ?string $since): void
    {
        foreach ($classes as $class) {
            $after = 0;
            $chunks = 0;

            do {
                $after = $indexer->reindexChunk($class, $after, 200, $since);
                $chunks++;
            } while ($after > 0);

            $removed = $since === null ? $indexer->pruneOrphans($class) : 0;

            $this->line(sprintf('%s: done (%d chunk(s), %d stale row(s) removed)', $class === MediaUsageRegistry::SETTING_MODEL ? 'settings' : $class, $chunks, $removed));
        }
    }

    /**
     * Voor een klantproject, vóór de uitrol: welke eigen formulieren een
     * mediaveld hebben. Best effort: het toont de bestanden en de veldnamen,
     * en welke modellen aangemeld zijn; de koppeling legt een mens.
     */
    private function audit(MediaUsageIndexer $indexer): int
    {
        $found = [];

        foreach (array_filter([app_path(), base_path('app-modules')], 'is_dir') as $directory) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());

                if (! preg_match_all('/(?:mediaHelper\(\)\s*->\s*field|MediaField::make)\(\s*([\'"])([^\'"]*)\1/', $source, $matches)) {
                    if (! preg_match('/mediaHelper\(\)\s*->\s*field\(|MediaField::make\(/', $source)) {
                        continue;
                    }

                    $matches = [2 => ['(name is not a literal)']];
                }

                $found[ltrim(str_replace(base_path(), '', $file->getPathname()), '/')] = array_values(array_unique($matches[2]));
            }
        }

        ksort($found);

        $this->line('Media fields found in app/' . (is_dir(base_path('app-modules')) ? ' and app-modules/' : '') . ': ' . count($found) . ' file(s).');

        foreach ($found as $file => $names) {
            $this->line('  ' . $file . ': ' . implode(', ', $names));
        }

        $this->newLine();
        $this->line('Registered models (a field in a block of the page builder is found through the content column of its model):');

        foreach (MediaUsageRegistry::models() as $model) {
            $columns = MediaUsageRegistry::for($model);
            $this->line('  ' . (class_exists($model) ? '' : '(not installed) ') . $model . ': ' . implode(', ', array_map(fn ($column, $kind) => $column . ' (' . $kind . ')', array_keys($columns), $columns)));
        }

        $this->line('Registered settings: ' . implode(', ', MediaUsageRegistry::settings()));

        $unbuildable = app(\Dashed\DashedFiles\Classes\Usage\MediaFieldMap::class)->unbuildable();

        if ($unbuildable !== []) {
            $this->newLine();
            $this->warn('Blocks whose schema cannot be built without a record (their references are guessed, never rewritten):');

            foreach ($unbuildable as $type => $reason) {
                $this->line('  ' . $type . ': ' . $reason);
            }
        }

        $this->newLine();
        $this->line('Every file above that saves into a model or setting of its own must be registered, in AppServiceProvider::boot():');
        $this->line('  \\Dashed\\DashedFiles\\Classes\\Usage\\MediaUsageRegistry::extend(function () {');
        $this->line('      MediaUsageRegistry::attribute(\\App\\Models\\Brand::class, \'logo\');              // one id');
        $this->line('      MediaUsageRegistry::attribute(\\App\\Models\\Brand::class, \'gallery\', \'ids\');     // a list of ids');
        $this->line('      MediaUsageRegistry::attribute(\\App\\Models\\Brand::class, \'content\', \'blocks\');  // a builder column');
        $this->line('      MediaUsageRegistry::attribute(\\App\\Models\\Brand::class, \'story\', \'richtext\');  // rich text');
        $this->line('      MediaUsageRegistry::setting(\'brand_fallback_image\');                             // a Customsetting key');
        $this->line('  });');
        $this->line('Then run: php artisan dashed:media-usages --fresh');
        $this->line('Until every field is registered a file can look unused while it is not. Nothing is removed automatically unless dashed-files.trash.auto_purge is on.');

        return self::SUCCESS;
    }
}
