<?php

namespace Dashed\DashedFiles\Commands;

use InvalidArgumentException;
use Illuminate\Console\Command;
use Dashed\DashedFiles\Classes\MediaTexts;
use Dashed\DashedFiles\Classes\MediaLibrary;

class MediaTextsCommand extends Command
{
    protected $signature = 'dashed:media-texts
        {--sync : Make the translations follow the text columns where they differ}
        {--mirror= : Switch the mirror locale: rewrite the text columns from the translations for this locale, then store it}';

    protected $description = 'Count (and with --sync repair) media items whose alt text, caption or title column differs from the mirror locale in the translations; --mirror=<locale> switches the mirror locale';

    public function handle(MediaLibrary $library): int
    {
        if (! MediaTexts::hasColumns()) {
            $this->error('The translation columns are missing. Run the migrations first.');

            return self::FAILURE;
        }

        $mirror = MediaTexts::firstLocale();

        if (is_string($target = $this->option('mirror')) && $target !== '') {
            try {
                $changed = $library->switchTextMirror($target);
            } catch (InvalidArgumentException $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }

            $this->info(sprintf('Mirror locale switched from %s to %s: the text columns of %d items were rewritten from the translations.', $mirror, $target, $changed));

            return self::SUCCESS;
        }

        $write = (bool) $this->option('sync');

        try {
            $drifted = $library->syncTexts($write);
        } catch (InvalidArgumentException $exception) {
            // De vastgelegde spiegeltaal is geen actieve taal meer.
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($drifted === 0) {
            $this->info('Every item is in sync: the text columns match the mirror locale (' . $mirror . ').');

            return self::SUCCESS;
        }

        $write
            ? $this->info(sprintf('Repaired %d items: the translations follow the text columns again.', $drifted))
            : $this->warn(sprintf('%d items differ. Run with --sync to make the translations follow the text columns.', $drifted));

        return self::SUCCESS;
    }
}
