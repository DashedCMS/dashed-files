<?php

namespace Dashed\DashedFiles\Media\Components\BrowseLibrary;

use RalphJSmit\Filament\MediaLibrary\Media\Components\BrowseLibrary;

/**
 * @mixin BrowseLibrary
 */
trait HasPagination
{
    public int $tableRecordsPerPage = 20;

    public function updatedTableRecordsPerPage(int $tableRecordsPerPage): void
    {
        $totalCount = $this->getBaseMediaLibraryFoldersQuery()->count() + $this->getBaseMediaLibraryItemsQuery()->count();

        // For example, say that someone has 20 records per page and is viewing the last page.
        // If that person updates to 40 records per page, the nr of pages will halve. Thus,
        // if someone changes the nr of records per page and the current page is no longer
        // valid, let's reset the page to 1, so that the user will not get an empty page.
        if (ceil($totalCount / $tableRecordsPerPage) < $this->getPage()) {
            $this->resetPage();
        }
    }

    public function getTableRecordsPerPageSelectOptions(): array
    {
        return [20, 40, 60, 80, 100];
    }
}
