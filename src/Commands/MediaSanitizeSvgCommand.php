<?php

namespace Dashed\DashedFiles\Commands;

use Illuminate\Console\Command;
use Dashed\DashedFiles\Classes\Svg\SvgBacklog;

/**
 * Schoont bestaande SVG's op die van voor de opschoner zijn (of van een
 * oudere versie ervan). Wat de opschoner weigert blijft staan en komt in
 * een lijst, zodat een beheerder het bestand kan vervangen.
 */
class MediaSanitizeSvgCommand extends Command
{
    public $signature = 'dashed:media-sanitize-svg {--dry-run : Only report, change nothing}';

    public $description = 'Sanitize existing svg files in the media library and add the svg header rule to a local public disk';

    public function handle(SvgBacklog $backlog): int
    {
        $write = ! $this->option('dry-run');
        $counts = ['clean' => 0, 'sanitized' => 0, 'refused' => 0, 'unreadable' => 0];
        $refused = [];

        $backlog->query()->chunkById(100, function ($rows) use ($backlog, $write, &$counts, &$refused) {
            foreach ($rows as $media) {
                $status = $backlog->sanitize($media, $write);
                $counts[$status]++;

                if ($status === 'refused' || $status === 'unreadable') {
                    $refused[] = [(int) $media->model_id, (int) $media->getKey(), (string) $media->collection_name, (string) $media->file_name, $status];
                }
            }
        });

        $this->line(sprintf(
            '%s: %d already clean, %d %s, %d refused, %d unreadable.',
            $write ? 'Done' : 'Dry run',
            $counts['clean'],
            $counts['sanitized'],
            $write ? 'sanitized' : 'would be sanitized',
            $counts['refused'],
            $counts['unreadable'],
        ));

        if ($refused !== []) {
            $this->warn('Not sanitized (the files are left in place; replace them in the media library):');
            $this->table(['item', 'media', 'collection', 'file', 'reason'], $refused);
        }

        // De header voor een direct geopende svg, alleen op een lokale publieke schijf.
        $class = 'Dashed\\DashedCore\\Classes\\UploadSecurity';

        if (SvgBacklog::diskIsLocalPublic() && class_exists($class) && method_exists($class, 'ensureSvgHeaders')) {
            if ($write) {
                foreach ($class::ensureSvgHeaders() as $directory => $status) {
                    $this->line(sprintf('.htaccess in %s: %s', $directory, $status));
                }

                if (($root = SvgBacklog::diskRoot()) !== null && ! SvgBacklog::headerPresent()) {
                    foreach ($class::ensureSvgHeaders($root) as $directory => $status) {
                        $this->line(sprintf('.htaccess in %s: %s', $directory, $status));
                    }
                }
            } else {
                $this->line('.htaccess svg rule: ' . (SvgBacklog::headerPresent() ? 'present' : 'missing (would be added)'));
            }

            $this->line('Nginx does not read .htaccess. Add this to the vhost instead:');
            $this->line('    location ~* \\.svgz?$ { add_header Content-Security-Policy "script-src \'none\'; sandbox" always; }');
        }

        return self::SUCCESS;
    }
}
