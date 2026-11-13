{{--
    De mediabibliotheek: boom, werkbalk, raster en detailpaneel. Wordt door
    de beheerpagina ingevoegd en kan zo in een keuzevenster hergebruikt worden
    (mediaConfig.mode = pick). Verwacht dat het omringende Livewire-component
    de traits BrowsesMedia en ReceivesMediaUploads gebruikt.

    wire:ignore: de browser rendert de lijst zelf; een render van Livewire
    (na elke upload) mag daar niet doorheen morphen.
--}}
@php
    $mediaConfig ??= [];
    // Het keuzevenster krijgt zijn instellingen per opening apart (config.sources) en geeft hier de bronnen voor de markup mee.
    $mediaSources = $mediaMarkupSources ?? ($mediaConfig['sources'] ?? []);
    $mediaItemActions ??= [];
    $mediaBulkActions ??= [];
    $mediaToolbarActions ??= [];
    $icon = fn (?string $name, string $class = 'dashed-media-icon') => $name
        ? rescue(fn () => svg($name, $class, ['aria-hidden' => 'true'])->toHtml(), '', false)
        : '';
@endphp

{{-- Stijl en script zijn statische bestanden (MediaAssets); de render hook aan het eind van de body zet ze in de pagina. --}}
@php(\Dashed\DashedFiles\Classes\MediaAssets::markNeeded())

{{-- Zonder inspringing en lege regels: dit is de grootste view van het pakket (MediaHtml). --}}
@php(ob_start())

<div
    wire:ignore
    class="dashed-media"
    x-data="dashedMediaBrowser"
    x-id="['dm-title', 'dm-alt', 'dm-caption', 'dm-tags', 'dm-dialog']"
    data-dashed-media="{{ json_encode($mediaConfig) }}"
    x-bind:class="{ 'dashed-media--detail': detailOpen, 'dashed-media--tree': treeOpen, 'dashed-media--touch': touch, 'dashed-media--selecting': selectedCount > 0, 'dashed-media--pick': ! manage }"
    x-on:keydown="rootKey($event)"
    x-on:paste.window="paste($event)"
