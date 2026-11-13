<?php

namespace Dashed\DashedFiles\Commands;

use Illuminate\Console\Command;
use Dashed\DashedFiles\Models\MediaItem;
use Dashed\DashedFiles\Classes\Browse\BrowseSchema;
use Dashed\DashedFiles\Jobs\RelocateMediaItems;
use Dashed\DashedFiles\Classes\Access\MediaStorage;
use Dashed\DashedFiles\Classes\Access\MediaRelocator;
use Dashed\DashedFiles\Classes\Access\MediaVisibility;

class MediaPrivateCommand extends Command
{
    protected $signature = 'dashed:media-private
        {action=status : status (what is private and what still has to move) or relocate (move the files that are waiting)}
        {--if-needed : Do nothing and stay silent when nothing is waiting}
        {--now : relocate: do the work in this process instead of on the queue}
        {--force : relocate: also retry items that failed less than fifteen minutes ago or were given up}';

    protected $description = 'Show the state of private media, or move files that still have to go to or from the private disk';

    public function handle(MediaVisibility $visibility, MediaRelocator $relocator): int
    {
        if (! BrowseSchema::hasAccess()) {
            $this->error('The private media columns are missing. Run the migrations first.');

            return self::FAILURE;
        }

        if ($this->argument('action') === 'relocate') {
            // Het vangnet: een open item in een privé-map gaat alsnog dicht en op pad.
            $closed = (int) rescue(fn () => $visibility->closeOpenItemsInPrivateFolders(), 0);

            if ($closed > 0) {
                $this->warn("Closed {$closed} open items that were in a private folder.");
            }

            if ($this->option('force')) {
                $visibility->retryGivenUp();
            }

            $pending = $visibility->pendingCount() - count($visibility->givenUpIds(PHP_INT_MAX));

            if ($this->option('if-needed') && $pending <= 0) {
                return self::SUCCESS;
            }

            return $this->relocate($relocator, max(0, $pending));
        }

        $pending = $visibility->pendingCount();

        if ($this->option('if-needed') && $pending === 0) {
            return self::SUCCESS;
        }

        $disk = MediaStorage::inspect();

        $this->line('Private disk: ' . $disk['disk'] . ' (' . ($disk['configured'] ? $disk['driver'] . ', ' . $disk['visibility'] : 'not configured') . ')');
        $this->line('Private folders: ' . count($visibility->privateFolderIds()));
        $this->line('Private items: ' . MediaItem::withoutGlobalScopes()->toBase()->where('is_private', true)->count());
        $this->line('Waiting to move: ' . $pending);
        $this->line('Given up (stay closed until retried with --force): ' . (($gaveUp = $visibility->givenUpIds()) === [] ? '0' : implode(', ', $gaveUp)));
        $this->line('Open items in a private folder: ' . count($visibility->openItemsInPrivateFolders()));

        return self::SUCCESS;
    }

    private function relocate(MediaRelocator $relocator, int $pending): int
    {
        if (! $this->option('now')) {
            RelocateMediaItems::dispatch(0, (bool) $this->option('force'));
            $this->info("Queued: {$pending} items are waiting to move.");

            return self::SUCCESS;
        }

        $done = 0;
        $failed = 0;

        // Opgegeven items doen niet mee; --force heeft ze hierboven al een nieuwe kans gegeven.
        MediaItem::withoutGlobalScopes()->toBase()->whereNotNull('relocate_to')
            ->when(BrowseSchema::hasRelocateProgress(), fn ($query) => $query->whereNull('relocate_gave_up_at'))
            ->select('id')->orderBy('id')
            ->chunkById(100, function ($rows) use ($relocator, &$done, &$failed) {
                foreach ($rows as $row) {
                    $outcome = $relocator->attempt((int) $row->id);
                    $outcome === MediaRelocator::DONE ? $done++ : $failed++;

                    if ($outcome !== MediaRelocator::DONE) {
                        $this->error("Item #{$row->id}: " . $outcome);
                    }
                }
            });

        $this->info("Moved: {$done}. Failed or busy: {$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
