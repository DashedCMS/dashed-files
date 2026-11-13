<?php

namespace Dashed\DashedFiles\Commands;

use Illuminate\Console\Command;
use Dashed\DashedFiles\Jobs\HashMediaItems;
use Dashed\DashedFiles\Classes\Ingest\MediaChecksums;

class MediaChecksumsCommand extends Command
{
    protected $signature = 'dashed:media-checksums
        {--all : Hash every file, not only the ones that share their size with another file}
        {--force : Do not ask for confirmation with --all}
        {--now : Hash in this process instead of on the queue, also files above the size limit of the queue job}';

    protected $description = 'Fill size and checksum for media library items, so duplicates can be detected';

    public function handle(MediaChecksums $checksums): int
    {
        if (! $checksums->available()) {
            $this->error('The checksum columns are missing. Run the migrations first.');

            return self::FAILURE;
        }

        if (! $this->option('all')) {
            if ($this->option('now')) {
                return $this->hashNow($checksums, false);
            }

            HashMediaItems::dispatch();

            $this->info('Queued: sizes for every item, then a checksum for files that share their size with another file.');

            return self::SUCCESS;
        }

        // Alles hashen leest elk bestand één keer helemaal van de schijf: op
        // een objectopslag is dat verkeer dat iemand betaalt.
        $totals = $checksums->unhashedTotals(all: true);
        $unsized = $checksums->withoutSize();

        $this->line(sprintf('Files without a checksum: %d (%s to read).', $totals['files'], $this->readable($totals['bytes'])));

        if ($unsized > 0) {
            $this->line(sprintf('Another %d items have no size yet; they are sized first and then hashed as well.', $unsized));
        }

        if (! $this->option('force') && ! $this->confirm('Hash all of these files?')) {
            $this->line('Nothing queued.');

            return self::FAILURE;
        }

        if ($this->option('now')) {
            return $this->hashNow($checksums, true);
        }

        HashMediaItems::dispatch(true);

        $this->info('Queued: sizes for every item, then a checksum for every file.');

        return self::SUCCESS;
    }

    /**
     * Alles in dit proces, zonder tijdslimiet en zonder de grens van de
     * wachtrijtaak: de uitweg voor bestanden die te groot zijn om in de
     * wachtrij binnen de standaard retry_after van 90 seconden te hashen.
     */
    private function hashNow(MediaChecksums $checksums, bool $all): int
    {
        $max = $checksums->maxId();

        for ($from = 1; $from <= $max; $from += 1000) {
            $checksums->fillSizes($from, $from + 999);
        }

        $hashed = 0;
        $after = 0;

        while (($sizes = $checksums->unhashedSizes($after, 100, $all)) !== []) {
            foreach (array_keys($sizes) as $id) {
                if (rescue(fn () => $checksums->hashItem($id)) !== null) {
                    $hashed++;
                }

                $after = $id;
            }
        }

        $this->info("Hashed: {$hashed} files.");

        return self::SUCCESS;
    }

    private function readable(int $bytes): string
    {
        return $bytes >= 1024 ** 3
            ? round($bytes / 1024 ** 3, 1) . ' GB'
            : round($bytes / 1024 ** 2, 1) . ' MB';
    }
}
