<?php

declare(strict_types=1);

namespace Dashed\DashedFiles\Retention;

use Throwable;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Dashed\DashedCore\Retention\Termijn;
use Dashed\DashedCore\Retention\Contracts\Opruimer;
use Dashed\DashedFiles\Filament\Actions\AiGenerateImageAction;

/**
 * Ruimt de referentieafbeeldingen op die bij "Genereer met AI" zijn
 * geüpload en zijn blijven staan (`ai-reference-temp` op de publieke
 * schijf). De actie haalt ze na elke aanroep zelf weg; dit is het vangnet
 * voor een modal die is weggeklikt of een verzoek dat halverwege stopte.
 * Geen tabel: het zijn bestanden, opgeruimd op hun wijzigingsdatum.
 */
class AiReferentieOpruimer implements Opruimer
{
    public function ruimOp(Termijn $termijn, int $portie, bool $droog): int
    {
        try {
            $disk = Storage::disk(AiGenerateImageAction::REFERENCE_DISK);
            $files = $disk->files(AiGenerateImageAction::REFERENCE_DIRECTORY);
        } catch (Throwable) {
            // Geen publieke schijf in dit project: niets op te ruimen.
            return 0;
        }

        // Een referentie is alleen nodig tijdens de ene aanroep: wat na een
        // uur (dashed-files.source_limits.ai_reference_minutes) nog staat, is
        // blijven hangen. De termijn in dagen is alleen nog de bovengrens.
        $minuten = config('dashed-files.source_limits.ai_reference_minutes');
        $minuten = is_numeric($minuten) && (int) $minuten >= 5 ? (int) $minuten : 60;
        $grens = Carbon::now()->subMinutes(min($minuten, max(1, $termijn->dagen()) * 1440))->getTimestamp();
        $aantal = 0;

        foreach ($files as $path) {
            $changed = rescue(fn () => $disk->lastModified($path), null, false);

            if (! is_int($changed) || $changed >= $grens) {
                continue;
            }

            if ($droog || rescue(fn () => $disk->delete($path), false, false)) {
                $aantal++;
            }
        }

        return $aantal;
    }
}
