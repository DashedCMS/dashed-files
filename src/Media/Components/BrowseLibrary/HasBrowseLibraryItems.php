<?php

namespace Dashed\DashedFiles\Media\Components\BrowseLibrary;

use Dashed\DashedFiles\DashedFilesPlugin;
use Dashed\DashedFiles\Media\DataTransferObjects\BrowseLibraryItem;
use Dashed\DashedFiles\Models\MediaFile;
use Dashed\DashedFiles\Models\MediaFolder;
use Filament\Forms\ComponentContainer;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Expression;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @mixin BrowseLibrary
 */
trait HasBrowseLibraryItems
{
    public bool $defer = false;

    public function mountHasBrowseLibraryItems(): void
    {
        $this->searchForm->fill();
        $this->sortOrderForm->fill();
    }

    public function loadMedia(mixed $folder = null, mixed $defaultFolder = null): void
    {
        $this->defer = false;

        if ($folder) {
            $this->openMediaLibraryFolder($folder);
            $this->lockMediaLibraryFolder($folder);
        } elseif ($defaultFolder) {
            $this->openMediaLibraryFolder($defaultFolder);
            $this->lockMediaLibraryFolder(null);
        } else {
            $this->openMediaLibraryFolder(null);
            $this->lockMediaLibraryFolder(null);
        }
    }

    protected function getSearchForm(): ComponentContainer
    {
        return $this
            ->makeForm()
            ->schema([
                TextInput::make('search')
                    ->disableLabel()
                    ->rules(['string', 'max:255'])
                    ->placeholder(Str::ucfirst(__('filament-media-library::translations.sentences.enter-search-term')))
                    ->debounce(),
            ]);
    }

    protected function getSortOrderForm(): ComponentContainer
    {
        return $this
            ->makeForm()
            ->schema([
                Select::make('sort_order')
                    ->disableLabel()
                    ->placeholder(Str::ucfirst(__('filament-media-library::translations.phrases.sort-by')))
                    ->reactive()
                    ->options([
                        'created_at_ascending' => __('filament-media-library::translations.components.browse-library.sort_order.created_at_ascending'),
                        'created_at_descending' => __('filament-media-library::translations.components.browse-library.sort_order.created_at_descending'),
                        'name_ascending' => __('filament-media-library::translations.components.browse-library.sort_order.name_ascending'),
                        'name_descending' => __('filament-media-library::translations.components.browse-library.sort_order.name_descending'),
                    ]),
            ]);
    }

    public function searchForm(): void
    {
        //
    }

    public function getBrowseLibraryItems(): Paginator
    {
        if ($this->defer) {
            return new Paginator([], $this->tableRecordsPerPage, $this->getPage());
        }

        $sortOrder = $this->sortOrderForm->getStateOnly(['sort_order'])['sort_order'] ?? null;

        if ($sortOrder) {
            return $this->getSortedBrowseLibraryItems($sortOrder);
        }

        $filamentMediaLibraryFolders = $this
            ->getBaseMediaLibraryFoldersQuery()
            ->orderBy('name')
            // Always pass one item too much to the paginator, otherwise the paginator will not know that there are more items, and thus more pages.
            ->take($this->tableRecordsPerPage + 1)
            ->skip(($this->getPage() - 1) * $this->tableRecordsPerPage)
            ->withCount(['files', 'children'])
            ->with(['files' => ['media']])
            ->get();

        if ($filamentMediaLibraryFolders->isEmpty()) {
            // If there are no media library folders, we don't know for sure if we already displayed
            // actual media library items on previous pages. Therefore, we need to count the total
            // number of items displayed until now, subtract the total number of media library
            // folders and the difference is the number of media library items that we did
            // already display. We use that number to skip the already displayed items.
            $filamentMediaLibraryFoldersCount = $this
                ->getBaseMediaLibraryFoldersQuery()
                ->count();

            $totalItemsDisplayedUntilNow = ($this->getPage() - 1) * $this->tableRecordsPerPage;
            $totalMediaLibraryItemsDisplayedUntilNow = $totalItemsDisplayedUntilNow - $filamentMediaLibraryFoldersCount;

            $filamentMediaLibraryItems = $this
                ->getBaseMediaLibraryItemsQuery()
                ->latest()
                ->skip($totalMediaLibraryItemsDisplayedUntilNow)
                ->take($this->tableRecordsPerPage + 1)
                ->get();
        } elseif ($filamentMediaLibraryFolders->count() <= $this->tableRecordsPerPage) {
            // By default, we will display the media library folders first. If there still
            // are media library folders returned, we can just fill up the remaining part
            // of the page with media library items, without having to skip items here.
            $filamentMediaLibraryItems = $this
                ->getBaseMediaLibraryItemsQuery()
                ->latest()
                ->take($this->tableRecordsPerPage - $filamentMediaLibraryFolders->count() + 1)
                ->get();
        } else {
            // If the total number of media library folders is greater than or equal to the number
            // of items we want to display per page, we don't need to fetch media library items.
            $filamentMediaLibraryItems = new Collection();
        }

        $browseLibraryItems = collect()
            ->merge($filamentMediaLibraryFolders)
            ->merge($filamentMediaLibraryItems)
            ->map(function (MediaFolder | MediaFile $mediaLibraryItem): BrowseLibraryItem {
                return new BrowseLibraryItem($mediaLibraryItem);
            });

        return new Paginator(
            items: $browseLibraryItems,
            perPage: $this->tableRecordsPerPage,
            currentPage: $this->getPage(),
        );
    }

