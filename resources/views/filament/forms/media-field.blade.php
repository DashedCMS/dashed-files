{{--
    Het mediaveld: de gekozen items als tegels, door de server getekend
    (bestandsnamen en alt-teksten gaan dus altijd door de escaping van Blade).
    De knoppen roepen window.dashedMedia.field aan; bestaat die niet (het veld
    staat buiten het paneel), dan blijft het veld alleen-lezen.
--}}
@php
    $items = $getViewItems();
    $external = $getExternalValue();
    $externalIsUrl = \Dashed\DashedFiles\Filament\Forms\Components\MediaField::isExternalUrl($external);
    $multiple = $isMultiple();
    // Zonder het recht view_media is het veld alleen-lezen: de keuze met
    // voorvertoning en naam, zonder kiezen, uploaden of weghalen. Anders dan
    // een uitgeschakeld veld blijft de waarde bij opslaan wel behouden.
    $readOnly = ! $canBrowse();
    $disabled = $isDisabled() || $readOnly;
    $max = $getMaxFiles();
    $sortable = $multiple && ! $disabled && $isReorderable() && count($items) > 1;
    $full = $multiple ? ($max !== null && count($items) >= $max) : (count($items) > 0 || $external !== null);
    $openable = $isDownloadable();
    $fieldKey = $getKey();
    $icon = fn (string $name, string $class = 'dashed-media-field-icon') => rescue(fn () => svg($name, $class, ['aria-hidden' => 'true'])->toHtml(), '', false);
    $safeUrl = fn (?string $url): ?string => is_string($url) && preg_match('#^(https?://|/)#i', $url) ? $url : null;
    $api = 'window.dashedMedia.field';
    $label = $getLabel();
    $ariaLabel = trim(strip_tags((string) ($label instanceof \Illuminate\Contracts\Support\Htmlable ? $label->toHtml() : $label)));
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    {{-- De opmaak staat niet hier maar in de render hook (media.picker-host): een stijlblok in het veld verdwijnt bij een gedeeltelijke render uit de pagina. --}}
    <div
        {{ $getExtraAttributeBag()->class(['dashed-media-field', 'dashed-media-field--multiple' => $multiple, 'dashed-media-field--disabled' => $disabled]) }}
        role="group"
        aria-label="{{ $ariaLabel }}"
        @unless ($disabled)
            {{-- Focusbaar, zodat plakken ook werkt zonder dat de focus op een knop staat. --}}
            tabindex="0"
        @endunless
        data-dashed-media-field="{{ $getBrowserConfig() }}"
        x-data="{ ready: false, over: false, alt: null }"
        x-init="ready = !! (window.dashedMedia && window.dashedMedia.pick && window.dashedMedia.field)"
        x-bind:class="{ 'dashed-media-field--over': over }"
        @unless ($disabled)
            x-on:dragover="over = ready && {{ $api }}.fileOver($event, $el)"
            x-on:dragleave="over = ready && {{ $api }}.fileLeave($event, $el)"
            x-on:drop="over = false; if (ready) { {{ $api }}.fileDrop($event, $el) }"
            x-on:paste="if (ready) { {{ $api }}.paste($event, $el) }"
        @endunless
    >
        @if ($external !== null)
            <div @class(['dashed-media-field-item', 'dashed-media-field-item--missing' => ! $externalIsUrl])>
                <div class="dashed-media-field-thumb">{!! $icon($externalIsUrl ? 'heroicon-o-link' : 'heroicon-o-exclamation-triangle', 'dashed-media-field-icon dashed-media-field-icon--lg') !!}</div>
                <div class="dashed-media-field-body">
                    <div class="dashed-media-field-name">{{ $externalIsUrl ? __('Extern adres') : __('Ongeldige waarde') }}</div>
                    <div class="dashed-media-field-meta" title="{{ $external }}">{{ $external }}</div>
                    @unless ($disabled)
                        <div class="dashed-media-field-actions" x-show="ready" x-cloak>
                            <button type="button" class="dashed-media-field-btn" x-on:click="{{ $api }}.pick($el)">{!! $icon('heroicon-m-arrows-right-left') !!} {{ __('Vervangen') }}</button>
                            @if ($externalIsUrl)
                                <a class="dashed-media-field-btn dashed-media-field-btn--icon" href="{{ $external }}" target="_blank" rel="noopener noreferrer" title="{{ __('Openen in een nieuw tabblad') }}" aria-label="{{ __('Openen in een nieuw tabblad') }}">{!! $icon('heroicon-m-arrow-top-right-on-square') !!}</a>
                            @endif
                            <button type="button" class="dashed-media-field-btn dashed-media-field-btn--icon dashed-media-field-btn--danger" title="{{ __('Verwijderen') }}" aria-label="{{ __('Verwijderen') }}" x-on:click="{{ $api }}.clear($el)">{!! $icon('heroicon-m-trash') !!}</button>
                        </div>
                    @endunless
                </div>
            </div>
        @elseif ($items !== [])
            <ul class="dashed-media-field-list" role="list">
                @foreach ($items as $index => $item)
                    <li
                        wire:key="{{ $fieldKey }}.media.{{ $item['id'] }}"
                        @class(['dashed-media-field-item', 'dashed-media-field-item--missing' => $item['missing']])
                        data-media-id="{{ $item['id'] }}"
                        @if ($sortable)
                            draggable="true"
                            x-on:dragstart="{{ $api }}.sortStart($event, {{ $item['id'] }})"
                            x-on:dragover="{{ $api }}.sortOver($event)"
                            x-on:drop="{{ $api }}.sortDrop($event, {{ $item['id'] }})"
                        @endif
                    >
                        @if ($item['missing'])
                            <div class="dashed-media-field-thumb">{!! $icon('heroicon-o-question-mark-circle', 'dashed-media-field-icon dashed-media-field-icon--lg') !!}</div>
                            <div class="dashed-media-field-body">
                                <div class="dashed-media-field-name">{{ __('Bestand niet gevonden') }}</div>
                                <div class="dashed-media-field-meta">#{{ $item['id'] }}</div>
                                @unless ($disabled)
                                    <div class="dashed-media-field-actions" x-show="ready" x-cloak>
                                        @unless ($multiple)
                                            <button type="button" class="dashed-media-field-btn" x-on:click="{{ $api }}.pick($el)">{!! $icon('heroicon-m-arrows-right-left') !!} {{ __('Vervangen') }}</button>
                                        @endunless
                                        <button type="button" class="dashed-media-field-btn dashed-media-field-btn--icon dashed-media-field-btn--danger" title="{{ __('Verwijderen') }}" aria-label="{{ __('Verwijderen') }}" x-on:click="{{ $api }}.remove($el, {{ $item['id'] }})">{!! $icon('heroicon-m-trash') !!}</button>
                                    </div>
                                @endunless
                            </div>
                        @else
                            <div class="dashed-media-field-thumb">
                                @if ($safeUrl($item['thumb']))
                                    {{-- 72 px: de beheerminiatuur (320) is ruim genoeg, ook op een scherm met dubbele dichtheid. --}}
                                    @php($small = $safeUrl($item['thumb_small'] ?? null))
                                    <img src="{{ $small ?: $safeUrl($item['thumb']) }}"@if ($small) width="{{ \Dashed\DashedFiles\Classes\ConversionDefinition::adminThumb()->width }}" height="{{ \Dashed\DashedFiles\Classes\ConversionDefinition::adminThumb()->height }}"@endif alt="{{ $item['alt'] ?? '' }}" loading="lazy" decoding="async" draggable="false">
                                    {{-- Fase 9b: de poster van een video of de miniatuur van een pdf, met een markering. --}}
                                    @if ($item['type'] === 'video')
                                        <span class="dashed-media-field-kind" aria-hidden="true">{!! $icon('heroicon-s-play', 'dashed-media-field-icon') !!}</span>
                                    @elseif (($item['mime'] ?? null) === 'application/pdf')
                                        <span class="dashed-media-field-kind dashed-media-field-kind--pdf" aria-hidden="true">PDF</span>
                                    @endif
                                @else
                                    {!! $icon(match ($item['type']) { 'video' => 'heroicon-o-film', 'image', 'svg' => 'heroicon-o-photo', default => 'heroicon-o-document-text' }, 'dashed-media-field-icon dashed-media-field-icon--lg') !!}
                                    <span class="dashed-media-field-ext">{{ pathinfo($item['file_name'], PATHINFO_EXTENSION) }}</span>
                                @endif
                                @if ($item['private'] ?? false)
                                    <span class="dashed-media-field-lock" title="{{ __('Dit bestand staat in een privé-map: de site kan het niet tonen.') }}">{!! $icon('heroicon-s-lock-closed') !!}<span class="dashed-media-field-sr">{{ __('Privé') }}</span></span>
                                @endif
                                @if ($item['trashed'])
                                    <span class="dashed-media-field-flag dashed-media-field-flag--trash" title="{{ __('Dit bestand ligt in de prullenbak van de mediabibliotheek. Op de site blijft het zichtbaar tot het definitief wordt verwijderd.') }}">{{ __('In prullenbak') }}</span>
                                @elseif ($item['needsAlt'])
                                    <span class="dashed-media-field-flag" title="{{ ($item['altLocale'] ?? null) ? __('Geen alt-tekst in: :locales', ['locales' => strtoupper($item['altLocale'])]) : __('Geen alt-tekst') }}">{{ __('Geen alt') }}@if ($item['altLocale'] ?? null) {{ strtoupper($item['altLocale']) }}@endif</span>
                                @endif
                            </div>
                            <div class="dashed-media-field-body">
                                <div class="dashed-media-field-name" title="{{ $item['name'] }}">{{ $item['name'] }}</div>
                                @if ($item['hasAlt'])
                                    <div class="dashed-media-field-meta" x-show="alt !== {{ $item['id'] }}">{{ filled($item['alt']) ? $item['alt'] : __('Geen alt-tekst') }}</div>
                                @endif

                                @unless ($disabled)
                                    @if ($item['hasAlt'])
                                        <div class="dashed-media-field-alt" x-show="alt === {{ $item['id'] }}" x-cloak>
                                            <label class="dashed-media-field-sr" for="{{ $getId() }}-alt-{{ $item['id'] }}">{{ __('Alt-tekst') }}</label>
                                            @if ($item['altLocale'] ?? null)
                                                <span class="dashed-media-field-locale" title="{{ __('De alt-tekst in deze taal') }}">{{ strtoupper($item['altLocale']) }}</span>
                                            @endif
                                            <input
                                                type="text"
                                                id="{{ $getId() }}-alt-{{ $item['id'] }}"
                                                maxlength="{{ \Dashed\DashedFiles\Filament\Forms\Components\MediaField::ALT_MAX }}"
                                                value="{{ $item['alt'] ?? '' }}"
                                                placeholder="{{ __('Beschrijf wat er op de afbeelding te zien is') }}"
                                                x-on:keydown.enter.prevent="{{ $api }}.saveAlt($el, {{ $item['id'] }}, $el.value); alt = null"
                                                x-on:keydown.escape.stop.prevent="alt = null"
                                            >
                                            <button type="button" class="dashed-media-field-btn" x-on:click="{{ $api }}.saveAlt($el, {{ $item['id'] }}, $el.previousElementSibling.value); alt = null">{{ __('Opslaan') }}</button>
                                        </div>
                                    @endif

                                    <div class="dashed-media-field-actions" x-show="ready" x-cloak>
                                        @if ($item['trashed'])
                                            <button type="button" class="dashed-media-field-btn dashed-media-field-btn--primary" data-media-field-restore="{{ $item['id'] }}" x-on:click="{{ $api }}.restore($el, {{ $item['id'] }})">{!! $icon('heroicon-m-arrow-uturn-left') !!} {{ __('Terugzetten') }}</button>
                                        @endif
                                        @unless ($multiple)
                                            <button type="button" class="dashed-media-field-btn" x-on:click="{{ $api }}.pick($el)">{!! $icon('heroicon-m-arrows-right-left') !!} {{ __('Vervangen') }}</button>
                                        @endunless
                                        @if ($sortable)
                                            <button type="button" class="dashed-media-field-btn dashed-media-field-btn--icon" title="{{ __('Naar voren') }}" aria-label="{{ __('Naar voren') }}" @disabled($loop->first) x-on:click="{{ $api }}.move($el, {{ $item['id'] }}, -1)">{!! $icon('heroicon-m-arrow-left') !!}</button>
                                            <button type="button" class="dashed-media-field-btn dashed-media-field-btn--icon" title="{{ __('Naar achteren') }}" aria-label="{{ __('Naar achteren') }}" @disabled($loop->last) x-on:click="{{ $api }}.move($el, {{ $item['id'] }}, 1)">{!! $icon('heroicon-m-arrow-right') !!}</button>
                                        @endif
                                        @if ($item['hasAlt'])
                                            <button type="button" class="dashed-media-field-btn dashed-media-field-btn--icon" title="{{ __('Alt-tekst bewerken') }}" aria-label="{{ __('Alt-tekst bewerken') }}" x-on:click="alt = alt === {{ $item['id'] }} ? null : {{ $item['id'] }}; $nextTick(() => document.getElementById(@js($getId() . '-alt-' . $item['id']))?.focus())">{!! $icon('heroicon-m-pencil-square') !!}</button>
                                        @endif
                                        @if ($openable && $safeUrl($item['url']))
                                            <a class="dashed-media-field-btn dashed-media-field-btn--icon" href="{{ $safeUrl($item['url']) }}" target="_blank" rel="noopener noreferrer" title="{{ __('Openen in een nieuw tabblad') }}" aria-label="{{ __('Openen in een nieuw tabblad') }}">{!! $icon('heroicon-m-arrow-top-right-on-square') !!}</a>
                                        @endif
                                        {{-- Het bestand zelf vervangen gebeurt op één plek: de mediabibliotheek, met dit item open. "Vervangen" hierboven kiest een ander item. --}}
                                        @if (! $item['trashed'] && $safeUrl($item['manageUrl']))
                                            <a class="dashed-media-field-btn dashed-media-field-btn--icon" href="{{ $safeUrl($item['manageUrl']) }}" target="_blank" rel="noopener" title="{{ __('Bestand bijwerken') }}" aria-label="{{ __('Bestand bijwerken') }}">{!! $icon('heroicon-m-arrow-path') !!}</a>
                                        @endif
                                        <button type="button" class="dashed-media-field-btn dashed-media-field-btn--icon dashed-media-field-btn--danger" title="{{ __('Verwijderen') }}" aria-label="{{ __('Verwijderen') }}" x-on:click="{{ $api }}.remove($el, {{ $item['id'] }})">{!! $icon('heroicon-m-trash') !!}</button>
                                    </div>
                                @endunless
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($disabled)
            @if ($items === [] && $external === null)
                <div class="dashed-media-field-empty">{{ __('Geen bestand gekozen') }}</div>
            @endif
            @if ($readOnly && ! $isDisabled())
                <p class="dashed-media-field-status" data-dashed-media-readonly>{{ __('Je hebt geen recht om de mediabibliotheek te gebruiken; dit veld is alleen-lezen.') }}</p>
            @endif
        @else
            @unless ($full)
                <div @class(['dashed-media-field-empty' => $items === [], 'dashed-media-field-foot' => $items !== []]) x-show="ready" x-cloak>
                    <button type="button" class="dashed-media-field-btn dashed-media-field-btn--primary" x-on:click="{{ $api }}.pick($el)">
                        {!! $icon('heroicon-m-photo') !!} {{ $items === [] ? __('Kies of upload') : __('Meer toevoegen') }}
                    </button>
                    <span>{{ __('Je kunt ook een bestand hierheen slepen.') }}</span>
                </div>
            @endunless

            {{-- Zonder het script van het paneel zijn de knoppen dood: dan liever een uitleg. --}}
            <div class="dashed-media-field-empty" x-show="! ready" x-cloak>
                {{ __('Kiezen kan hier niet: de mediabibliotheek is op deze pagina niet geladen.') }}
            </div>

            <p class="dashed-media-field-status" data-dashed-media-status role="status" aria-live="polite"></p>
        @endif
    </div>
</x-dynamic-component>
