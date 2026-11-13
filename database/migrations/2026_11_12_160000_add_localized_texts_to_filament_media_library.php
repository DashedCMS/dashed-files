<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    private const TABLE = 'filament_media_library';

    private const COLUMNS = ['alt_text' => 'alt_texts', 'caption' => 'captions', 'title' => 'titles'];

    private const SETTING = 'media_text_mirror_locale';

    private const BATCH = 200;

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        // Tekst met JSON erin en geen json-type: MySQL vergelijkt een
        // json-kolom hoofdlettergevoelig, en zoeken op alt-tekst hoort dat
        // niet te zijn. De oude tekstkolommen blijven staan als spiegel van
        // de spiegeltaal.
        foreach (self::COLUMNS as $source => $column) {
            if (! Schema::hasColumn(self::TABLE, $column)) {
                Schema::table(self::TABLE, fn (Blueprint $table) => $table->text($column)->nullable());
            }
        }

        $locale = $this->mirrorLocale();
        $max = (int) DB::table(self::TABLE)->max('id');

        for ($from = 1; $from <= $max; $from += 2000) {
            foreach (self::COLUMNS as $source => $column) {
                if (Schema::hasColumn(self::TABLE, $source)) {
                    $this->fill($source, $column, $locale, $from, $from + 1999);
                }
            }
        }
    }

    public function down(): void
    {
        // De kolommen blijven staan: terugdraaien mag geen vertalingen kosten.
    }

    /**
     * Idempotent: wat al vertalingen heeft blijft staan, een lege tekst
     * levert niets op (de kolom blijft null). Meerdere rijen per query.
     */
    private function fill(string $source, string $column, string $locale, int $from, int $to): void
    {
        $rows = DB::table(self::TABLE)
            ->whereBetween('id', [$from, $to])
            ->whereNull($column)
            ->whereNotNull($source)
            ->where($source, '!=', '')
            ->orderBy('id')
            ->get(['id', $source]);

        $values = [];

        foreach ($rows as $row) {
            if (trim((string) $row->{$source}) === '') {
                continue;
            }

            // Ongeldige UTF-8 (een oude import) wordt vervangen in plaats van
            // dat json_encode false geeft; lukt het dan nog niet, dan blijft
            // de rij leeg. Nooit false (0) wegschrijven.
            $json = json_encode([$locale => (string) $row->{$source}], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if (is_string($json) && $json !== '') {
                $values[(int) $row->id] = $json;
            }
        }

        foreach (array_chunk($values, self::BATCH, true) as $batch) {
            $cases = implode(' ', array_fill(0, count($batch), 'when ? then ?'));
            $bindings = [];

            foreach ($batch as $id => $json) {
                array_push($bindings, $id, $json);
            }

            $ids = array_keys($batch);
            $grammar = DB::connection()->getQueryGrammar();

            DB::update(
                'update ' . $grammar->wrapTable(self::TABLE) . ' set ' . $grammar->wrap($column) . ' = case ' . $grammar->wrap('id') . ' ' . $cases . ' end'
                . ' where ' . $grammar->wrap('id') . ' in (' . implode(', ', array_fill(0, count($ids), '?')) . ') and ' . $grammar->wrap($column) . ' is null',
                array_merge($bindings, $ids),
            );
        }
    }

    /**
     * De taal waar de tekstkolommen de spiegel van zijn. Eén keer bepaald en
     * vastgelegd in een installatiebrede instelling (op de eerste site): een
     * later toegevoegde taal of een gewijzigde volgorde verandert zo niet
     * stilzwijgend onder welke taal de tekstkolom gelezen wordt.
     */
    private function mirrorLocale(): string
    {
        $stored = $this->setting();

        if ($stored !== null) {
            return $stored;
        }

        try {
            $locale = \Dashed\DashedCore\Classes\Locales::getFirstLocale()['id'] ?? null;
        } catch (\Throwable) {
            $locale = null;
        }

        if (! is_string($locale) || $locale === '') {
            // De talen zijn tijdens een migratie niet altijd te lezen (een
            // kale installatie zonder sites): dan de taal van de applicatie.
            // Niet vastgelegd: de eerste aanroep met leesbare talen doet dat.
            $locale = (string) config('app.locale', 'nl');

            Log::warning('Media texts migration: the locales of the sites could not be read, used the application locale [' . $locale . '] as mirror locale for existing alt texts, captions and titles. Check it with dashed:media:doctor; switch with dashed:media-texts --mirror=<locale>.');

            return $locale;
        }

        try {
            \Dashed\DashedCore\Models\Customsetting::set(self::SETTING, $locale, (string) \Dashed\DashedCore\Classes\Sites::getFirstSite()['id']);
        } catch (\Throwable $exception) {
            Log::warning('Media texts migration: could not store the mirror locale [' . $locale . ']: ' . $exception->getMessage());
        }

        return $locale;
    }

    private function setting(): ?string
    {
        try {
            $stored = \Dashed\DashedCore\Models\Customsetting::get(self::SETTING, (string) \Dashed\DashedCore\Classes\Sites::getFirstSite()['id']);
        } catch (\Throwable) {
            return null;
        }

        return is_string($stored) && preg_match('/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8})*$/', $stored) ? $stored : null;
    }
};