    protected function getSortedBrowseLibraryItems(string $sortOrder): Paginator
    {
        /** @var Media $media */
        $media = new (config('media-library.media_model'));

        /** @var MediaFile $mediaLibraryItem */
        $mediaLibraryItem = new (DashedFilesPlugin::get()->getModelItem());

        /** @var MediaFolder $mediaLibraryFolder */
        $mediaLibraryFolder = new (DashedFilesPlugin::get()->getModelFolder());

        $mediaLibraryFolderSearchableQuery = $this
            ->getBaseMediaLibraryFoldersQuery()
            ->select([
                $mediaLibraryFolder->getQualifiedKeyName(),
                new Expression("'filament_media_library_folder' AS type"),
                $mediaLibraryFolder->qualifyColumn('name'),
                $mediaLibraryFolder->qualifyColumn($mediaLibraryFolder->getCreatedAtColumn()) . ' AS created_at',
            ])
            ->toBase();

        $mediaLibraryItemSearchableQuery = $this
            ->getBaseMediaLibraryItemsQuery()
            ->join($media->getTable(), $mediaLibraryItem->getQualifiedKeyName(), '=', $media->qualifyColumn('model_id'))
            ->where($media->qualifyColumn('model_type'), $mediaLibraryItem->getMorphClass())
            ->select([
                $mediaLibraryItem->getQualifiedKeyName(),
                new Expression("'filament_media_library_item' AS type"),
                $media->qualifyColumn('name'),
                $mediaLibraryItem->qualifyColumn($mediaLibraryItem->getCreatedAtColumn()) . ' AS created_at',
            ])
            ->toBase();

        $searchableQuery = $mediaLibraryFolderSearchableQuery->union($mediaLibraryItemSearchableQuery);

        $this->applyBrowseLibraryItemsOrderBy($searchableQuery, $sortOrder);

        $searchResults = $searchableQuery
            // Always pass one item too much to the paginator, otherwise the paginator will not know that there are more items, and thus more pages.
            ->take($this->tableRecordsPerPage + 1)
            ->skip(($this->getPage() - 1) * $this->tableRecordsPerPage)
            ->get();

        /** @var Collection<MediaFolder, array-key> $filamentMediaLibraryFolders */
        $filamentMediaLibraryFolders = DashedFilesPlugin::get()
            ->getModelFolder()::query()
            ->whereIn($mediaLibraryFolder->getKeyName(), $searchResults->where('type', 'filament_media_library_folder')->pluck('id'))
            ->withCount(['mediaLibraryItems', 'children'])
            ->with(['mediaLibraryItems' => ['media']])
            ->get();

        /** @var Collection<MediaFile, array-key> $filamentMediaLibraryItems */
        $filamentMediaLibraryItems = DashedFilesPlugin::get()
            ->getModelItem()::query()
            ->whereIn($mediaLibraryItem->getKeyName(), $searchResults->where('type', 'filament_media_library_item')->pluck('id'))
            ->with(['media'])
            ->get();

        $browseLibraryItems = collect()
            ->merge($filamentMediaLibraryFolders)
            ->merge($filamentMediaLibraryItems)
            ->map(function (MediaFolder | MediaFile $mediaLibraryItem): BrowseLibraryItem {
                return new BrowseLibraryItem($mediaLibraryItem);
            });

        $this->applyBrowseLibraryItemsSortBy($browseLibraryItems, $sortOrder);

        return new Paginator(
            items: $browseLibraryItems,
            perPage: $this->tableRecordsPerPage,
            currentPage: $this->getPage(),
        );
    }

