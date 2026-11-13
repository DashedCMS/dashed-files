{{--
    Een SVG uit de bibliotheek inline in de pagina, zodat hij met CSS te
    kleuren is (currentColor) en geen extra verzoek kost:

        <x-dashed-files::svg :media-id="$logo" class="h-8 w-auto" aria-label="Logo" />

    Alleen voor een svg die bij de inname is opgeschoond en uit het beheer of
    uit code komt; de inhoud gaat bij het tekenen nogmaals door de opschoner
    (profiel inline). Voldoet het item daar niet aan, dan staat hier een
    gewone img via het beeldcomponent. Alleen class, width, height, role,
    aria-label en aria-hidden komen op het svg-element.
--}}
@props([
    'mediaId' => null,
    'idPrefix' => null,
])
@php
    // Blade levert de waarde van een attribuut al als html aan (ge-escapet).
    // De opschoner schrijft via de DOM en escapet zelf: eerst terug naar
    // platte tekst, anders staat er een dubbel ge-escapete waarde.
    $svgAttributes = array_map(
        fn ($value) => is_string($value) ? html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $value,
        $attributes->getAttributes(),
    );
    $inlineSvg = $mediaId
        ? app(\Dashed\DashedFiles\Classes\Svg\InlineSvg::class)->render($mediaId, $svgAttributes, $idPrefix)
        : null;
@endphp
@if ($inlineSvg !== null)
{!! $inlineSvg !!}
@elseif ($mediaId)
<x-dashed-files::image :mediaId="$mediaId" :alt="(string) ($svgAttributes['aria-label'] ?? '')" :attributes="$attributes->only(['class', 'width', 'height', 'loading'])" />
@endif
