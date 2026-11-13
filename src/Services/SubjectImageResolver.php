<?php

namespace Dashed\DashedFiles\Services;

use Spatie\MediaLibrary\HasMedia;
use Illuminate\Database\Eloquent\Model;
use Dashed\DashedFiles\Models\MediaItem;
use Dashed\DashedFiles\Classes\MediaPrivacy;
use Dashed\DashedFiles\Classes\Access\MediaStorage;

class SubjectImageResolver
{
    /**
     * Return a list of public image URLs for a visitable model.
     * Keys and values are both URLs so Filament Select can render them directly.
     *
     * @return array<string, string>
     */
    public function collect(?Model $model): array
    {
        if (! $model) {
            return [];
        }

        $urls = array_merge(
            $this->fromImagesAttribute($model),
            $this->fromImageAttribute($model),
            $this->fromSpatieMediaLibrary($model),
        );

        $urls = array_values(array_unique(array_filter($urls)));

        return array_combine($urls, $urls) ?: [];
    }

    private function fromImagesAttribute(Model $model): array
    {
        if (! array_key_exists('images', $model->getAttributes()) && ! $model->hasCast('images')) {
            return [];
        }

        $raw = $model->images ?? null;
        if (! is_array($raw) || empty($raw)) {
            return [];
        }

        return array_filter(array_map(fn ($id) => $this->resolveUrl($id), $raw));
    }

    private function fromImageAttribute(Model $model): array
    {
        if (! array_key_exists('image', $model->getAttributes())) {
            return [];
        }

        $url = $this->resolveUrl($model->image);

        return $url ? [$url] : [];
    }

    private function fromSpatieMediaLibrary(Model $model): array
    {
        if (! interface_exists(HasMedia::class) || ! $model instanceof HasMedia) {
            return [];
        }

        // Een privé of beschermd item van de mediabibliotheek, of wat er ook
        // op de privé-schijf staat, gaat niet als adres naar een externe dienst.
        if ($model instanceof MediaItem && rescue(fn () => MediaPrivacy::isProtected($model), true, false)) {
            return [];
        }

        return $model->getMedia()
            ->reject(fn ($media) => MediaStorage::isPrivateDisk($media->disk))
            ->map(fn ($media) => $media->getFullUrl())
            ->filter()
            ->values()
            ->all();
    }

    private function resolveUrl(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        if (is_array($value)) {
            $value = $value[0] ?? null;
            if (! $value) {
                return null;
            }
        }

        // Een geplakt adres mag, behalve als het naar een beschermd bestand
        // van de eigen bibliotheek wijst.
        if (is_string($value) && ! ctype_digit($value)) {
            return MediaPrivacy::isProtectedUrl($value) ? null : $value;
        }

        if ($this->isProtectedMediaId($value)) {
            return null;
        }

        if (function_exists('mediaHelper')) {
            $resolved = mediaHelper()->getSingleMedia($value, 'original');

            if (is_string($resolved) && $resolved !== '') {
                return $resolved;
            }

            if (is_object($resolved) && isset($resolved->url)) {
                return $resolved->url;
            }
        }

        return null;
    }

    /**
     * Een media-id waarvan MediaPrivacy zegt dat hij niet naar een externe
     * AI mag (privé, chatbijlage, map chat of orders). Kan de toets niet
     * gedaan worden, dan geldt het item als beschermd.
     */
    private function isProtectedMediaId(mixed $value): bool
    {
        if (! is_numeric($value)) {
            return false;
        }

        return (bool) rescue(function () use ($value) {
            $item = MediaItem::withoutGlobalScopes()->find((int) $value);

            return $item !== null && MediaPrivacy::isProtected($item);
        }, true, false);
    }
}