>
    <div class="dashed-media-sr" aria-live="polite" x-text="live"></div>
    <input type="file" multiple class="dashed-media-sr" tabindex="-1" aria-hidden="true" x-ref="file" x-on:change="picked($event)">
    <input type="file" class="dashed-media-sr" tabindex="-1" aria-hidden="true" x-ref="replaceFile" x-on:change="replacePicked($event)">

    {{-- Boom --}}
    <aside class="dashed-media-side" aria-label="{{ __('Mappen') }}">
        <div class="dashed-media-side-scroll" x-ref="tree" x-on:keydown="treeKey($event)">
            <ul class="dashed-media-tree" role="tree" aria-label="{{ __('Slimme mappen') }}">
                @foreach (['recent' => 'heroicon-o-clock', 'mine' => 'heroicon-o-user', 'favorites' => 'heroicon-o-star', 'missing_alt' => 'heroicon-o-exclamation-triangle'] as $smartKey => $smartIcon)
                    <li role="none">
                        <div class="dashed-media-node" role="treeitem" tabindex="0"
                             x-bind:aria-current="filters.folder === null && filters.smart === '{{ $smartKey }}' ? 'true' : null"
                             x-on:click="openSmart('{{ $smartKey }}')">
                            {!! $icon($smartIcon) !!}
                            <span class="dashed-media-node-name">{{ $mediaConfig['labels']['smart'][$smartKey] ?? $smartKey }}</span>
                        </div>
                    </li>
                @endforeach
                {{-- Alleen op de beheerpagina, en alleen als er iets te zien valt: zonder gevulde index zegt "ongebruikt" niets. --}}
                <template x-if="manage && tree.usageReady">
                    <li role="none">
                        <div class="dashed-media-node" role="treeitem" tabindex="0"
                             x-bind:aria-current="filters.folder === null && filters.smart === 'unused' ? 'true' : null"
                             x-on:click="openSmart('unused')">
                            {!! $icon('heroicon-o-archive-box') !!}
                            <span class="dashed-media-node-name">{{ $mediaConfig['labels']['smart']['unused'] ?? 'unused' }}</span>
                        </div>
                    </li>
                </template>
                <template x-if="manage && tree.hasDuplicates">
                    <li role="none">
                        <div class="dashed-media-node" role="treeitem" tabindex="0"
                             x-bind:aria-current="filters.folder === null && filters.smart === 'duplicates' ? 'true' : null"
                             x-on:click="openSmart('duplicates')">
                            {!! $icon('heroicon-o-document-duplicate') !!}
                            <span class="dashed-media-node-name">{{ $mediaConfig['labels']['smart']['duplicates'] ?? 'duplicates' }}</span>
                        </div>
                    </li>
                </template>
            </ul>

            <div class="dashed-media-side-head">
                <span>{{ __('Mappen') }}</span>
                <button type="button" class="dashed-media-btn dashed-media-btn--ghost" x-show="manage && canFolders" x-on:click="askNewFolder(null)" title="{{ __('Nieuwe map') }}" aria-label="{{ __('Nieuwe map') }}">
                    {!! $icon('heroicon-o-folder-plus') !!}
                </button>
            </div>

            <ul class="dashed-media-tree" role="tree" aria-label="{{ __('Mappen') }}">
                <li role="none">
                    <div class="dashed-media-node" role="treeitem" tabindex="0"
                         x-bind:aria-current="isCurrent(null) ? 'true' : null"
                         x-on:click="openFolder(null)">
                        {!! $icon('heroicon-o-squares-2x2') !!}
                        <span class="dashed-media-node-name">{{ __('Alle bestanden') }}</span>
                        <span class="dashed-media-node-count" x-text="tree.allCount"></span>
                    </div>
                </li>
                <li role="none">
                    <div class="dashed-media-node" role="treeitem" tabindex="0"
                         x-bind:aria-current="isCurrent('root') ? 'true' : null"
                         x-bind:class="{ 'dashed-media-node--drop': dropFolder === 'root' }"
                         x-on:click="openFolder('root')"
                         x-on:dragover="overFolder($event, 'root')"
                         x-on:dragleave="leaveFolder($event, 'root')"
                         x-on:drop="dropOnFolder($event, null)">
                        {!! $icon('heroicon-o-inbox') !!}
                        <span class="dashed-media-node-name">{{ __('Zonder map') }}</span>
                        <span class="dashed-media-node-count" x-text="tree.rootCount"></span>
                    </div>
                </li>
                <template x-for="row in treeRows" x-bind:key="row.id">
                    <li role="none">
                        <div class="dashed-media-node" role="treeitem" tabindex="0"
                             x-bind:aria-level="row.depth + 1"
                             x-bind:aria-expanded="row.hasChildren ? row.open : null"
                             x-bind:aria-current="isCurrent(row.id) ? 'true' : null"
                             x-bind:data-folder="row.id"
                             x-bind:data-name="row.name"
                             x-bind:style="'padding-left:' + (6 + row.depth * 16) + 'px'"
                             x-bind:class="{ 'dashed-media-node--drop': dropFolder === row.id }"
                             x-bind:draggable="manage && canFolders && renaming !== row.id ? 'true' : null"
                             x-on:click="openFolder(row.id)"
                             x-on:dragstart="dragFolder($event, row.id)"
                             x-on:dragover="overFolder($event, row.id)"
                             x-on:dragleave="leaveFolder($event, row.id)"
                             x-on:drop="dropOnFolder($event, row.id)">
                            <button type="button" class="dashed-media-twist" tabindex="-1"
                                    x-bind:class="{ 'dashed-media-twist--empty': ! row.hasChildren }"
                                    x-bind:aria-expanded="row.open"
                                    aria-label="{{ __('Submappen tonen of verbergen') }}"
                                    x-on:click.stop="toggleFolder(row.id)">
                                {!! $icon('heroicon-m-chevron-right') !!}
                            </button>
                            {!! $icon('heroicon-o-folder') !!}
                            <template x-if="renaming !== row.id">
                                <span class="dashed-media-node-name" x-text="row.name"></span>
                            </template>
                            {{-- Fase 10: een privé-map (eigen of geërfd), en bestanden die nog onderweg zijn naar of van de privé-schijf. --}}
                            <span class="dashed-media-lock" x-show="row.private" x-bind:title="L.privateFolder">{!! $icon('heroicon-s-lock-closed') !!}<span class="dashed-media-sr" x-text="L.privateFolder"></span></span>
                            <span class="dashed-media-moving" x-show="row.moving" x-text="L.moving" x-bind:title="movingTitle(row.moving)"></span>
                            <template x-if="renaming === row.id">
                                <input type="text" class="dashed-media-input" maxlength="255" aria-label="{{ __('Naam van de map') }}"
                                       x-bind:data-rename="row.id"
                                       x-model="renameValue"
                                       x-on:click.stop
                                       x-on:keydown.stop
                                       x-on:keydown.enter.prevent="commitRename()"
                                       x-on:keydown.escape.prevent="renaming = null"
                                       x-on:blur="commitRename()">
                            </template>
                            <span class="dashed-media-node-count" x-text="row.total"></span>
                            <button type="button" class="dashed-media-btn dashed-media-btn--ghost dashed-media-node-menu-btn" tabindex="-1"
                                    x-show="manage && (canFolders || canDelete)"
                                    aria-haspopup="menu"
                                    x-bind:aria-expanded="folderMenu === row.id"
                                    aria-label="{{ __('Acties voor deze map') }}"
                                    x-on:click.stop="folderMenu = folderMenu === row.id ? null : row.id">
                                {!! $icon('heroicon-m-ellipsis-horizontal') !!}
                            </button>
                            <template x-if="folderMenu === row.id">
                                <div class="dashed-media-menu" role="menu" x-on:click.stop x-on:click.outside="folderMenu = null" x-on:keydown.stop x-on:keydown.escape="closeFolderMenu(row.id)">
                                    <button type="button" role="menuitem" x-show="canFolders" x-on:click="askNewFolder(row.id)">{!! $icon('heroicon-o-folder-plus') !!} {{ __('Nieuwe submap') }}</button>
                                    <button type="button" role="menuitem" x-show="canFolders" x-on:click="startRename(row.id, row.name)">{!! $icon('heroicon-o-pencil') !!} {{ __('Hernoemen') }}</button>
                                    <button type="button" role="menuitem" x-show="canFolders" x-on:click="askMoveFolder(row.id)">{!! $icon('heroicon-o-arrow-right-circle') !!} {{ __('Verplaatsen naar...') }}</button>
                                    {{-- Fase 10: privé en, bij meer dan één site, de sites van de map. --}}
                                    <button type="button" role="menuitem" x-show="canFolders && hasAccess" x-on:click="askFolderSettings(row.id)">{!! $icon('heroicon-o-lock-closed') !!} <span x-text="siteOptions.length ? L.folderSettingsSites : L.folderSettings"></span></button>
                                    <button type="button" role="menuitem" x-show="canFolders && canDelete" x-on:click="askDeleteFolder(row.id)">{!! $icon('heroicon-o-trash') !!} {{ __('Verwijderen') }}</button>
                                </div>
                            </template>
                        </div>
                    </li>
                </template>
            </ul>

            <template x-if="manage">
                <ul class="dashed-media-tree dashed-media-tree--trash" role="tree" aria-label="{{ __('Prullenbak') }}">
                    <li role="none">
                        <div class="dashed-media-node" role="treeitem" tabindex="0"
                             x-bind:aria-current="filters.folder === null && filters.smart === 'trash' ? 'true' : null"
                             x-on:click="openSmart('trash')">
                            {!! $icon('heroicon-o-trash') !!}
                            <span class="dashed-media-node-name">{{ $mediaConfig['labels']['smart']['trash'] ?? 'trash' }}</span>
                            <span class="dashed-media-node-count" x-show="tree.trashCount" x-text="tree.trashCount"></span>
                        </div>
                    </li>
                </ul>
            </template>
        </div>
    </aside>

    {{-- Midden --}}
    <section class="dashed-media-main" aria-label="{{ __('Bestanden') }}">
        {{-- Tabbladen van het keuzevenster --}}
        <div class="dashed-media-picktabs" role="tablist" aria-label="{{ __('Bron') }}" x-show="! manage" x-cloak>
            <button type="button" role="tab" class="dashed-media-picktab" :aria-selected="pickTab === 'library'" x-on:click="setPickTab('library')">{!! $icon('heroicon-o-folder') !!} {{ __('Bibliotheek') }}</button>
            <button type="button" role="tab" class="dashed-media-picktab" :aria-selected="pickTab === 'recent'" x-on:click="setPickTab('recent')">{!! $icon('heroicon-o-clock') !!} {{ __('Recent') }}</button>
            <button type="button" role="tab" class="dashed-media-picktab" :aria-selected="pickTab === 'favorites'" x-on:click="setPickTab('favorites')">{!! $icon('heroicon-o-star') !!} {{ __('Favorieten') }}</button>
            <button type="button" role="tab" class="dashed-media-picktab" x-show="canUpload" :aria-selected="pickTab === 'upload'" x-on:click="setPickTab('upload')">{!! $icon('heroicon-o-arrow-up-tray') !!} {{ __('Uploaden') }}</button>
            {{-- Fase 11: per beschikbare externe bron een tabblad. Een bron die er niet is, of die dit veld niet toestaat, staat niet in de instelling (sources) en blijft verborgen. In het keuzevenster staat de markup voor alle bronnen, want ze wordt één keer opgehaald. --}}
            @foreach ($mediaSources as $mediaSource)
                <button type="button" role="tab" class="dashed-media-picktab" x-show="canUpload && sources.some((entry) => entry.key === @js($mediaSource['key']))" data-media-source-tab="{{ $mediaSource['key'] }}" :aria-selected="pickTab === 'source' && sourceKey === @js($mediaSource['key'])" x-on:click="openSource(@js($mediaSource['key']))">{!! $icon($mediaSource['icon'] ?? null) !!} {{ $mediaSource['label'] }}</button>
            @endforeach
        </div>

        <div class="dashed-media-toolbar" x-show="manage || (pickTab !== 'upload' && pickTab !== 'source')">
            <nav class="dashed-media-crumbs" aria-label="{{ __('Kruimelpad') }}">
                <button type="button" class="dashed-media-btn dashed-media-only-narrow" x-on:click="treeOpen = ! treeOpen" x-bind:aria-expanded="treeOpen">
                    {!! $icon('heroicon-o-folder') !!} {{ __('Mappen') }}
                </button>
                <button type="button" x-on:click="openFolder(null)" x-text="crumbs.length ? L.allFiles : locationLabel"></button>
                <template x-for="step in crumbs" x-bind:key="step.id">
                    <span style="display: contents">
                        <span aria-hidden="true">/</span>
                        <button type="button" x-on:click="openFolder(step.id)" x-text="step.name"></button>
                    </span>
                </template>
                <span class="dashed-media-node-count" x-show="total !== null" x-text="total"></span>
            </nav>

            <label class="dashed-media-search">
                <span class="dashed-media-sr">{{ __('Zoeken in bestanden') }}</span>
                {!! $icon('heroicon-o-magnifying-glass') !!}
                <input type="search" class="dashed-media-input" maxlength="100" placeholder="{{ __('Zoeken...') }}"
                       x-bind:value="filters.search"
                       x-on:input.debounce.300ms="setFilter('search', $event.target.value)">
            </label>

            {{-- Fase 10: het sitefilter, alleen bij meer dan één site. In het keuzevenster staat het standaard op de sites van het record; "Alle sites" zet het uit. --}}
            <template x-if="siteOptions.length">
                <label class="dashed-media-sitefilter" x-bind:class="{ 'dashed-media-sitefilter--on': siteChoice !== '' }">
                    <span class="dashed-media-sr">{{ __('Site') }}</span>
                    {!! $icon('heroicon-o-globe-alt') !!}
                    <select class="dashed-media-select" aria-label="{{ __('Site') }}" x-bind:value="siteChoice" x-on:change="setSite($event.target.value)">
                        <option value="">{{ __('Alle sites') }}</option>
                        <template x-if="recordSites.length > 1">
                            <option value="__record" x-bind:selected="siteChoice === '__record'">{{ __('Sites van dit record') }}</option>
                        </template>
                        <template x-for="site in siteOptions" x-bind:key="site.id">
                            <option x-bind:value="site.id" x-bind:selected="siteChoice === site.id" x-text="site.label"></option>
                        </template>
                    </select>
                    <span class="dashed-media-badge" x-show="siteChoice !== ''">{{ __('Sitefilter') }}</span>
                </label>
            </template>

            <select class="dashed-media-select" aria-label="{{ __('Type') }}" x-show="manage || ! pick.accept.length" x-bind:value="filters.type" x-on:change="setFilter('type', $event.target.value)">
                <option value="">{{ __('Alle typen') }}</option>
                <option value="image">{{ __('Afbeeldingen') }}</option>
                <option value="video">{{ __('Video\'s') }}</option>
                <option value="document">{{ __('Documenten') }}</option>
                <option value="svg">{{ __('SVG') }}</option>
            </select>

            <div class="dashed-media-pop" x-on:click.outside="tagMenu = false">
                <button type="button" class="dashed-media-btn" x-bind:class="{ 'dashed-media-btn--on': filters.tag.length }" aria-haspopup="true" x-bind:aria-expanded="tagMenu"
                        x-on:click="tagMenu = ! tagMenu; if (tagMenu) { loadTagOptions(); }">
                    {!! $icon('heroicon-o-tag') !!} {{ __('Tags') }}
                    <span class="dashed-media-badge" x-show="filters.tag.length" x-text="filters.tag.length"></span>
                </button>
                <div class="dashed-media-pop-panel" x-show="tagMenu" x-cloak>
                    <input type="search" class="dashed-media-input" placeholder="{{ __('Zoek een tag...') }}" aria-label="{{ __('Zoek een tag') }}"
                           x-model="tagSearch" x-on:input.debounce.300ms="loadTagOptions()">
                    <template x-for="tag in tagOptions" x-bind:key="tag.id">
                        <label>
                            <input type="checkbox" x-bind:checked="filters.tag.includes(tag.id)" x-on:change="toggleTagFilter(tag.id)">
                            <span class="dashed-media-node-name" x-text="tag.name"></span>
                            <span class="dashed-media-node-count" x-text="tag.count"></span>
                        </label>
                    </template>
                    <p class="dashed-media-row-meta" x-show="! tagOptions.length">{{ __('Geen tags gevonden.') }}</p>
                </div>
            </div>

            <button type="button" class="dashed-media-btn" x-bind:class="{ 'dashed-media-btn--on': moreOpen }" x-bind:aria-expanded="moreOpen" x-on:click="moreOpen = ! moreOpen">
                {!! $icon('heroicon-o-adjustments-horizontal') !!} {{ __('Meer filters') }}
            </button>

            <button type="button" class="dashed-media-btn" x-show="activeFilterCount > 0" x-cloak x-on:click="clearFilters()">
                <span class="dashed-media-badge" x-text="activeFilterCount"></span> {{ __('Filters wissen') }}
            </button>

            <span class="dashed-media-spacer"></span>

            <label class="dashed-media-check" x-show="typeof filters.folder === 'number'" x-cloak>
                <input type="checkbox" x-bind:checked="filters.include_subfolders" x-on:change="setFilter('include_subfolders', $event.target.checked)">
                {{ __('Inclusief submappen') }}
            </label>

            <select class="dashed-media-select" aria-label="{{ __('Sortering') }}" x-bind:value="filters.sort" x-on:change="setFilter('sort', $event.target.value)">
                <option value="newest">{{ __('Nieuwste eerst') }}</option>
                <option value="oldest">{{ __('Oudste eerst') }}</option>
                <option value="name">{{ __('Naam') }}</option>
                <option value="size">{{ __('Grootste bestand') }}</option>
                <option value="largest_dimensions">{{ __('Grootste afmetingen') }}</option>
            </select>

            <div role="group" aria-label="{{ __('Weergave') }}" style="display: inline-flex; gap: 2px">
                <button type="button" class="dashed-media-btn" x-bind:class="{ 'dashed-media-btn--on': view === 'grid' }" x-bind:aria-pressed="view === 'grid'" x-on:click="setView('grid')" title="{{ __('Raster') }}" aria-label="{{ __('Raster') }}">{!! $icon('heroicon-o-squares-2x2') !!}</button>
                <button type="button" class="dashed-media-btn" x-bind:class="{ 'dashed-media-btn--on': view === 'list' }" x-bind:aria-pressed="view === 'list'" x-on:click="setView('list')" title="{{ __('Lijst') }}" aria-label="{{ __('Lijst') }}">{!! $icon('heroicon-o-list-bullet') !!}</button>
            </div>

            @foreach ($mediaToolbarActions as $action)
                <button type="button" class="dashed-media-btn" x-show="manage && canEdit" x-on:click="runAction('toolbar', @js($action['key']))">
                    {!! $icon($action['icon']) !!} {{ $action['label'] }}
                </button>
            @endforeach

            {{-- Alleen als er iets ontbreekt en er geen run loopt; de server weet het uit loadConversionStatus(). --}}
            <button type="button" class="dashed-media-btn" x-show="manage && canEdit && missingFormats && ! run" x-cloak x-bind:disabled="busy" x-on:click="startRun('missing', [])">
                {!! $icon('heroicon-o-photo') !!} {{ __('Ontbrekende formaten genereren') }}
            </button>

            {{-- Fase 11: de externe bronnen, op de beheerpagina als knop. --}}
            @foreach ($mediaSources as $mediaSource)
                <button type="button" class="dashed-media-btn" x-show="manage && canUpload && ! inTrash" data-media-source-button="{{ $mediaSource['key'] }}" x-bind:aria-pressed="sourceKey === @js($mediaSource['key'])" x-on:click="openSource(@js($mediaSource['key']))">
                    {!! $icon($mediaSource['icon'] ?? null) !!} {{ $mediaSource['label'] }}
                </button>
            @endforeach

            <button type="button" class="dashed-media-btn dashed-media-btn--primary" x-show="manage && canUpload && ! inTrash" x-on:click="pickFiles()">
                {!! $icon('heroicon-o-arrow-up-tray') !!} {{ __('Uploaden') }}
            </button>
        </div>

        <div class="dashed-media-more" x-show="moreOpen" x-cloak>
            <label>
                <span class="dashed-media-label">{{ __('Bron') }}</span>
                <select class="dashed-media-select" style="width: 100%" x-bind:value="filters.source" x-on:change="setFilter('source', $event.target.value)">
                    <option value="">{{ __('Alle bronnen') }}</option>
                    <option value="upload">{{ __('Geüpload') }}</option>
                    <option value="url">{{ __('Van een URL') }}</option>
                    <option value="ai">{{ __('AI') }}</option>
                    <option value="api">{{ __('API') }}</option>
                    <option value="chat">{{ __('Chat') }}</option>
                    <option value="system">{{ __('Systeem') }}</option>
                </select>
            </label>
            <label>
                <span class="dashed-media-label">{{ __('Minimale breedte (px)') }}</span>
                <input type="number" min="0" step="1" class="dashed-media-input" x-bind:value="filters.min_width" x-on:change="setFilter('min_width', $event.target.value)">
            </label>
            <label>
                <span class="dashed-media-label">{{ __('Minimale hoogte (px)') }}</span>
                <input type="number" min="0" step="1" class="dashed-media-input" x-bind:value="filters.min_height" x-on:change="setFilter('min_height', $event.target.value)">
            </label>
            <label>
                <span class="dashed-media-label">{{ __('Geüpload vanaf') }}</span>
                <input type="date" class="dashed-media-input" x-bind:value="filters.date_from" x-on:change="setFilter('date_from', $event.target.value)">
            </label>
            <label>
                <span class="dashed-media-label">{{ __('Geüpload tot en met') }}</span>
                <input type="date" class="dashed-media-input" x-bind:value="filters.date_to" x-on:change="setFilter('date_to', $event.target.value)">
            </label>
            <label class="dashed-media-check" style="align-self: end; padding-bottom: 7px">
                <input type="checkbox" x-bind:checked="filters.smart === 'favorites'" x-on:change="setFilter('smart', $event.target.checked ? 'favorites' : null)">
                {{ __('Alleen favorieten') }}
            </label>
        </div>

        {{-- De pomp: één regel zolang er een run loopt, of als de laatste mislukt is. --}}
        <div class="dashed-media-run" x-show="manage && (run || runFailed)" x-cloak role="status" aria-live="polite">
            <template x-if="run">
                <span style="display: contents">
                    {!! $icon('heroicon-o-arrow-path') !!}
                    <strong x-text="runTitle(run)"></strong>
                    <progress x-bind:max="Math.max(1, run.total)" x-bind:value="Math.min(run.done, run.total)" x-bind:aria-label="runTitle(run)"></progress>
                    <span class="dashed-media-row-meta" x-text="runProgress(run)"></span>
                    <span class="dashed-media-spacer"></span>
                    <button type="button" class="dashed-media-btn" x-bind:disabled="busy" x-on:click="cancelRun()">{!! $icon('heroicon-o-x-mark') !!} {{ __('Annuleren') }}</button>
                </span>
            </template>
            <template x-if="! run && runFailed">
                <span style="display: contents">
                    {!! $icon('heroicon-o-exclamation-triangle') !!}
                    <strong x-text="L.runFailedTitle"></strong>
                    <span class="dashed-media-row-meta" x-text="runFailed.error || runProgress(runFailed)"></span>
                    <span class="dashed-media-spacer"></span>
                    <button type="button" class="dashed-media-btn" x-bind:disabled="busy" x-on:click="resumeRun()">{!! $icon('heroicon-o-play') !!} {{ __('Hervatten') }}</button>
                    <button type="button" class="dashed-media-btn dashed-media-btn--ghost" aria-label="{{ __('Melding sluiten') }}" x-on:click="runFailed = null">{!! $icon('heroicon-o-x-mark') !!}</button>
                </span>
            </template>
        </div>

        {{-- Fase 10: bestanden die nog van of naar de privé-schijf moeten. Tot ze er staan blijven ze dicht. --}}
        <div class="dashed-media-run" x-show="manage && tree.moving" x-cloak role="status" aria-live="polite">
            <span class="dashed-media-spin"></span>
            <span x-text="movingTitle(tree.moving)"></span>
            <button type="button" class="dashed-media-btn" x-show="canFolders" x-bind:disabled="busy" x-on:click="retryRelocation()">{{ __('Nu verder gaan') }}</button>
        </div>

        {{-- Bulkbalk. Verwijderen is naar de prullenbak; in de prullenbak zelf staan terugzetten en definitief verwijderen. --}}
        <div class="dashed-media-bulk" x-show="manage && selectedCount >= 2" x-cloak role="toolbar" aria-label="{{ __('Acties voor de selectie') }}">
            <strong x-text="L.selectedCount ? L.selectedCount.replace(':count', selectedCount) : selectedCount"></strong>
            <template x-if="manage && inTrash">
                <span style="display: contents">
                    <button type="button" class="dashed-media-btn" x-show="canDelete" x-on:click="restoreItems()">{!! $icon('heroicon-o-arrow-uturn-left') !!} {{ __('Terugzetten') }}</button>
                    {{-- Alleen met het recht purge_media; de server toetst het ook. --}}
                    <button type="button" class="dashed-media-btn dashed-media-btn--danger" x-show="canPurge" x-on:click="askPurge()">{!! $icon('heroicon-o-trash') !!} {{ __('Definitief verwijderen') }}</button>
                </span>
            </template>
            <template x-if="manage && ! inTrash">
                <span style="display: contents">
                    <button type="button" class="dashed-media-btn" x-show="canEdit" x-on:click="askMoveItems()">{!! $icon('heroicon-o-arrow-right-circle') !!} {{ __('Verplaatsen naar...') }}</button>
                    <button type="button" class="dashed-media-btn" x-show="canEdit" x-on:click="askTags()">{!! $icon('heroicon-o-tag') !!} {{ __('Tags toevoegen of verwijderen') }}</button>
                    <button type="button" class="dashed-media-btn" x-on:click="bulkFavorite()">{!! $icon('heroicon-o-star') !!} {{ __('Favoriet') }}</button>
                    @foreach ($mediaBulkActions as $action)
                        <button type="button" class="dashed-media-btn" x-show="canEdit" x-on:click="runAction('bulk', @js($action['key']))">
                            {!! $icon($action['icon']) !!} {{ $action['label'] }}
                        </button>
                    @endforeach
                    <button type="button" class="dashed-media-btn" x-show="canEdit" x-bind:disabled="busy || !! run" x-on:click="askRegenerate(targetIds())">{!! $icon('heroicon-o-photo') !!} {{ __('Formaten opnieuw genereren') }}</button>
                    <button type="button" class="dashed-media-btn" x-show="canMerge" x-on:click="askMerge()">{!! $icon('heroicon-o-document-duplicate') !!} {{ __('Samenvoegen') }}</button>
                    <button type="button" class="dashed-media-btn dashed-media-btn--danger" x-show="canDelete" x-on:click="askDeleteSelected()">{!! $icon('heroicon-o-trash') !!} {{ __('Verwijderen') }}</button>
                </span>
            </template>
            <button type="button" class="dashed-media-btn" x-on:click="downloadZip()">{!! $icon('heroicon-o-archive-box-arrow-down') !!} {{ __('Downloaden als zip') }}</button>
            <button type="button" class="dashed-media-btn" x-on:click="clearSelection()">{!! $icon('heroicon-o-x-mark') !!} {{ __('Selectie opheffen') }}</button>
            <button type="button" class="dashed-media-btn dashed-media-btn--primary" x-show="! manage" x-on:click="confirmPick()">{{ __('Kiezen') }}</button>
        </div>
        <div class="dashed-media-note" x-show="selectionNote || (hasMore && items.length)" x-cloak>
            <span x-text="selectionNote"></span>
            <button type="button" class="dashed-media-btn dashed-media-btn--ghost" style="text-decoration: underline" x-show="manage && hasMore && ! selectionNote" x-on:click="selectAllInFilter()">{{ __('Alles in dit filter selecteren') }}</button>
        </div>

        {{-- Uploadlijst --}}
        <div class="dashed-media-uploads" x-show="uploads.length" x-cloak>
            <div class="dashed-media-uploads-head">
                <span>{{ __('Uploads') }}</span>
                <button type="button" class="dashed-media-btn dashed-media-btn--ghost" x-show="! uploadsBusy" x-on:click="clearUploads()">{{ __('Lijst wissen') }}</button>
            </div>
            <template x-for="upload in uploads" x-bind:key="upload.key">
                <div class="dashed-media-upload">
                    <span class="dashed-media-upload-name" x-text="upload.name" x-bind:title="upload.name"></span>
                    <span class="dashed-media-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" x-bind:aria-valuenow="upload.progress" x-bind:aria-label="upload.name">
                        <span x-bind:style="'width:' + upload.progress + '%'"></span>
                    </span>
                    <span class="dashed-media-upload-state" x-bind:data-status="upload.status" x-text="statusLabel(upload)"></span>
                    <button type="button" class="dashed-media-btn dashed-media-btn--ghost dashed-media-upload-again" x-show="upload.canAddAnyway" x-on:click="addAnyway(upload.key)">{{ __('Toch als nieuw bestand toevoegen') }}</button>
                </div>
            </template>
        </div>

        {{-- Uploadtabblad van het keuzevenster --}}
        <div class="dashed-media-pickdrop" x-show="! manage && pickTab === 'upload'" x-cloak
             x-bind:class="{ 'dashed-media-pickdrop--over': gridDrop }"
             x-on:dragover="overGrid($event)"
             x-on:dragleave="leaveGrid($event)"
             x-on:drop="dropOnGrid($event)">
            {!! $icon('heroicon-o-arrow-up-tray', 'dashed-media-icon dashed-media-icon--lg') !!}
            <strong>{{ __('Sleep bestanden hierheen') }}</strong>
            <span>{{ __('Of plak een afbeelding met Ctrl/Cmd+V. Wat je uploadt staat daarna geselecteerd.') }}</span>
            <button type="button" class="dashed-media-btn dashed-media-btn--primary" x-on:click="pickFiles()">
                {!! $icon('heroicon-o-arrow-up-tray') !!} {{ __('Kies bestanden van je computer') }}
            </button>
        </div>

        {{--
            Fase 11: het paneel van een externe bron (URL, stock, AI). Alles
            wat hier van een aanbieder komt (namen, titels) staat er met
            x-text in, dus als tekst; een link bestaat alleen voor een
            https-adres dat de server op het domein van de aanbieder vond.
        --}}
        @if (! empty($mediaSources))
            <div class="dashed-media-source" x-show="sourceKey && (manage || pickTab === 'source')" x-cloak data-media-source-panel>
                <div class="dashed-media-source-head">
                    <strong x-text="source.title || source.label"></strong>
                    <span class="dashed-media-spacer"></span>
                    <button type="button" class="dashed-media-btn dashed-media-btn--ghost" x-show="manage" x-on:click="closeSource()" x-bind:aria-label="SL.close">{!! $icon('heroicon-o-x-mark') !!}</button>
                </div>
                <p class="dashed-media-source-help" x-show="source.help" x-text="source.help"></p>

                {{-- Van een URL --}}
                <form class="dashed-media-source-row" x-show="source.kind === 'url'" x-on:submit.prevent="sourceImportUrl()">
                    <input type="url" class="dashed-media-input" maxlength="2000" inputmode="url" autocomplete="off" spellcheck="false" data-media-source-url
                           x-bind:placeholder="source.placeholder || ''" x-bind:aria-label="source.title || ''" x-model="sourceForm.url">
                    <button type="submit" class="dashed-media-btn dashed-media-btn--primary" x-bind:disabled="sourceBusy || ! sourceForm.url.trim()">
                        {!! $icon('heroicon-o-arrow-down-tray') !!} <span x-text="source.button"></span>
                    </button>
                </form>

                {{-- Zoeken bij een stockaanbieder --}}
                <div x-show="source.kind === 'search'">
                    <form class="dashed-media-source-row" x-on:submit.prevent="sourceSearch(1)">
                        <input type="search" class="dashed-media-input" maxlength="100" autocomplete="off" data-media-source-query
                               x-bind:placeholder="source.placeholder || ''" x-bind:aria-label="source.title || ''" x-model="sourceForm.query">
                        <select class="dashed-media-input dashed-media-source-select" x-bind:aria-label="SL.orientation" x-model="sourceForm.orientation">
                            <template x-for="option in (source.orientations || [])" x-bind:key="option.value">
                                <option x-bind:value="option.value" x-text="option.label"></option>
                            </template>
                        </select>
                        <button type="submit" class="dashed-media-btn dashed-media-btn--primary" x-bind:disabled="sourceBusy || ! sourceForm.query.trim()">
                            {!! $icon('heroicon-o-magnifying-glass') !!} <span x-text="source.button"></span>
                        </button>
                    </form>
                    {{-- De vermelding die de aanbieder in een zoekscherm vraagt. --}}
                    <p class="dashed-media-source-credit" x-show="source.attribution">
                        <template x-if="safeHref((source.attribution || {}).url)">
                            <a target="_blank" rel="noopener noreferrer" x-bind:href="safeHref(source.attribution.url)" x-text="source.attribution.text"></a>
                        </template>
                        <template x-if="! safeHref((source.attribution || {}).url)">
                            <span x-text="(source.attribution || {}).text"></span>
                        </template>
                        <span x-show="source.license" x-text="' · ' + (source.license || '')"></span>
                    </p>
                    <div class="dashed-media-source-grid">
                        <template x-for="result in sourceResults" x-bind:key="result.id">
                            <figure class="dashed-media-source-card" x-bind:data-media-source-result="result.id">
                                <img loading="lazy" decoding="async" referrerpolicy="no-referrer" x-bind:src="safeHref(result.thumb)" x-bind:alt="result.alt || ''">
                                <figcaption>
                                    <template x-if="result.author && safeHref(result.author_url)">
                                        <a target="_blank" rel="noopener noreferrer nofollow" x-bind:href="safeHref(result.author_url)" x-text="photoBy(result)"></a>
                                    </template>
                                    <template x-if="result.author && ! safeHref(result.author_url)">
                                        <span x-text="photoBy(result)"></span>
                                    </template>
                                    <span class="dashed-media-source-size" x-show="result.width && result.height" x-text="result.width + ' × ' + result.height"></span>
                                </figcaption>
                                <button type="button" class="dashed-media-btn" x-bind:disabled="result.busy || result.done" x-on:click="sourceImport(result)">
                                    <span x-text="result.done ? SL.imported : (result.busy ? SL.importing : SL.import)"></span>
                                </button>
                            </figure>
                        </template>
                    </div>
                    <button type="button" class="dashed-media-btn" x-show="sourceHasMore" x-bind:disabled="sourceBusy" x-on:click="sourceSearch(sourcePage + 1)" x-text="SL.more"></button>
                </div>

                {{-- Maken met AI: de aanvraag gaat naar de wachtrij, het paneel vraagt de stand op tot het klaar is. --}}
                <form class="dashed-media-source-form" x-show="source.kind === 'generate'" x-on:submit.prevent="sourceGenerate()">
                    <label class="dashed-media-label" x-bind:for="$id('dm-dialog') + '-prompt'" x-text="SL.prompt"></label>
                    <textarea class="dashed-media-textarea" rows="3" maxlength="2000" data-media-source-prompt x-bind:id="$id('dm-dialog') + '-prompt'"
                              x-bind:placeholder="source.placeholder || ''" x-model="sourceForm.prompt"></textarea>
                    <div class="dashed-media-source-row">
                        <select class="dashed-media-input dashed-media-source-select" x-bind:aria-label="SL.ratio" x-model="sourceForm.ratio">
                            <template x-for="option in (source.ratios || [])" x-bind:key="option.value">
                                <option x-bind:value="option.value" x-text="option.label"></option>
                            </template>
                        </select>
                        <span class="dashed-media-source-reference">
                            <span x-text="SL.reference + ': ' + (sourceReference ? sourceReference.name : SL.noReference)"></span>
                            <button type="button" class="dashed-media-btn dashed-media-btn--ghost" x-show="! sourceReference && detailOpen && detail.type === 'image' && ! detail.private" x-on:click="useReference()" x-text="SL.useReference"></button>
                            <button type="button" class="dashed-media-btn dashed-media-btn--ghost" x-show="sourceReference" x-on:click="sourceReference = null" x-text="SL.removeReference"></button>
                        </span>
                        <span class="dashed-media-spacer"></span>
                        <button type="submit" class="dashed-media-btn dashed-media-btn--primary" x-bind:disabled="sourceBusy || ! sourceForm.prompt.trim()">
                            {!! $icon('heroicon-o-sparkles') !!} <span x-text="source.button"></span>
                        </button>
                    </div>
                    <p class="dashed-media-source-help" x-show="source.referenceHelp" x-text="source.referenceHelp"></p>
                </form>

                <p class="dashed-media-source-status" role="status" data-media-source-status x-show="sourceMessage" x-bind:data-status="sourceState">
                    <span class="dashed-media-spin" aria-hidden="true" x-show="sourceBusy"></span>
                    <span x-text="sourceMessage"></span>
                </p>
            </div>
        @endif

        <div class="dashed-media-scroll" x-ref="scroll" x-show="manage || (pickTab !== 'upload' && pickTab !== 'source')"
             x-bind:class="{ 'dashed-media-scroll--drop': gridDrop }"
             x-on:dragover="overGrid($event)"
             x-on:dragleave="leaveGrid($event)"
             x-on:drop="dropOnGrid($event)">

            {{-- Eerlijk over wat de lijst is: gebaseerd op de bekende velden. --}}
            <p class="dashed-media-caveat" x-show="manage && filters.smart === 'unused'" x-cloak x-text="L.indexCaveat"></p>

            {{-- Mappen in de prullenbak: een map komt met alles wat er in dezelfde handeling mee is weggegooid terug. --}}
            <ul class="dashed-media-trashfolders" x-show="inTrash && trashedFolders.length" x-cloak aria-label="{{ __('Mappen in de prullenbak') }}">
                <template x-for="folder in trashedFolders" x-bind:key="folder.id">
                    <li>
                        {!! $icon('heroicon-o-folder') !!}
                        <span class="dashed-media-node-name" x-text="folder.name"></span>
                        <span class="dashed-media-row-meta" x-text="trashedFolderLabel(folder)"></span>
                        <button type="button" class="dashed-media-btn" x-show="canFolders && canDelete" x-on:click="restoreFolder(folder.id)">{!! $icon('heroicon-o-arrow-uturn-left') !!} {{ __('Map terugzetten') }}</button>
                    </li>
                </template>
            </ul>

            <div class="dashed-media-row dashed-media-list-head" aria-hidden="true" x-show="view === 'list' && items.length" x-cloak>
                <span></span><span></span>
                <span>{{ __('Naam') }}</span>
                <span class="dashed-media-hide-narrow">{{ __('Type') }}</span>
                <span class="dashed-media-hide-narrow">{{ __('Afmetingen') }}</span>
                <span>{{ __('Grootte') }}</span>
                <span class="dashed-media-hide-narrow">{{ __('Datum') }}</span>
            </div>

            <ul x-ref="grid" role="listbox" aria-multiselectable="true" aria-label="{{ __('Bestanden') }}"
                x-bind:class="view === 'list' ? 'dashed-media-list' : 'dashed-media-grid'"
                x-on:keydown="gridKey($event)">
                <template x-for="(item, index) in items" x-bind:key="item.id">
                    <li role="option"
                        x-bind:class="[view === 'list' ? 'dashed-media-row' : 'dashed-media-tile', isChosen(item.id) ? 'dashed-media-item--chosen' : '']"
                        x-bind:aria-selected="isSelected(item.id)"
                        x-bind:tabindex="index === focusIndex ? 0 : -1"
                        x-bind:data-index="index"
                        x-bind:draggable="manage && canEdit ? 'true' : null"
                        x-bind:title="item.name"
                        x-on:click="clickItem(index, $event)"
                        x-on:dblclick="dblItem(index)"
                        x-on:focus="focusIndex = index"
                        x-on:dragstart="dragItem($event, index)">
                        <button type="button" class="dashed-media-tick" tabindex="-1" role="checkbox"
                                x-bind:aria-checked="isSelected(item.id)"
                                aria-label="{{ __('Selecteren') }}"
                                x-on:click.stop="tick(index)">
                            {!! $icon('heroicon-m-check') !!}
                        </button>
                        <div class="dashed-media-thumb" x-bind:class="{ 'dashed-media-thumb--check': item.thumb && transparent(item) }">
                            <template x-if="item.thumb">
                                {{-- De beheerminiatuur (320) met de gewone (600) voor een scherm met dubbele dichtheid; expliciete maten tegen verspringen. --}}
                                <img x-bind:src="item.thumb_small || item.thumb" x-bind:srcset="thumbSrcset(item)" x-bind:width="item.thumb_small ? {{ \Dashed\DashedFiles\Classes\ConversionDefinition::adminThumb()->width }} : null" x-bind:height="item.thumb_small ? {{ \Dashed\DashedFiles\Classes\ConversionDefinition::adminThumb()->height }} : null" x-bind:alt="item.alt || ''" loading="lazy" decoding="async" draggable="false">
                            </template>
                            <template x-if="! item.thumb">
                                <span style="display: contents">
                                    <span x-show="item.type === 'video'">{!! $icon('heroicon-o-film', 'dashed-media-icon dashed-media-icon--lg') !!}</span>
                                    <span x-show="item.type === 'document'">{!! $icon('heroicon-o-document-text', 'dashed-media-icon dashed-media-icon--lg') !!}</span>
                                    <span x-show="item.type === 'image' || item.type === 'svg'">{!! $icon('heroicon-o-photo', 'dashed-media-icon dashed-media-icon--lg') !!}</span>
                                    <span class="dashed-media-ext" x-text="extension(item)"></span>
                                </span>
                            </template>
                            {{-- Fase 9b: een video of pdf met voorvertoning is aan zijn markering te herkennen; duur of aantal pagina's erbij. --}}
                            <span class="dashed-media-kind" x-show="item.thumb && item.type === 'video'" aria-hidden="true">{!! $icon('heroicon-s-play') !!}</span>
                            <span class="dashed-media-kind dashed-media-kind--pdf" x-show="item.thumb && item.mime === 'application/pdf'" aria-hidden="true">PDF</span>
                            <span class="dashed-media-kind dashed-media-kind--pdf" x-show="item.thumb && item.animated" aria-hidden="true">GIF</span>
                            <span class="dashed-media-length" x-show="previewBadge(item)" x-text="previewBadge(item)"></span>
                            {{-- Fase 10: een bestand uit een privé-map. De miniatuur komt via een ondertekende route. --}}
                            <span class="dashed-media-lock dashed-media-lock--tile" x-show="item.private" x-bind:title="item.relocating ? L.privateMoving : L.privateItem">{!! $icon('heroicon-s-lock-closed') !!}<span class="dashed-media-sr" x-text="L.privateItem"></span></span>
                            <span class="dashed-media-chosen" x-show="isChosen(item.id)">{!! $icon('heroicon-m-check') !!} {{ __('Al gekozen') }}</span>
                            <span class="dashed-media-flag" x-show="needsAlt(item) && ! item.trashed" x-bind:title="altFlagTitle(item)">
                                {!! $icon('heroicon-m-exclamation-triangle') !!} <span x-text="altFlagText(item)">{{ __('Geen alt') }}</span>
                            </span>
                            {{-- Alleen als de gebruiksindex gevuld is: dan is usage_count een getal en anders null. --}}
                            <span class="dashed-media-flag dashed-media-flag--unused" x-show="manage && item.usage_count === 0 && ! item.trashed" title="{{ __('Dit bestand wordt nergens op de site gebruikt') }}">{{ __('Ongebruikt') }}</span>
                            <span class="dashed-media-flag dashed-media-flag--unused" x-show="item.trashed && ! item.in_use_on_site" x-bind:title="purgeLabel(item)" x-text="purgeShort(item)"></span>
                            {{-- De site heeft dit weggegooide bestand nog uitgeleverd, of de index kent er nog een verwijzing naar. --}}
                            <span class="dashed-media-flag dashed-media-flag--inuse" x-show="item.trashed && item.in_use_on_site" x-bind:title="L.inUseOnSite" x-text="L.inUseOnSite"></span>
                        </div>
                        <div class="dashed-media-name" x-text="item.name"></div>
                        <span class="dashed-media-row-meta dashed-media-hide-narrow" x-text="typeLabel(item)"></span>
                        <span class="dashed-media-row-meta dashed-media-hide-narrow" x-text="metaLabel(item)"></span>
                        <span class="dashed-media-row-meta" x-text="size(item.size)"></span>
                        <span class="dashed-media-row-meta dashed-media-hide-narrow" x-text="date(item.created_at)"></span>
                        <button type="button" class="dashed-media-star" tabindex="-1"
                                x-bind:aria-pressed="item.is_favorite"
                                aria-label="{{ __('Favoriet') }}"
                                x-on:click.stop="toggleFavorite(item.id)">
                            <span x-show="item.is_favorite">{!! $icon('heroicon-s-star') !!}</span>
                            <span x-show="! item.is_favorite">{!! $icon('heroicon-o-star') !!}</span>
                        </button>
                    </li>
                </template>
            </ul>

            <div class="dashed-media-grid" x-show="loading && ! items.length" aria-hidden="true">
                @for ($i = 0; $i < 12; $i++)
                    <div class="dashed-media-skeleton"></div>
                @endfor
            </div>

            <div class="dashed-media-empty" x-show="! loading && ! items.length && ! failed" x-cloak>
                {!! $icon('heroicon-o-photo', 'dashed-media-icon dashed-media-icon--lg') !!}
                <strong>{{ __('Hier staan nog geen bestanden') }}</strong>
                <span x-show="activeFilterCount > 0">{{ __('Geen enkel bestand past bij deze filters. Wis de filters of zoek op iets anders.') }}</span>
                <span x-show="activeFilterCount === 0 && ! filters.smart">{{ __('Sleep bestanden hierheen, plak een afbeelding met Ctrl/Cmd+V, of kies bestanden van je computer.') }}</span>
                <span x-show="inTrash && activeFilterCount === 0">{{ __('De prullenbak is leeg.') }}</span>
                <button type="button" class="dashed-media-btn dashed-media-btn--primary" x-show="manage && canUpload && ! filters.smart" x-on:click="pickFiles()">
                    {!! $icon('heroicon-o-arrow-up-tray') !!} {{ __('Uploaden') }}
                </button>
            </div>

            <div class="dashed-media-empty" x-show="failed" x-cloak role="alert">
                <strong>{{ __('De bestanden konden niet worden geladen.') }}</strong>
                <button type="button" class="dashed-media-btn" x-on:click="reload()">{{ __('Opnieuw proberen') }}</button>
            </div>

            <div class="dashed-media-foot" x-show="loading && items.length" x-cloak>
                <span class="dashed-media-spin" aria-hidden="true"></span> {{ __('Meer bestanden laden...') }}
            </div>
            <div class="dashed-media-sentinel" x-ref="sentinel" aria-hidden="true"></div>
        </div>

        {{-- Voet van het keuzevenster --}}
        <div class="dashed-media-pickbar" x-show="! manage" x-cloak>
            <strong role="status" x-text="pickCountLabel"></strong>
            <span class="dashed-media-spacer"></span>
            <button type="button" class="dashed-media-btn" x-on:click="cancelPick()">{{ __('Annuleren') }}</button>
            <button type="button" class="dashed-media-btn dashed-media-btn--primary" x-bind:disabled="! selectedCount" x-on:click="confirmPick()">{{ __('Kiezen') }}</button>
        </div>
    </section>

    {{-- Detailpaneel --}}
    <template x-if="detailOpen">
        <aside class="dashed-media-detail" aria-label="{{ __('Details van het bestand') }}">
            <div class="dashed-media-detail-head">
                <span x-text="detail.file_name || detail.name"></span>
                <button type="button" class="dashed-media-btn dashed-media-btn--ghost" aria-label="{{ __('Sluiten') }}" x-on:click="closeDetail()">{!! $icon('heroicon-o-x-mark') !!}</button>
            </div>
            <div class="dashed-media-detail-body">
                <div class="dashed-media-preview">
                    <template x-if="detail.type === 'video' && detail.url">
                        <video controls preload="metadata" playsinline x-bind:src="detail.url" x-bind:poster="detail.thumb || null"></video>
                    </template>
                    <template x-if="detail.type !== 'video'">
                        <div class="dashed-media-thumb" x-bind:class="{ 'dashed-media-thumb--check': detail.thumb && transparent(detail) }">
                            <template x-if="detail.thumb">
                                {{-- Een geanimeerde gif speelt in het paneel; de tegels tonen alleen het eerste beeld. --}}
                                <img x-bind:src="detail.animated && detail.url ? detail.url : detail.thumb" x-bind:alt="detail.alt || ''" decoding="async">
                            </template>
                            <template x-if="! detail.thumb">
                                <span style="display: contents">
                                    {!! $icon('heroicon-o-document-text', 'dashed-media-icon dashed-media-icon--lg') !!}
                                    <span class="dashed-media-ext" x-text="extension(detail)"></span>
                                </span>
                            </template>
                        </div>
                    </template>
                </div>

                {{-- Fase 10 --}}
                <div class="dashed-media-banner" x-show="detail.private" role="status">
                    <strong>{!! $icon('heroicon-s-lock-closed') !!} {{ __('Privé') }}</strong>
                    <span>{{ __('Dit bestand staat in een privé-map. De site kan het niet tonen; in het beheer open je het via een link die een kwartier geldig is.') }}</span>
                    <span x-show="detail.relocating" x-text="L.privateMoving"></span>
                </div>

                <div class="dashed-media-banner" x-show="detail.trashed" role="status">
                    <strong>{{ __('In prullenbak') }}</strong>
                    <span x-text="purgeLabel(detail)"></span>
                    <span>{{ __('Op de site blijft dit bestand zichtbaar tot het definitief is verwijderd.') }}</span>
                    <strong x-show="detail.in_use_on_site" x-text="L.inUseOnSite"></strong>
                    <span x-show="detail.merge_batch" x-text="L.merged"></span>
                    <span class="dashed-media-actions" x-show="manage">
                        <button type="button" class="dashed-media-btn dashed-media-btn--primary" x-show="canDelete" x-bind:disabled="busy" x-on:click="restoreItems([detail.id])">{!! $icon('heroicon-o-arrow-uturn-left') !!} {{ __('Terugzetten') }}</button>
                        <button type="button" class="dashed-media-btn" x-show="canPurge && detail.merge_batch" x-bind:disabled="busy" x-on:click="undoMerge(detail.merge_batch)">{!! $icon('heroicon-o-arrow-uturn-left') !!} {{ __('Samenvoegen ongedaan maken') }}</button>
                        <button type="button" class="dashed-media-btn dashed-media-btn--danger" x-show="canPurge" x-on:click="askPurge([detail.id])">{!! $icon('heroicon-o-trash') !!} {{ __('Definitief verwijderen') }}</button>
                    </span>
                </div>

                {{-- Focuspunt: klik of sleep de markering, of gebruik de pijltjestoetsen. Alleen voor rasterafbeeldingen. --}}
                <template x-if="manage && canEdit && detail.can_focus && detail.url">
                    <div class="dashed-media-focal">
                        <div class="dashed-media-label" x-bind:id="$id('dm-focal')">{{ __('Focuspunt') }}</div>
                        <div class="dashed-media-focal-stage" tabindex="0" role="group" x-ref="focalStage"
                             x-bind:aria-labelledby="$id('dm-focal')"
                             aria-description="{{ __('Klik of sleep om het focuspunt te zetten. Pijltjestoetsen verplaatsen het 1 procent, met Shift 10 procent.') }}"
                             x-on:pointerdown="focalDown($event)" x-on:keydown="focalKey($event)">
                            <img x-bind:src="detail.url" alt="" draggable="false" decoding="async">
                            <span class="dashed-media-focal-dot" aria-hidden="true" x-bind:style="'left:' + focal.x + '%;top:' + focal.y + '%'"></span>
                        </div>
                        <div class="dashed-media-focal-previews" aria-hidden="true">
                            <figure>
                                <img class="dashed-media-focal-square" x-bind:src="detail.url" alt="" x-bind:style="'object-position:' + focal.x + '% ' + focal.y + '%'">
                                <figcaption>{{ __('Vierkant') }}</figcaption>
                            </figure>
                            <figure>
                                <img class="dashed-media-focal-wide" x-bind:src="detail.url" alt="" x-bind:style="'object-position:' + focal.x + '% ' + focal.y + '%'">
                                <figcaption>{{ __('Liggend') }}</figcaption>
                            </figure>
                        </div>
                        <div class="dashed-media-actions">
                            <span class="dashed-media-row-meta" aria-live="polite" x-text="focalText()"></span>
                            <span class="dashed-media-spacer"></span>
                            <button type="button" class="dashed-media-btn" x-bind:disabled="busy || (focal.x === 50 && focal.y === 50)" x-on:click="focalCenter()">{{ __('Midden') }}</button>
                            <button type="button" class="dashed-media-btn dashed-media-btn--primary" x-show="focalDirty" x-bind:disabled="busy" x-on:click="focalSave()">{{ __('Focuspunt opslaan') }}</button>
                        </div>
                    </div>
                </template>

                <div class="dashed-media-actions">
                    <a class="dashed-media-btn" target="_blank" rel="noopener" x-show="detail.url" x-bind:href="detail.url">{!! $icon('heroicon-o-arrow-top-right-on-square') !!} {{ __('Openen') }}</a>
                    <button type="button" class="dashed-media-btn" x-show="detail.url && ! detail.private" x-on:click="copyUrl()">{!! $icon('heroicon-o-link') !!} {{ __('URL kopiëren') }}</button>
                    <a class="dashed-media-btn" x-show="detail.url" x-bind:href="detail.url" x-bind:download="detail.file_name || ''">{!! $icon('heroicon-o-arrow-down-tray') !!} {{ __('Downloaden') }}</a>
                    <button type="button" class="dashed-media-btn" x-bind:class="{ 'dashed-media-btn--on': detail.is_favorite }" x-bind:aria-pressed="detail.is_favorite" x-on:click="toggleFavorite(detail.id)">
                        <span x-show="detail.is_favorite">{!! $icon('heroicon-s-star') !!}</span>
                        <span x-show="! detail.is_favorite">{!! $icon('heroicon-o-star') !!}</span>
                        {{ __('Favoriet') }}
                    </button>
                    <template x-if="manage && ! detail.trashed">
                        <span style="display: contents">
                            <button type="button" class="dashed-media-btn" x-show="canUploadHere" x-bind:disabled="busy" x-on:click="askReplace()">{!! $icon('heroicon-o-arrow-path') !!} {{ __('Vervangen') }}</button>
                            <button type="button" class="dashed-media-btn" x-show="canEdit && detail.can_edit && detail.edit_source" x-bind:disabled="busy" x-on:click="askEdit()">{!! $icon('heroicon-o-scissors') !!} {{ __('Bewerken') }}</button>
                            <button type="button" class="dashed-media-btn" x-show="canEdit && detail.can_focus" x-bind:disabled="busy || !! run" x-on:click="askRegenerate([detail.id])">{!! $icon('heroicon-o-photo') !!} {{ __('Formaten opnieuw genereren') }}</button>
                            {{-- Fase 9b: de poster van een video of de miniatuur van een pdf. --}}
                            <button type="button" class="dashed-media-btn" x-show="canEdit && detail.can_set_poster" x-bind:disabled="busy" x-on:click="choosePoster()">{!! $icon('heroicon-o-photo') !!} {{ __('Poster kiezen uit de bibliotheek') }}</button>
                            <button type="button" class="dashed-media-btn" x-show="canEdit && detail.can_regenerate_preview" x-bind:disabled="busy" x-on:click="regeneratePreview()">{!! $icon('heroicon-o-arrow-path') !!} {{ __('Poster opnieuw maken') }}</button>
                            <button type="button" class="dashed-media-btn" x-show="canEdit && detail.can_set_poster && detail.poster && detail.poster.custom" x-bind:disabled="busy" x-on:click="removePoster()">{!! $icon('heroicon-o-x-mark') !!} {{ __('Eigen poster verwijderen') }}</button>
                            <button type="button" class="dashed-media-btn" x-show="canEdit" x-on:click="askMoveItems([detail.id])">{!! $icon('heroicon-o-arrow-right-circle') !!} {{ __('Verplaatsen naar...') }}</button>
                            @foreach ($mediaItemActions as $action)
                                <button type="button" class="dashed-media-btn" x-show="canEdit && (detail.actions || []).includes(@js($action['key']))" x-on:click="runAction('item', @js($action['key']))">
                                    {!! $icon($action['icon']) !!} {{ $action['label'] }}
                                </button>
                            @endforeach
                            <button type="button" class="dashed-media-btn dashed-media-btn--danger" x-show="canDelete" x-on:click="askDelete(detail.id)">{!! $icon('heroicon-o-trash') !!} {{ __('Verwijderen') }}</button>
                        </span>
                    </template>
                </div>

                {{-- Alleen bij meer dan één taal: titel, alt-tekst en bijschrift hieronder zijn dan van de gekozen taal. --}}
                <template x-if="locales.length > 1">
                    <div class="dashed-media-locales" role="group" aria-label="{{ __('Taal van titel, alt-tekst en bijschrift') }}">
                        <template x-for="locale in locales" x-bind:key="locale.id">
                            <button type="button" class="dashed-media-locale" x-bind:aria-pressed="textLocale === locale.id ? 'true' : 'false'" x-bind:title="locale.label" x-on:click="setTextLocale(locale.id)">
                                <span x-text="locale.id.toUpperCase()"></span>
                                <span class="dashed-media-locale-dot" x-show="detail.type === 'image' || detail.type === 'svg'"
                                      x-bind:class="{ 'dashed-media-locale-dot--on': hasAlt(locale.id) }"
                                      x-bind:title="hasAlt(locale.id) ? L.altFilled : L.altEmpty"></span>
                                <span class="dashed-media-sr" x-show="detail.type === 'image' || detail.type === 'svg'" x-text="hasAlt(locale.id) ? L.altFilled : L.altEmpty"></span>
                            </button>
                        </template>
                    </div>
                </template>

                <div>
                    <div class="dashed-media-field-head">
                        <label class="dashed-media-label" x-bind:for="$id('dm-title')">{{ __('Titel') }}</label>
                        <span class="dashed-media-saved" x-show="saved.title" role="status">{!! $icon('heroicon-m-check') !!} {{ __('Opgeslagen') }}</span>
                    </div>
                    <input type="text" class="dashed-media-input" maxlength="255" x-bind:id="$id('dm-title')" x-bind:placeholder="detail.file_name || ''" x-bind:disabled="! manage || ! canEdit"
                           x-model="form.title" x-on:blur="saveField('title')" x-on:keydown.enter.prevent="$event.target.blur()">
                </div>

                <div x-show="detail.type === 'image' || detail.type === 'svg'">
                    <div class="dashed-media-field-head">
                        <label class="dashed-media-label" x-bind:for="$id('dm-alt')">{{ __('Alt-tekst') }}</label>
                        <span class="dashed-media-saved" x-show="saved.alt" role="status">{!! $icon('heroicon-m-check') !!} {{ __('Opgeslagen') }}</span>
                    </div>
                    <textarea class="dashed-media-textarea" maxlength="255" x-bind:id="$id('dm-alt')" x-bind:disabled="! manage || ! canEdit"
                              placeholder="{{ __('Beschrijf wat er op de afbeelding te zien is') }}"
                              x-model="form.alt" x-on:blur="saveField('alt')"></textarea>
                    {{-- De AI gaf het na drie pogingen op: zichtbaar, met een weg terug. --}}
                    <template x-if="manage && detail.ai_alt_failed && ! detail.trashed">
                        <p class="dashed-media-ai-failed" role="status">
                            <span>{!! $icon('heroicon-m-exclamation-triangle') !!} {{ __('AI kon geen alt-tekst maken.') }}</span>
                            <button type="button" class="dashed-media-btn" x-bind:disabled="busy" x-on:click="retryAltText()">{!! $icon('heroicon-o-arrow-path') !!} {{ __('Opnieuw proberen') }}</button>
                        </p>
                    </template>
                </div>

                <div>
                    <div class="dashed-media-field-head">
                        <label class="dashed-media-label" x-bind:for="$id('dm-caption')">{{ __('Bijschrift') }}</label>
                        <span class="dashed-media-saved" x-show="saved.caption" role="status">{!! $icon('heroicon-m-check') !!} {{ __('Opgeslagen') }}</span>
                    </div>
                    <textarea class="dashed-media-textarea" maxlength="255" x-bind:id="$id('dm-caption')" x-bind:disabled="! manage || ! canEdit"
                              x-model="form.caption" x-on:blur="saveField('caption')"></textarea>
                </div>

                <div>
                    <div class="dashed-media-field-head">
                        <label class="dashed-media-label" x-bind:for="$id('dm-tags')">{{ __('Tags') }}</label>
                        <span class="dashed-media-saved" x-show="saved.tags" role="status">{!! $icon('heroicon-m-check') !!} {{ __('Opgeslagen') }}</span>
                    </div>
                    <div class="dashed-media-chips" x-show="(detail.tags || []).length">
                        <template x-for="tag in (detail.tags || [])" x-bind:key="tag">
                            <span class="dashed-media-chip">
                                <span x-text="tag"></span>
                                <button type="button" x-show="manage && canEdit" x-bind:aria-label="@js(__('Tag verwijderen')) + ': ' + tag" x-on:click="removeTag(tag)">{!! $icon('heroicon-m-x-mark') !!}</button>
                            </span>
                        </template>
                    </div>
                    <input type="text" class="dashed-media-input" maxlength="50" x-show="manage && canEdit" x-bind:id="$id('dm-tags')"
                           placeholder="{{ __('Tag toevoegen en Enter') }}"
                           x-model="tagInput"
                           x-on:focus="suggestTags()"
                           x-on:input="suggestTags()"
                           x-on:keydown.enter.prevent="addTag(tagInput)">
                    <div class="dashed-media-suggest" x-show="manage && canEdit && tagSuggestions.length">
                        <template x-for="name in tagSuggestions" x-bind:key="name">
                            <button type="button" x-text="name" x-on:click="addTag(name)"></button>
                        </template>
                    </div>
                </div>

                {{-- Fase 11: de herkomst. Alles als tekst; een link alleen naar het domein van de aanbieder. --}}
                <div class="dashed-media-origin" x-show="detail.origin" data-media-origin>
                    <div class="dashed-media-label">{{ __('Herkomst') }}</div>
                    <div x-text="(detail.origin || {}).label || ''"></div>
                    <div class="dashed-media-origin-text" x-show="(detail.origin || {}).address" x-text="(detail.origin || {}).address"></div>
                    <template x-if="(detail.origin || {}).kind === 'stock' || (detail.origin || {}).credit">
                        <div class="dashed-media-origin-credit">
                            <span x-text="detail.origin.credit || ''"></span>
                            <template x-if="safeHref(detail.origin.author_url)">
                                <a target="_blank" rel="noopener noreferrer nofollow" x-bind:href="safeHref(detail.origin.author_url)">{{ __('Profiel van de maker') }}</a>
                            </template>
                            <template x-if="safeHref(detail.origin.provider_url)">
                                <a target="_blank" rel="noopener noreferrer nofollow" x-bind:href="safeHref(detail.origin.provider_url)">{{ __('Bekijk bij de aanbieder') }}</a>
                            </template>
                            <span x-show="detail.origin.license" x-text="detail.origin.license"></span>
                            <button type="button" class="dashed-media-btn" x-show="manage && canEdit && detail.origin.credit && ! detail.trashed" x-on:click="creditToCaption()">{{ __('Vermelding in bijschrift zetten') }}</button>
                        </div>
                    </template>
                    <div class="dashed-media-origin-text" x-show="(detail.origin || {}).prompt" x-text="(detail.origin || {}).prompt"></div>
                </div>

                {{-- Waar gebruikt. Zolang de index nog niet gevuld is (usage_count is dan null) staat hier niets. --}}
                <div class="dashed-media-usage" x-show="manage && detail.usage_count !== null && detail.usage_count !== undefined">
                    <div class="dashed-media-label" x-text="usageHeading(detail)"></div>
                    <ul x-show="(detail.usages || []).length">
                        <template x-for="(usage, usageIndex) in (detail.usages || [])" x-bind:key="usageIndex">
                            <li>
                                <span class="dashed-media-row-meta" x-text="usage.label"></span>
                                <template x-if="usage.url">
                                    <a x-bind:href="usage.url" target="_blank" rel="noopener" x-text="usage.title"></a>
                                </template>
                                <template x-if="! usage.url">
                                    <span x-text="usage.title"></span>
                                </template>
                                <span class="dashed-media-row-meta" x-text="usageWhere(usage)"></span>
                                {{-- Aangenomen op de naam van een sleutel of op een getal dat toevallig een media-id is. --}}
                                <span class="dashed-media-chip" x-show="usage.guess" x-text="L.guess" title="{{ __('Deze plek is niet zeker: het veld is niet bekend bij de mediabibliotheek. Telt mee als gebruik, wordt nooit automatisch aangepast.') }}"></span>
                            </li>
                        </template>
                    </ul>
                    <p class="dashed-media-row-meta" x-show="detail.usage_count > (detail.usages || []).length" x-text="usageMore(detail)"></p>
                </div>

                <dl class="dashed-media-facts">
                    <dt>{{ __('Type') }}</dt>
                    <dd><span x-text="typeLabel(detail)"></span> <span class="dashed-media-node-count" x-text="detail.mime || ''"></span></dd>
                    <template x-if="dimensions(detail)">
                        <span style="display: contents"><dt>{{ __('Afmetingen') }}</dt><dd x-text="dimensions(detail) + ' px'"></dd></span>
                    </template>
                    <template x-if="detail.duration">
                        <span style="display: contents"><dt>{{ __('Duur') }}</dt><dd x-text="duration(detail.duration)"></dd></span>
                    </template>
                    <template x-if="detail.pages">
                        <span style="display: contents"><dt>{{ __("Pagina's") }}</dt><dd x-text="detail.pages"></dd></span>
                    </template>
                    <template x-if="detail.type === 'video' || detail.mime === 'application/pdf'">
                        <span style="display: contents"><dt>{{ __('Voorvertoning') }}</dt><dd x-text="posterLabel(detail)"></dd></span>
                    </template>
                    <dt>{{ __('Grootte') }}</dt>
                    <dd x-text="size(detail.size)"></dd>
                    <dt>{{ __('Geüpload op') }}</dt>
                    <dd x-text="date(detail.created_at)"></dd>
                    <template x-if="detail.uploader">
                        <span style="display: contents"><dt>{{ __('Geüpload door') }}</dt><dd x-text="detail.uploader"></dd></span>
                    </template>
                    <dt>{{ __('Map') }}</dt>
                    <dd>
                        <button type="button" x-on:click="openFolder(detail.folder_id || 'root')"
                                x-text="(detail.breadcrumb || []).length ? detail.breadcrumb.map((step) => step.name).join(' / ') : L.noFolder"></button>
                    </dd>
                    <template x-if="detail.source">
                        <span style="display: contents"><dt>{{ __('Bron') }}</dt><dd x-text="detail.source"></dd></span>
                    </template>
                    <template x-if="(detail.conversions || []).length">
                        <span style="display: contents"><dt>{{ __('Formaten') }}</dt><dd x-text="detail.conversions.join(', ')"></dd></span>
                    </template>
                </dl>
            </div>
        </aside>
    </template>

    {{-- Dialogen --}}
    <template x-if="dialogOpen">
        <div class="dashed-media-backdrop" x-on:mousedown.self="closeDialog()">
            <div class="dashed-media-dialog" x-bind:class="{ 'dashed-media-dialog--wide': dialog.type === 'edit' }" role="dialog" aria-modal="true" x-ref="dialog" x-bind:aria-labelledby="$id('dm-dialog')" x-on:keydown.tab="trapFocus($event)" x-on:keydown.stop="if ($event.key === 'Escape') { closeDialog(); }">

                <template x-if="dialog.type === 'confirm'">
                    <form x-on:submit.prevent="confirmGeneric()" style="display: contents">
                        <div class="dashed-media-dialog-head" x-bind:id="$id('dm-dialog')" x-text="dialog.title"></div>
                        <div class="dashed-media-dialog-body">
                            <p style="margin: 0" x-text="dialog.body"></p>
                            <p style="margin: 0; font-weight: 600" x-show="dialog.note" x-text="dialog.note"></p>
                            <p class="dashed-media-caveat" x-show="dialog.caveat" x-text="dialog.caveat"></p>
                            <ul class="dashed-media-dialog-lines" x-show="(dialog.lines || []).length">
                                <template x-for="(line, lineIndex) in (dialog.lines || [])" x-bind:key="lineIndex">
                                    <li x-text="line"></li>
                                </template>
                            </ul>
                        </div>
                        <div class="dashed-media-dialog-foot">
                            <button type="button" class="dashed-media-btn" data-autofocus x-on:click="closeDialog()">{{ __('Annuleren') }}</button>
                            <button type="submit" class="dashed-media-btn" x-bind:class="dialog.danger ? 'dashed-media-btn--danger-solid' : 'dashed-media-btn--primary'" x-bind:disabled="busy" x-text="dialog.action || L.confirm"></button>
                        </div>
                    </form>
                </template>

                <template x-if="dialog.type === 'folder'">
                    <form x-on:submit.prevent="confirmFolder()" style="display: contents">
                        <div class="dashed-media-dialog-head" x-bind:id="$id('dm-dialog')">{{ __('Nieuwe map') }}</div>
                        <div class="dashed-media-dialog-body">
                            <label>
                                <span class="dashed-media-label">{{ __('Naam') }}</span>
                                <input type="text" class="dashed-media-input" maxlength="255" required data-autofocus x-model="dialog.name">
                            </label>
                            <p class="dashed-media-row-meta" style="margin: 0" x-show="dialog.parentId">
                                {{ __('In:') }} <span x-text="folderTrail(dialog.parentId).map((step) => step.name).join(' / ')"></span>
                            </p>
                        </div>
                        <div class="dashed-media-dialog-foot">
                            <button type="button" class="dashed-media-btn" x-on:click="closeDialog()">{{ __('Annuleren') }}</button>
                            <button type="submit" class="dashed-media-btn dashed-media-btn--primary" x-bind:disabled="busy">{{ __('Aanmaken') }}</button>
                        </div>
                    </form>
                </template>

                {{-- Fase 10: privé en sites van een map. --}}
                <template x-if="dialog.type === 'settings'">
                    <form x-on:submit.prevent="confirmFolderSettings()" style="display: contents">
                        <div class="dashed-media-dialog-head" x-bind:id="$id('dm-dialog')"><span x-text="siteOptions.length ? L.folderSettingsSites : L.folderSettings"></span>: <span x-text="dialog.name"></span></div>
                        <div class="dashed-media-dialog-body">
                            <label class="dashed-media-check">
                                <input type="checkbox" data-autofocus x-model="dialog.private" x-bind:disabled="dialog.inheritedPrivate">
                                <span>{{ __('Privé-map') }}</span>
                            </label>
                            <p class="dashed-media-row-meta" style="margin: 0" x-show="! dialog.inheritedPrivate">{{ __('Bestanden in een privé-map (en in elke map eronder) zijn alleen in het beheer te openen. De site kan ze niet tonen.') }}</p>
                            <p class="dashed-media-row-meta" style="margin: 0" data-dashed-media-private-note>{{ __('Let op: privé schermt niet af tussen collega\'s. Iedereen met toegang tot de mediabibliotheek kan privé-bestanden zien en downloaden. Privé houdt ze van de website en uit de AI.') }}</p>
                            <p class="dashed-media-row-meta" style="margin: 0" x-show="dialog.inheritedPrivate">{{ __('Deze map is privé omdat een map erboven privé is. Dat is hier niet uit te zetten.') }}</p>
                            <template x-if="(dialog.siteOptions || []).length">
                                <fieldset class="dashed-media-sites">
                                    <legend class="dashed-media-label">{{ __('Sites') }}</legend>
                                    <p class="dashed-media-row-meta" style="margin: 0">{{ __('Niets aangevinkt is alle sites. Een map kan alleen bij sites horen waar de map erboven ook bij hoort; mappen eronder krimpen mee.') }}</p>
                                    <p class="dashed-media-row-meta" style="margin: 0" x-show="dialog.inheritedSites">{{ __('Van boven beperkt tot:') }} <span x-text="dialog.inheritedSites"></span></p>
                                    <template x-for="site in dialog.siteOptions" x-bind:key="site.id">
                                        <label class="dashed-media-check">
                                            <input type="checkbox" x-bind:value="site.id" x-bind:checked="dialog.sites.includes(site.id)" x-on:change="toggleDialogSite(site.id)">
                                            <span x-text="site.label"></span>
                                        </label>
                                    </template>
                                </fieldset>
                            </template>
                        </div>
                        <div class="dashed-media-dialog-foot">
                            <button type="button" class="dashed-media-btn" x-on:click="closeDialog()">{{ __('Annuleren') }}</button>
                            <button type="submit" class="dashed-media-btn dashed-media-btn--primary" x-bind:disabled="busy">{{ __('Opslaan') }}</button>
                        </div>
                    </form>
                </template>

                <template x-if="dialog.type === 'move'">
                    <form x-on:submit.prevent="confirmMove()" style="display: contents">
                        <div class="dashed-media-dialog-head" x-bind:id="$id('dm-dialog')">{{ __('Verplaatsen naar...') }}</div>
                        <div class="dashed-media-dialog-body">
                            <ul class="dashed-media-picklist" role="radiogroup" aria-label="{{ __('Doelmap') }}">
                                <li>
                                    <button type="button" role="radio" data-autofocus x-bind:aria-checked="dialog.target === null" x-on:click="dialog.target = null">
                                        {!! $icon('heroicon-o-inbox') !!}
                                        <span x-show="dialog.kind === 'items'">{{ __('Zonder map') }}</span>
                                        <span x-show="dialog.kind === 'folder'">{{ __('Bovenste niveau') }}</span>
                                    </button>
                                </li>
                                <template x-for="row in allFolders" x-bind:key="row.id">
                                    <li>
                                        <button type="button" role="radio"
                                                x-bind:aria-checked="dialog.target === row.id"
                                                x-bind:disabled="moveDisabled(row.id)"
                                                x-bind:style="'padding-left:' + (9 + row.depth * 16) + 'px'"
                                                x-on:click="dialog.target = row.id">
                                            {!! $icon('heroicon-o-folder') !!}
                                            <span class="dashed-media-node-name" x-text="row.name"></span>
                                            <span class="dashed-media-lock" x-show="row.private" x-bind:title="L.privateFolder">{!! $icon('heroicon-s-lock-closed') !!}</span>
                                            <span x-show="dialog.target === row.id">{!! $icon('heroicon-m-check') !!}</span>
                                        </button>
                                    </li>
                                </template>
                            </ul>
                        </div>
                        <div class="dashed-media-dialog-foot">
                            <button type="button" class="dashed-media-btn" x-on:click="closeDialog()">{{ __('Annuleren') }}</button>
                            <button type="submit" class="dashed-media-btn dashed-media-btn--primary" x-bind:disabled="busy || dialog.target === undefined">{{ __('Verplaatsen') }}</button>
                        </div>
                    </form>
                </template>

                <template x-if="dialog.type === 'tags'">
                    <form x-on:submit.prevent="confirmTags()" style="display: contents">
                        <div class="dashed-media-dialog-head" x-bind:id="$id('dm-dialog')">{{ __('Tags toevoegen of verwijderen') }}</div>
                        <div class="dashed-media-dialog-body">
                            <div role="radiogroup" aria-label="{{ __('Bewerking') }}" style="display: flex; gap: 14px">
                                <label class="dashed-media-check"><input type="radio" value="add" x-model="dialog.mode"> {{ __('Toevoegen') }}</label>
                                <label class="dashed-media-check"><input type="radio" value="remove" x-model="dialog.mode"> {{ __('Verwijderen') }}</label>
                            </div>
                            <label>
                                <span class="dashed-media-label">{{ __('Tags, gescheiden door een komma') }}</span>
                                <input type="text" class="dashed-media-input" required data-autofocus x-model="dialog.names">
                            </label>
                        </div>
                        <div class="dashed-media-dialog-foot">
                            <button type="button" class="dashed-media-btn" x-on:click="closeDialog()">{{ __('Annuleren') }}</button>
                            <button type="submit" class="dashed-media-btn dashed-media-btn--primary" x-bind:disabled="busy">{{ __('Toepassen') }}</button>
                        </div>
                    </form>
                </template>

                <template x-if="dialog.type === 'merge'">
                    <form x-on:submit.prevent="confirmMerge()" style="display: contents">
                        <div class="dashed-media-dialog-head" x-bind:id="$id('dm-dialog')">{{ __('Dubbele bestanden samenvoegen') }}</div>
                        <div class="dashed-media-dialog-body">
                            <p style="margin: 0" x-text="L.mergeBody"></p>
                            <p style="margin: 0; font-weight: 600" x-show="dialog.altDiffers" x-text="L.mergeAltDiffers"></p>
                            <ul class="dashed-media-picklist" role="radiogroup" aria-label="{{ __('Het bestand dat blijft') }}">
                                <template x-for="(entry, entryIndex) in (dialog.items || [])" x-bind:key="entry.id">
                                    <li>
                                        <button type="button" role="radio" x-bind:data-autofocus="entryIndex === 0 ? '' : null" x-bind:aria-checked="dialog.keep === entry.id" x-on:click="dialog.keep = entry.id">
                                            <span class="dashed-media-node-name" x-text="entry.name"></span>
                                            <span class="dashed-media-row-meta" x-text="mergeMeta(entry)"></span>
                                            <span class="dashed-media-row-meta" x-show="dialog.altDiffers" x-text="entry.alt || L.noAlt"></span>
                                            <span x-show="dialog.keep === entry.id">{!! $icon('heroicon-m-check') !!}</span>
                                        </button>
                                    </li>
                                </template>
                            </ul>
                        </div>
                        <div class="dashed-media-dialog-foot">
                            <button type="button" class="dashed-media-btn" x-on:click="closeDialog()">{{ __('Annuleren') }}</button>
                            <button type="submit" class="dashed-media-btn dashed-media-btn--primary" x-bind:disabled="busy || ! dialog.keep">{{ __('Samenvoegen') }}</button>
                        </div>
                    </form>
                </template>

                <template x-if="dialog.type === 'edit'">
                    <form x-on:submit.prevent="confirmEdit()" style="display: contents">
                        <div class="dashed-media-dialog-head" x-bind:id="$id('dm-dialog')">{{ __('Afbeelding bewerken') }}</div>
                        <div class="dashed-media-dialog-body">
                            <div class="dashed-media-edit-wrap">
                                <div class="dashed-media-edit-stage" tabindex="0" role="group" data-autofocus x-ref="editStage"
                                     aria-label="{{ __('Uitsnede') }}"
                                     aria-description="{{ __('Pijltjestoetsen verplaatsen het kader. Met Shift maken de pijltjes het kader groter of kleiner.') }}"
                                     x-on:keydown="editKey($event)">
                                    <canvas x-ref="editCanvas"></canvas>
                                    <div class="dashed-media-edit-crop" x-show="dialog.ready" x-bind:style="editCropStyle()" x-on:pointerdown="editDown($event, 'move')">
                                        <span class="dashed-media-edit-handle" style="left: 0; top: 0; cursor: nwse-resize" x-on:pointerdown.stop="editDown($event, 'nw')"></span>
                                        <span class="dashed-media-edit-handle" style="left: 100%; top: 0; cursor: nesw-resize" x-on:pointerdown.stop="editDown($event, 'ne')"></span>
                                        <span class="dashed-media-edit-handle" style="left: 0; top: 100%; cursor: nesw-resize" x-on:pointerdown.stop="editDown($event, 'sw')"></span>
                                        <span class="dashed-media-edit-handle" style="left: 100%; top: 100%; cursor: nwse-resize" x-on:pointerdown.stop="editDown($event, 'se')"></span>
                                    </div>
                                </div>
                            </div>
                            <p class="dashed-media-row-meta" style="margin: 0" x-show="! dialog.ready && ! dialog.failed">{{ __('De afbeelding wordt geladen...') }}</p>
                            <p style="margin: 0; color: var(--dm-danger)" x-show="dialog.failed">{{ __('De afbeelding kon niet worden geladen.') }}</p>
                            <div class="dashed-media-actions">
                                <label class="dashed-media-check">
                                    <span>{{ __('Verhouding') }}</span>
                                    <select class="dashed-media-select" style="width: auto" x-model="dialog.ratio" x-on:change="editRatio()">
                                        <option value="">{{ __('Vrij') }}</option>
                                        <option value="1:1">1:1</option>
                                        <option value="4:3">4:3</option>
                                        <option value="3:2">3:2</option>
                                        <option value="16:9">16:9</option>
                                    </select>
                                </label>
                                <button type="button" class="dashed-media-btn" title="{{ __('Linksom draaien') }}" aria-label="{{ __('Linksom draaien') }}" x-on:click="editRotate(-90)">{!! $icon('heroicon-o-arrow-uturn-left') !!}</button>
                                <button type="button" class="dashed-media-btn" title="{{ __('Rechtsom draaien') }}" aria-label="{{ __('Rechtsom draaien') }}" x-on:click="editRotate(90)">{!! $icon('heroicon-o-arrow-uturn-right') !!}</button>
                                <button type="button" class="dashed-media-btn" x-bind:class="{ 'dashed-media-btn--on': dialog.flipH }" x-bind:aria-pressed="dialog.flipH" x-on:click="editFlip('h')">{!! $icon('heroicon-o-arrows-right-left') !!} {{ __('Spiegelen') }}</button>
                                <button type="button" class="dashed-media-btn" x-bind:class="{ 'dashed-media-btn--on': dialog.flipV }" x-bind:aria-pressed="dialog.flipV" x-on:click="editFlip('v')">{!! $icon('heroicon-o-arrows-up-down') !!} {{ __('Omkeren') }}</button>
                                <button type="button" class="dashed-media-btn" x-on:click="editResetCrop()">{{ __('Hele afbeelding') }}</button>
                            </div>
                            <p class="dashed-media-row-meta" style="margin: 0" aria-live="polite" x-text="editSummary()"></p>
                            <p class="dashed-media-caveat" x-text="editNote()"></p>
                        </div>
                        <div class="dashed-media-dialog-foot">
                            <button type="button" class="dashed-media-btn" x-on:click="closeDialog()">{{ __('Annuleren') }}</button>
                            <button type="button" class="dashed-media-btn" x-show="dialog.hasEdits" x-bind:disabled="busy" x-on:click="revertEdit()">{!! $icon('heroicon-o-arrow-uturn-left') !!} {{ __('Origineel herstellen') }}</button>
                            <button type="submit" class="dashed-media-btn dashed-media-btn--primary" x-bind:disabled="busy || ! dialog.ready">{{ __('Opslaan') }}</button>
                        </div>
                    </form>
                </template>

                <template x-if="dialog.type === 'action'">
                    <form x-on:submit.prevent="confirmAction()" style="display: contents">
                        <div class="dashed-media-dialog-head" x-bind:id="$id('dm-dialog')" x-text="dialog.action.label"></div>
                        <div class="dashed-media-dialog-body">
                            <p style="margin: 0" x-show="dialog.action.confirm" x-text="dialog.action.confirm"></p>
                            <template x-for="field in (dialog.action.fields || [])" x-bind:key="field.name">
                                <label>
                                    <span class="dashed-media-label" x-text="field.label"></span>
                                    <template x-if="field.type === 'text'">
                                        <input type="text" class="dashed-media-input" x-bind:required="field.required" x-model="dialog.data[field.name]">
                                    </template>
                                    <template x-if="field.type === 'textarea'">
                                        <textarea class="dashed-media-textarea" x-bind:required="field.required" x-model="dialog.data[field.name]"></textarea>
                                    </template>
                                    <template x-if="field.type === 'select'">
                                        <select class="dashed-media-select" style="width: 100%" x-bind:required="field.required" x-model="dialog.data[field.name]">
                                            <option value="">{{ __('Kies...') }}</option>
                                            <template x-for="option in field.options" x-bind:key="option.value">
                                                <option x-bind:value="option.value" x-text="option.label"></option>
                                            </template>
                                        </select>
                                    </template>
                                </label>
                            </template>
                        </div>
                        <div class="dashed-media-dialog-foot">
                            <button type="button" class="dashed-media-btn" x-on:click="closeDialog()">{{ __('Annuleren') }}</button>
                            <button type="submit" class="dashed-media-btn dashed-media-btn--primary" x-bind:disabled="busy">{{ __('Uitvoeren') }}</button>
                        </div>
                    </form>
                </template>
            </div>
        </div>
    </template>
</div>
{!! \Dashed\DashedFiles\Classes\MediaHtml::compact((string) ob_get_clean()) !!}
