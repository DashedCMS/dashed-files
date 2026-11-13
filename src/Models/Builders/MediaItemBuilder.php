<?php

namespace Dashed\DashedFiles\Models\Builders;

use Dashed\DashedFiles\Models\MediaItem;
use Illuminate\Database\Eloquent\Builder;

/**
 * De query-builder van MediaItem. Hij doet één ding extra: de oude naam van
 * de "heeft bestand"-scope (`has_media`, zo heette hij op het pakketmodel)
 * vertalen naar de eigen naam. Klantcode die van het oude pakket komt roept
 * `withoutGlobalScope('has_media')` aan; zonder deze vertaling zou dat stil
 * niets doen en bleven items zonder bestand onzichtbaar.
 *
 * De eigen scope houdt bewust zijn eigen naam: hij telt alleen een bestand in
 * de collectie `library`, het pakketmodel elk bestand. Op een gewoon item is
 * dat hetzelfde.
 */
class MediaItemBuilder extends Builder
{
    public function withoutGlobalScope($scope)
    {
        return parent::withoutGlobalScope($scope === MediaItem::LEGACY_SCOPE_WITH_FILE ? MediaItem::SCOPE_WITH_FILE : $scope);
    }

    public function withoutGlobalScopes(?array $scopes = null)
    {
        if (is_array($scopes)) {
            $scopes = array_map(fn ($scope) => $scope === MediaItem::LEGACY_SCOPE_WITH_FILE ? MediaItem::SCOPE_WITH_FILE : $scope, $scopes);
        }

        return parent::withoutGlobalScopes($scopes);
    }
}
