<?php

namespace Dashed\DashedFiles\Models\Scopes;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Dashed\DashedFiles\Classes\Browse\BrowseSchema;

/**
 * De gewone prullenbak-scope, maar pas zodra de kolom er is. De code kan
 * live staan voordat de migratie gedraaid heeft, en zonder deze toets zou
 * dan elke query op de mediabibliotheek (ook die van de site) op een
 * ontbrekende kolom stuklopen.
 */
class MediaTrashScope extends SoftDeletingScope
{
    public function apply(Builder $builder, Model $model)
    {
        if (BrowseSchema::hasTrash()) {
            parent::apply($builder, $model);
        }
    }
}