    protected function getBaseMediaLibraryItemsQuery(): Builder
    {
        $searchTerm = $this->searchForm->getStateOnly(['search'])['search'] ?? null;

        return DashedFilesPlugin::get()
            ->getModelItem()::query()
            ->with(['media', 'folder'])
            ->when(
                value: $this->mediaLibraryFolder,
                callback: fn (Builder $query): Builder => $query->where('folder_id', $this->mediaLibraryFolder->getKey()),
                default: fn (Builder $query): Builder => $query->whereNull('folder_id')
            )
            ->when($searchTerm, function (Builder $query) use ($searchTerm): Builder {
                return $query->where(function (Builder $query) use ($searchTerm): Builder {
                    return $query
                        ->whereHas('media', function ($query) use ($searchTerm) {
                            $searchTerm = Str::lower($searchTerm);

                            return $query
                                ->where(new Expression('LOWER(name)'), 'LIKE', "%{$searchTerm}%")
                                ->orWhere(new Expression('LOWER(file_name)'), 'LIKE', "%{$searchTerm}%");
                        })
                        ->orWhere(new Expression('LOWER(caption)'), 'LIKE', "%{$searchTerm}%")
                        ->orWhere(new Expression('LOWER(alt_text)'), 'LIKE', "%{$searchTerm}%");
                });
            });
    }

    protected function getBaseMediaLibraryFoldersQuery(): Builder
    {
        $searchTerm = $this->searchForm->getStateOnly(['search'])['search'] ?? null;

        return DashedFilesPlugin::get()
            ->getModelFolder()::query()
            ->when(
                value: $this->mediaLibraryFolder,
                callback: fn (Builder $query): Builder => $query->where('parent_id', $this->mediaLibraryFolder->getKey()),
                default: fn (Builder $query): Builder => $query->whereNull('parent_id')
            )
            ->when($searchTerm, function (Builder $query) use ($searchTerm): Builder {
                return $query->where(new Expression('LOWER(name)'), 'LIKE', "%{$searchTerm}%");
            });
    }

    public function applyBrowseLibraryItemsOrderBy(\Illuminate\Database\Query\Builder &$searchableQuery, string $sortOrder): void
    {
        match ($sortOrder) {
            'created_at_ascending' => $searchableQuery->orderBy('created_at'),
            'created_at_descending' => $searchableQuery->orderByDesc('created_at'),
            'name_ascending' => $searchableQuery->orderBy('name'),
            'name_descending' => $searchableQuery->orderByDesc('name'),
        };
    }

    public function applyBrowseLibraryItemsSortBy(\Illuminate\Support\Collection &$browseLibraryItems, string $sortOrder): void
    {
        $browseLibraryItems = match ($sortOrder) {
            'created_at_ascending' => $browseLibraryItems->sortBy(fn (BrowseLibraryItem $browseLibraryItem) => $browseLibraryItem->getCreatedAt()),
            'created_at_descending' => $browseLibraryItems->sortByDesc(fn (BrowseLibraryItem $browseLibraryItem) => $browseLibraryItem->getCreatedAt()),
            'name_ascending' => $browseLibraryItems->sortBy(fn (BrowseLibraryItem $browseLibraryItem) => $browseLibraryItem->getName()),
            'name_descending' => $browseLibraryItems->sortByDesc(fn (BrowseLibraryItem $browseLibraryItem) => $browseLibraryItem->getName()),
        };
    }
}
