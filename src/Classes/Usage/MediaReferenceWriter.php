<?php

namespace Dashed\DashedFiles\Classes\Usage;

use Illuminate\Database\Eloquent\Model;

/**
 * Bewaart wat de extractor op een model heeft gewijzigd, zonder de
 * model-events af te vuren. Een `save()` op een product start zijn
 * feed, zijn zoekindex en zijn webhooks opnieuw; voor "hetzelfde plaatje,
 * een ander id of adres" is dat zwaar en onnodig. Alleen de gewijzigde
 * kolommen gaan met een query naar de database, met `updated_at` erbij
 * zodat caches met die waarde in de sleutel verversen.
 */
class MediaReferenceWriter
{
    // Deze modellen bewaren hun waarde ook in een eigen cache die alleen hun
    // saved-haak leegt, en hebben geen zware nabewerking: die gaan via het model.
    private const THROUGH_MODEL = [
        'Dashed\\DashedCore\\Models\\Customsetting',
        'Dashed\\DashedTranslations\\Models\\Translation',
    ];

    /**
     * @return bool of er iets bewaard is
     */
    public function persist(Model $model): bool
    {
        if (! $model->exists || ! $model->isDirty()) {
            return false;
        }

        foreach (self::THROUGH_MODEL as $class) {
            if (is_a($model, $class)) {
                return (bool) $model->save();
            }
        }

        $dirty = $model->getDirty();

        if ($model->usesTimestamps() && $model->getUpdatedAtColumn()) {
            $dirty[$model->getUpdatedAtColumn()] = $model->freshTimestampString();
        }

        $model->getConnection()->table($model->getTable())->where($model->getKeyName(), $model->getKey())->update($dirty);
        $model->syncOriginal();

        return true;
    }
}
