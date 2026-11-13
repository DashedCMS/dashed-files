<?php

declare(strict_types=1);

namespace Dashed\DashedFiles\Retention;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Dashed\DashedCore\Retention\Termijn;
use Dashed\DashedFiles\Models\MediaItem;
use Dashed\DashedFiles\Models\MediaFolder;
use Dashed\DashedFiles\Classes\MediaLibrary;
use Dashed\DashedCore\Retention\Contracts\Opruimer;
use Dashed\DashedFiles\Classes\Browse\BrowseSchema;
use Dashed\DashedFiles\Classes\Browse\MediaTrashOrigin;

/**
 * Leegt de prullenbak van de mediabibliotheek, maar alleen als dat bewust is
 * aangezet (`dashed-files.trash.auto_purge`, standaard uit) en de eigen
 * motor draait. De lijst "waar gebruikt" kent alleen de aangemelde velden;
 * een bestand dat in een eigen veld van een project staat lijkt ongebruikt
 * en zou zonder die schakelaar na dertig dagen stilletjes van de schijf
 * verdwijnen.
 *
 * Ook als het aanstaat blijft staan: wat nog een verwijzing in de index
 * heeft, en wat sinds het weggooien nog door de site is opgevraagd
 * (`trashed_served_at`). Via het model, zodat het bestand meegaat.
 *
 * Los van die schakelaar: wat CODE weggooide (`trashed_via = code`, een taak
 * of commando zonder paneelsessie) gaat na
 * `dashed-files.trash.code_deleted_purge_days` (standaard 30) alsnog weg.
 * Het oude pakket verwijderde zo'n item meteen definitief, en code die
 * daarop leunt (autoxl gooit elke nacht zijn vervangen afbeeldingen weg)
 * liet anders een prullenbak achter die alleen maar groeit. Dit is veiliger
 * dan dat oude gedrag: het item blijft eerst een maand terug te zetten, en
 * het gaat pas weg als de site het sinds het weggooien niet meer opvroeg,
 * de index geen verwijzing kent, het niet bij een samenvoeging hoort en
 * het geen poster van een ander item is. Het mag terwijl `auto_purge`
 * uitstaat, omdat die schakelaar beschermt tegen een beheerder die iets
 * weggooit wat in een onbekend veld nog gebruikt wordt; hier heeft de code
 * van het project zelf gezegd dat het item weg moet, en die code rekende
 * op een definitieve verwijdering. Rijen van vóór de kolom hebben geen
 * herkomst en vallen hier nooit onder.
 */
class MediaPrullenbakOpruimer implements Opruimer
{
    public function ruimOp(Termijn $termijn, int $portie, bool $droog): int
    {
        if (! MediaLibrary::usesOwnEngine() || ! BrowseSchema::hasTrash() || ! BrowseSchema::hasServed() || ! BrowseSchema::hasUsages()) {
            return 0;
        }

        $auto = MediaLibrary::trashAutoPurge();
        $codeDays = BrowseSchema::hasTrashOrigin() ? MediaTrashOrigin::codePurgeDays() : null;

        if (! $auto && $codeDays === null) {
            return 0;
        }

        $grens = Carbon::now()->subDays($termijn->dagen());
        $table = (new MediaItem())->getTable();

        // Zonder de bestandsscope: ook een item zonder bestand mag weg.
        // Verlopen: de algemene termijn (alleen met auto_purge), of de
        // termijn van wat code weggooide.
        $expired = fn () => MediaItem::withoutGlobalScope(MediaItem::SCOPE_WITH_FILE)->onlyTrashed()
            ->where(function ($query) use ($auto, $codeDays, $grens, $table) {
                if ($auto) {
                    $query->orWhere($table . '.deleted_at', '<', $grens);
                }

                if ($codeDays !== null) {
                    $query->orWhere(fn ($code) => $code->where($table . '.trashed_via', MediaTrashOrigin::CODE)
                        ->where($table . '.deleted_at', '<', Carbon::now()->subDays($codeDays))
                        // Een item uit een samenvoeging blijft: undoMerge() heeft het nodig.
                        ->when(BrowseSchema::hasMerges(), fn ($merged) => $merged->whereNotExists(fn ($merge) => $merge->selectRaw('1')->from(BrowseSchema::MERGES)->whereColumn(BrowseSchema::MERGES . '.merged_item_id', $table . '.id'))));
                }
            });
        $eligible = fn () => $expired()
            ->whereNull($table . '.trashed_served_at')
            ->whereNotExists(fn ($usage) => $usage->selectRaw('1')->from(BrowseSchema::USAGES)->whereColumn(BrowseSchema::USAGES . '.media_item_id', $table . '.id'))
            // Een afbeelding die nog als poster van een video of pdf dient blijft ook staan.
            ->when(BrowseSchema::hasPreview(), fn ($query) => $query->whereNotExists(fn ($poster) => \Dashed\DashedFiles\Classes\Usage\MediaUsages::posterUseExists($poster, $table)));

        $skipped = $expired()->count() - $eligible()->count();

        if ($skipped > 0) {
            Log::info('Media trash: ' . $skipped . ' expired item(s) kept because they are still referenced or were still served by the site.');
        }

        // Mappen alleen met auto_purge: code gooit items weg, geen mappen.
        $folders = fn () => MediaFolder::onlyTrashed()
            ->when(! $auto, fn ($query) => $query->whereRaw('1 = 0'))
            ->where('deleted_at', '<', $grens)
            // Een map gaat pas weg als er niets meer in staat, ook niets in de prullenbak.
            ->whereNotExists(fn ($item) => $item->selectRaw('1')->from($table)->whereColumn($table . '.folder_id', 'filament_media_library_folders.id'))
            ->whereNotExists(fn ($child) => $child->selectRaw('1')->from('filament_media_library_folders as c')->whereColumn('c.parent_id', 'filament_media_library_folders.id'));

        if ($droog) {
            return $eligible()->count() + $folders()->count();
        }

        $aantal = 0;

        $eligible()->chunkById(max(1, $portie), function ($items) use (&$aantal) {
            foreach ($items as $item) {
                $item->forceDelete();
                $aantal++;
            }
        }, $table . '.id', 'id');

        // Na de items: folder_id is een harde foreign key zonder cascade. Een
        // paar rondes, zodat een lege submap eerst gaat en zijn ouder daarna.
        for ($ronde = 0; $ronde < 10; $ronde++) {
            $gevonden = $folders()->limit(max(1, $portie))->get();

            if ($gevonden->isEmpty()) {
                break;
            }

            foreach ($gevonden as $folder) {
                $folder->forceDelete();
                $aantal++;
            }
        }

        return $aantal;
    }
}
