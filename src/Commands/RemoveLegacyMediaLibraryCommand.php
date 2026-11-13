<?php

namespace Dashed\DashedFiles\Commands;

use RuntimeException;
use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;
use Dashed\DashedFiles\Classes\Legacy\LegacyMediaCodeRewriter;

/**
 * Fase 5 van de eigen mediabibliotheek: klantcode losmaken van het oude
 * pakket. `--code` zet de modellen om naar MediaItem en MediaFolder en meldt
 * wat met de hand moet. Standaard alleen een rapport; schrijven pas met
 * `--write`. Exitcode 1 zodra er handwerk is, zodat een script kan stoppen.
 */
class RemoveLegacyMediaLibraryCommand extends Command
{
    public const DIRECTORIES = ['app', 'database', 'routes', 'config', 'resources/views', 'tests'];

    public const SKIPPED_DIRECTORIES = ['vendor', 'node_modules', 'storage'];

    // Exitcode bij verkeerd gebruik, naast SUCCESS (0) en FAILURE (1, handwerk).
    public const USAGE = 2;

    protected $signature = 'dashed:remove-legacy-media-library
        {--code : Rewrite the models of the old media library to MediaItem and MediaFolder}
        {--composer-css : Remove the composer and CSS lines of the old package (release B, not available yet)}
        {--path= : Project root (defaults to the root of this application)}
        {--dry-run : Only report what would change (the default)}
        {--write : Write the changes}';

    protected $description = 'Move project code off the old media library package: --code rewrites the models and reports what needs manual work';

    public function handle(LegacyMediaCodeRewriter $rewriter): int
    {
        if ($this->option('composer-css')) {
            $this->error('--composer-css is not available yet: it removes the composer and CSS lines and ships with release B of the media library. Use --code now.');

            return self::USAGE;
        }

        if (! $this->option('code')) {
            $this->error('Nothing to do: pass --code.');

            return self::USAGE;
        }

        $root = rtrim((string) ($this->option('path') ?: base_path()), '/');

        if (! is_dir($root)) {
            $this->error("No such directory: {$root}");

            return self::USAGE;
        }

        $write = (bool) $this->option('write') && ! $this->option('dry-run');

        if ($this->option('write') && $this->option('dry-run')) {
            $this->warn('Both --write and --dry-run given: running as a dry run.');
        }

        // Sinds LegacyClassAliases is dit opruimen, geen voorwaarde: de oude
        // namen wijzen vanzelf naar MediaItem en MediaFolder zodra het pakket weg is.
        $this->line('Note: this is optional tidying. Project code that names the old models works without rewriting:');
        $this->line('once the old package is gone, dashed-files aliases the old class names to MediaItem and MediaFolder (dashed-files.legacy_aliases).');
        $this->line(sprintf('Scanning %s (%s)', $root, $write ? 'write' : 'dry run'));

        $files = 0;
        $replacements = 0;
        $handworkCount = 0;

        foreach ($this->files($root) as $path) {
            $relative = substr($path, strlen($root) + 1);
            $original = file_get_contents($path);

            if ($original === false || stripos($original, LegacyMediaCodeRewriter::VENDOR) === false) {
                continue;
            }

            $result = $rewriter->rewrite($path, $original);
            $changed = $result['code'] !== $original;

            if (! $changed && $result['handwork'] === []) {
                continue;
            }

            $isMigration = str_starts_with($relative, 'database/migrations/');
            $this->newLine();
            $this->line($relative . ($isMigration ? '  (migration: safe to convert, it only runs again on migrate:fresh)' : ''));

            foreach ($this->byLine($result['changes']) as $line => $texts) {
                $this->line("  - {$relative}:{$line} " . implode('; ', $texts));
            }

            foreach ($result['handwork'] as $item) {
                $this->line("  - HANDWORK {$relative}:{$item['line']} {$item['text']}");
            }

            if ($changed) {
                $files++;
                $replacements += count($result['changes']);

                if ($write) {
                    $this->writeAtomically($path, $result['code']);
                }
            }

            $handworkCount += count($result['handwork']);
        }

        $this->newLine();
        $this->line(sprintf('%d files %s, %d replacements, %d handwork items.', $files, $write ? 'changed' : 'to change', $replacements, $handworkCount));

        if (! $write && $files > 0) {
            $this->line('Dry run: nothing written. Run again with --write to apply.');
        } elseif (! $write) {
            $this->line('Dry run: nothing written.');
        }

        return $handworkCount > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<int, string> */
    private function files(string $root): array
    {
        $directories = array_values(array_filter(array_map(fn (string $dir) => $root . '/' . $dir, self::DIRECTORIES), 'is_dir'));

        if ($directories === []) {
            return [];
        }

        $paths = [];
        $finder = Finder::create()->files()->in($directories)->name('*.php')->exclude(self::SKIPPED_DIRECTORIES)->ignoreVCS(true);

        foreach ($finder as $file) {
            $path = $file->getPathname();

            // Ook diep geneste vendor-, node_modules- en storage-mappen overslaan.
            if (preg_match('#/(' . implode('|', self::SKIPPED_DIRECTORIES) . ')/#', substr($path, strlen($root)))) {
                continue;
            }

            $paths[] = $path;
        }

        sort($paths);

        return array_values(array_unique($paths));
    }

    /**
     * @param  array<int, array{line: int, text: string}>  $items
     * @return array<int, array<int, string>>
     */
    private function byLine(array $items): array
    {
        $lines = [];

        foreach ($items as $item) {
            $lines[$item['line']][] = $item['text'];
        }

        ksort($lines);

        return array_map(fn (array $texts) => array_values(array_unique($texts)), $lines);
    }

    /**
     * Tijdelijk bestand naast het origineel en dan een rename: een
     * afgebroken run laat nooit een half bestand achter. Bestandsrechten
     * blijven gelijk; regeleinden ook, want de herschrijver vervangt alleen
     * namen.
     */
    private function writeAtomically(string $path, string $code): void
    {
        $permissions = fileperms($path) & 07777;
        $temporary = dirname($path) . '/.' . basename($path) . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (file_put_contents($temporary, $code, LOCK_EX) !== strlen($code)) {
            @unlink($temporary);

            throw new RuntimeException("Could not write {$temporary}");
        }

        chmod($temporary, $permissions);

        if (! rename($temporary, $path)) {
            @unlink($temporary);

            throw new RuntimeException("Could not replace {$path}");
        }
    }
}
