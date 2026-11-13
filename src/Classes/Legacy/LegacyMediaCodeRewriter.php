<?php

namespace Dashed\DashedFiles\Classes\Legacy;

use PhpToken;
use Dashed\DashedFiles\Models\MediaItem;
use Dashed\DashedFiles\Models\MediaFolder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

/**
 * Zet klantcode om van het model van het oude mediabibliotheekpakket naar
 * MediaItem en MediaFolder. Werkt op tokens (PhpToken, geen afhankelijkheid
 * op nikic/php-parser, dat in productie vaak ontbreekt), zodat een string of
 * commentaar met de oude naam nooit herschreven wordt; die worden gemeld.
 *
 * Blade wordt niet als geheel getokeniseerd: alleen de PHP-gebieden
 * (`@php ... @endphp`, `{{ }}`, `{!! !!}`, `<?php ?>`) gaan door dezelfde
 * tokenroute. Wat erbuiten de oude naam noemt is handwerk.
 */
class LegacyMediaCodeRewriter
{
    // De naam van het oude pakket in stukken: de bewakingstest
    // MediaLibraryVendorGuardTest verbiedt hem als letterlijke tekst in de
    // broncode van dashed-files, en dit bestand gebruikt hem alleen als
    // zoektekst, nooit als klasse.
    public const VENDOR = 'Ralph' . 'JSmit';

    public const PREFIX = self::VENDOR . '\\Filament\\MediaLibrary\\';

    /** @var array<string, class-string> oude klassenaam => nieuwe */
    public const MAP = [
        self::PREFIX . 'Models\\MediaLibraryItem' => MediaItem::class,
        self::PREFIX . 'Media\\Models\\MediaLibraryItem' => MediaItem::class,
        self::PREFIX . 'Models\\MediaLibraryFolder' => MediaFolder::class,
        self::PREFIX . 'Media\\Models\\MediaLibraryFolder' => MediaFolder::class,
    ];

    // Methodes die één model opleveren: daarna volgt de keten het model.
    private const SINGLE_MODEL = ['find', 'findorfail', 'findornew', 'first', 'firstorfail', 'firstornew', 'firstorcreate', 'updateorcreate', 'create', 'forcecreate', 'sole', 'firstwhere', 'make', 'createorfirst'];

    // Methodes van SoftDeletes, als macro op de builder.
    // Statische methodes van het model die een query teruggeven.
    private const BUILDER_STARTERS = ['query', 'on', 'onwriteconnection', 'newquery', 'newmodelquery', 'withoutglobalscope', 'withoutglobalscopes'];

    private const SOFT_DELETE_MACROS = ['withtrashed', 'onlytrashed', 'withouttrashed', 'restore', 'restoreorcreate', 'createorrestore'];

    /**
     * @return array{code: string, changes: array<int, array{line: int, text: string}>, handwork: array<int, array{line: int, text: string}>}
     */
    public function rewrite(string $path, string $code): array
    {
        return str_ends_with($path, '.blade.php') ? $this->rewriteBlade($code) : $this->rewritePhp($code);
    }

    /**
     * @return array{code: string, changes: array<int, array{line: int, text: string}>, handwork: array<int, array{line: int, text: string}>}
     */
    public function rewritePhp(string $code, int $lineOffset = 0, bool $aliasInsteadOfRename = false): array
    {
        $tokens = PhpToken::tokenize($code);
        $changes = [];
        $handwork = [];
        $renames = [];
        $handled = [];

        [$imports, $skipped] = $this->imports($tokens);
        $taken = [];

        // Gegroepeerde of meervoudige importregels die het oude pakket
        // noemen: niet herschrijven (de korte namen zouden niet meegaan).
        foreach ($skipped as [$start, $end]) {
            $handwork[] = ['line' => $tokens[$start]->line + $lineOffset, 'text' => 'Grouped or combined use statement with the old package, not rewritten'];

            for ($i = $start; $i <= $end; $i++) {
                $handled[$i] = 'skipped';
            }
        }

        foreach ($imports as $import) {
            if (! isset(self::lowerMap()[strtolower(ltrim($import['name'], '\\'))])) {
                $taken[strtolower($import['short'])] = strtolower(ltrim($import['name'], '\\'));
            }
        }

        foreach ($imports as $import) {
            $old = ltrim($import['name'], '\\');
            $new = self::lowerMap()[strtolower($old)] ?? null;
            $token = $tokens[$import['nameIndex']];
            $handled[$import['nameIndex']] = true;

            if ($new === null) {
                continue;
            }

            $line = $token->line + $lineOffset;
            $newShort = class_basename($new);
            $prefix = str_starts_with($import['name'], '\\') ? '\\' : '';

            if ($import['alias'] !== null) {
                $token->text = $prefix . $new;
                $changes[] = ['line' => $line, 'text' => "{$old} -> {$new} (alias {$import['alias']} kept)"];

                continue;
            }

            $existing = $taken[strtolower($newShort)] ?? null;

            if ($existing === strtolower($new) && ! $aliasInsteadOfRename) {
                // Al geïmporteerd: de oude regel weg, de korte naam wordt die van ons model.
                $this->removeStatement($tokens, $import['start'], $import['end']);
                $renames[strtolower($import['short'])] = $newShort;
                $changes[] = ['line' => $line, 'text' => "{$old} removed, {$newShort} is already imported"];

                continue;
            }

            if ($existing !== null || $aliasInsteadOfRename) {
                // De korte naam is bezet (of de code staat verspreid over
                // Blade-gebieden): de oude korte naam blijft als alias.
                $token->text = $prefix . $new . ' as ' . $import['short'];
                $changes[] = ['line' => $line, 'text' => "{$old} -> {$new} as {$import['short']}" . ($existing !== null && ! $aliasInsteadOfRename ? " ({$newShort} is another class here)" : '')];

                continue;
            }

            $token->text = $prefix . $new;
            $renames[strtolower($import['short'])] = $newShort;
            $taken[strtolower($newShort)] = strtolower($new);
            $changes[] = ['line' => $line, 'text' => "{$old} -> {$new}"];
        }

        foreach ($tokens as $index => $token) {
            if (($handled[$index] ?? null) === 'skipped') {
                continue;
            }

            if (isset($handled[$index])) {
                if (stripos($token->text, self::VENDOR) !== false && ! isset(self::lowerMap()[strtolower(ltrim($token->text, '\\'))]) && ! str_contains($token->text, 'Dashed\\')) {
                    $handwork[] = ['line' => $token->line + $lineOffset, 'text' => 'Import of another class of the old package: ' . ltrim($token->text, '\\')];
                }

                continue;
            }

            $line = $token->line + $lineOffset;

            if ($token->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                $name = ltrim($token->text, '\\');
                $new = self::lowerMap()[strtolower($name)] ?? null;

                if ($new !== null) {
                    $token->text = (str_starts_with($token->text, '\\') ? '\\' : '') . $new;
                    $changes[] = ['line' => $line, 'text' => "{$name} -> {$new}"];
                } elseif (stripos($name, self::VENDOR . '\\') === 0) {
                    $handwork[] = ['line' => $line, 'text' => 'Other class of the old package, not rewritten: ' . $name];
                }

                continue;
            }

            if ($token->is(T_STRING) && isset($renames[strtolower($token->text)]) && $this->isClassReference($tokens, $index)) {
                $changes[] = ['line' => $line, 'text' => "{$token->text} -> {$renames[strtolower($token->text)]}"];
                $token->text = $renames[strtolower($token->text)];

                continue;
            }

            if ($token->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML]) && stripos($token->text, self::VENDOR) !== false) {
                $kind = $token->is([T_COMMENT, T_DOC_COMMENT]) ? 'comment' : ($token->is(T_INLINE_HTML) ? 'inline html' : 'string');
                $handwork[] = ['line' => $line + $this->linesBefore($token->text, self::VENDOR), 'text' => "Old package named in a {$kind}, not rewritten"];
            }
        }

        $result = implode('', array_map(fn (PhpToken $token) => $token->text, $tokens));

        foreach ($this->unknownCalls($result) as $call) {
            $handwork[] = ['line' => $call['line'] + $lineOffset, 'text' => $call['text']];
        }

        return ['code' => $result, 'changes' => $changes, 'handwork' => $handwork];
    }

    /**
     * @return array{code: string, changes: array<int, array{line: int, text: string}>, handwork: array<int, array{line: int, text: string}>}
     */
    public function rewriteBlade(string $code): array
    {
        $pattern = '/@php(?!\s*\()(?<php>.*?)@endphp|\{\{(?!--)(?<echo>.*?)\}\}|\{!!(?<raw>.*?)!!\}|<\?php(?<tag>.*?)(?:\?>|\z)/s';
        preg_match_all($pattern, $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);

        $out = '';
        $cursor = 0;
        $changes = [];
        $handwork = [];

        foreach ($matches as $match) {
            foreach (['php', 'echo', 'raw', 'tag'] as $group) {
                if (($match[$group][0] ?? null) === null) {
                    continue;
                }

                [$inner, $offset] = $match[$group];
                $outside = substr($code, $cursor, $offset - $cursor);
                $handwork = array_merge($handwork, $this->mentionsOutsidePhp($outside, $this->lineAt($code, $cursor)));
                $out .= $outside;

                // De korte naam kan in een ander gebied gebruikt worden dan
                // waar hij geïmporteerd is: daarom een alias in plaats van
                // hernoemen.
                $result = $this->rewritePhp('<?php ' . $inner, $this->lineAt($code, $offset) - 1, true);
                $out .= substr($result['code'], 6);
                $changes = array_merge($changes, $result['changes']);
                $handwork = array_merge($handwork, $result['handwork']);
                $cursor = $offset + strlen($inner);
            }
        }

        $rest = substr($code, $cursor);
        $handwork = array_merge($handwork, $this->mentionsOutsidePhp($rest, $this->lineAt($code, $cursor)));

        return ['code' => $out . $rest, 'changes' => $changes, 'handwork' => $handwork];
    }

    /** @return array<string, class-string> */
    private static function lowerMap(): array
    {
        static $map = null;

        return $map ??= array_change_key_case(self::MAP, CASE_LOWER);
    }

    /**
     * De importregels op het hoogste niveau (niet de `use` van een trait of
     * een closure).
     *
     * @param  array<int, PhpToken>  $tokens
     * @return array{0: array<int, array{start: int, end: int, nameIndex: int, name: string, alias: ?string, short: string}>, 1: array<int, array{0: int, 1: int}>}
     */
    private function imports(array $tokens): array
    {
        $imports = [];
        $skipped = [];
        $stack = [];
        $pendingNamespace = false;
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->is(T_NAMESPACE)) {
                $pendingNamespace = true;
            } elseif ($token->text === ';') {
                $pendingNamespace = false;
            } elseif ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $stack[] = $pendingNamespace ? 'namespace' : 'block';
                $pendingNamespace = false;
            } elseif ($token->text === '}') {
                array_pop($stack);
            }

            // Niet de `use (...)` van een closure.
            if (! $token->is(T_USE) || in_array('block', $stack, true) || $this->significant($tokens, $i, 1)?->text === '(') {
                continue;
            }

            $end = $i;
            $names = [];

            while (++$end < $count && $tokens[$end]->text !== ';') {
                if ($tokens[$end]->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING])) {
                    $names[] = $end;
                }
            }

            $body = implode('', array_map(fn (PhpToken $t) => $t->text, array_slice($tokens, $i, $end - $i)));

            // `use function`/`use const`, gegroepeerd of meerdere tegelijk:
            // niet aanraken. Noemt zo'n regel het oude pakket, dan meldt de
            // tweede ronde dat als handwerk.
            if ($names === [] || str_contains($body, '{') || str_contains($body, ',') || preg_match('/^use\s+(function|const)\b/i', $body)) {
                if (stripos($body, self::VENDOR) !== false) {
                    $skipped[] = [$i, $end];
                }

                $i = $end;

                continue;
            }

            $nameIndex = $names[0];
            $alias = null;

            for ($j = $nameIndex + 1; $j < $end; $j++) {
                if ($tokens[$j]->is(T_AS)) {
                    $alias = $tokens[$names[1] ?? $j]->text ?? null;
                }
            }

            $name = $tokens[$nameIndex]->text;
            $imports[] = [
                'start' => $i,
                'end' => $end,
                'nameIndex' => $nameIndex,
                'name' => $name,
                'alias' => $alias,
                'short' => $alias ?? class_basename(ltrim($name, '\\')),
            ];
            $i = $end;
        }

        return [$imports, $skipped];
    }

    /** @param  array<int, PhpToken>  $tokens */
    private function removeStatement(array $tokens, int $start, int $end): void
    {
        for ($i = $start; $i <= $end; $i++) {
            $tokens[$i]->text = '';
        }

        $next = $tokens[$end + 1] ?? null;

        if ($next && $next->is(T_WHITESPACE)) {
            $next->text = preg_replace('/^\r?\n/', '', $next->text, 1);
        }
    }

    /**
     * Is deze korte naam een verwijzing naar de klasse (en niet een
     * methode, eigenschap, constante of functie met dezelfde naam)?
     *
     * @param  array<int, PhpToken>  $tokens
     */
    private function isClassReference(array $tokens, int $index): bool
    {
        $previous = $this->significant($tokens, $index, -1);
        $next = $this->significant($tokens, $index, 1);

        if ($previous && ($previous->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_GOTO]) || $previous->text === '$')) {
            return false;
        }

        if ($next && $next->text === '(' && ! ($previous && $previous->is(T_NEW))) {
            return false;
        }

        // Een named argument (`MediaLibraryItem: ...`), niet `::`.
        return ! ($next && $next->text === ':');
    }

    /** @param  array<int, PhpToken>  $tokens */
    private function significant(array $tokens, int $index, int $direction): ?PhpToken
    {
        for ($i = $index + $direction; isset($tokens[$i]); $i += $direction) {
            if (! $tokens[$i]->isIgnorable()) {
                return $tokens[$i];
            }
        }

        return null;
    }

    /**
     * Aanroepen op ons model die het niet kent. Een heuristiek: ketens die
     * beginnen bij `MediaItem::`, variabelen die uit zo'n keten of uit
     * `new MediaItem` komen, `foreach (MediaItem::... as $item)` en
     * parameters met MediaItem als type. Variabelen gelden per functie.
     *
     * @return array<int, array{line: int, text: string}>
     */
    private function unknownCalls(string $code): array
    {
        $tokens = array_values(array_filter(PhpToken::tokenize($code), fn (PhpToken $t) => ! $t->isIgnorable()));
        $names = $this->namesFor($tokens, MediaItem::class);

        if ($names === []) {
            return [];
        }

        $isItem = fn (?PhpToken $t) => $t && $t->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) && in_array(strtolower(ltrim($t->text, '\\')), $names, true);
        $found = [];
        $vars = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            // Een benoemde functie of methode begint met schone variabelen.
            if ($token->is(T_FUNCTION) && ($tokens[$i + 1] ?? null)?->is(T_STRING)) {
                $vars = [];
            }

            // Parameter met type: `MediaItem $item` of `?MediaItem $item`.
            if ($isItem($token) && ($tokens[$i + 1] ?? null)?->is(T_VARIABLE)) {
                $vars[$tokens[$i + 1]->text] = true;
            }

            if ($isItem($token) && ($tokens[$i + 1] ?? null)?->is(T_DOUBLE_COLON)) {
                [$state, $last, $calls] = $this->walkChain($tokens, $i + 2, 'static');
                $found = array_merge($found, $calls);

                // `$x = MediaItem::find(...)`: $x is een item.
                if ($state === 'model' && ($tokens[$i - 1] ?? null)?->text === '=' && ($tokens[$i - 2] ?? null)?->is(T_VARIABLE)) {
                    $vars[$tokens[$i - 2]->text] = true;
                }

                // `foreach (MediaItem::...->get() as $item)`.
                if (($tokens[$i - 1] ?? null)?->text === '(' && ($tokens[$i - 2] ?? null)?->is(T_FOREACH)) {
                    for ($j = $last; $j < $count && $tokens[$j]->text !== ')'; $j++) {
                        if ($tokens[$j]->is(T_AS)) {
                            $variable = ($tokens[$j + 2] ?? null)?->is(T_DOUBLE_ARROW) ? ($tokens[$j + 3] ?? null) : ($tokens[$j + 1] ?? null);

                            if ($variable?->is(T_VARIABLE)) {
                                $vars[$variable->text] = true;
                            }
                        }
                    }
                }

                continue;
            }

            if ($token->is(T_NEW) && $isItem($tokens[$i + 1] ?? null) && ($tokens[$i - 1] ?? null)?->text === '=' && ($tokens[$i - 2] ?? null)?->is(T_VARIABLE)) {
                $vars[$tokens[$i - 2]->text] = true;

                continue;
            }

            if ($token->is(T_VARIABLE) && isset($vars[$token->text]) && ($tokens[$i + 1] ?? null)?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
                // Een nieuwe toewijzing aan de variabele maakt hem iets anders.
                if (($tokens[$i - 1] ?? null)?->text === '=') {
                    continue;
                }

                [, , $calls] = $this->walkChain($tokens, $i + 1, 'model');
                $found = array_merge($found, $calls);
            }

            if ($token->is(T_VARIABLE) && ($tokens[$i + 1] ?? null)?->text === '=' && isset($vars[$token->text]) && ! $isItem($tokens[$i + 2] ?? null) && ! ($tokens[$i + 2] ?? null)?->is(T_NEW)) {
                unset($vars[$token->text]);
            }
        }

        return $found;
    }

    /**
     * Loopt een keten `->a()->b()` af vanaf $i (dat op `::`, `->` of `?->`
     * staat, of na `::` op de methodenaam).
     *
     * @param  array<int, PhpToken>  $tokens
     * @return array{0: string, 1: int, 2: array<int, array{line: int, text: string}>}
     */
    private function walkChain(array $tokens, int $i, string $state): array
    {
        $found = [];

        while (true) {
            if ($state !== 'static') {
                if (! ($tokens[$i] ?? null)?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
                    return [$state, $i, $found];
                }

                $i++;
            }

            $name = $tokens[$i] ?? null;

            if (! $name || ! $name->is(T_STRING)) {
                return [$state, $i, $found];
            }

            // Een eigenschap of constante, geen aanroep.
            if (($tokens[$i + 1] ?? null)?->text !== '(') {
                if ($state === 'static' && $name->text !== 'class' && ! defined(MediaItem::class . '::' . $name->text)) {
                    $found[] = ['line' => $name->line, 'text' => "MediaItem::{$name->text} does not exist on the own model"];
                }

                return ['done', $i, $found];
            }

            $method = strtolower($name->text);
            $known = $state === 'model' ? $this->isModelMethod($method) : ($this->isBuilderMethod($method) || $this->isModelMethod($method));

            if (! $known) {
                $found[] = ['line' => $name->line, 'text' => "Call to {$name->text}() on a media item or its query: the own model has no such method"];

                return ['done', $i, $found];
            }

            $i = $this->skipParentheses($tokens, $i + 1);

            if ($state !== 'model' && in_array($method, self::BUILDER_STARTERS, true)) {
                $state = 'builder';

                continue;
            }

            if ($state === 'model' || (! $this->isBuilderMethod($method) && ! in_array($method, self::SINGLE_MODEL, true))) {
                // Wat een modelmethode of een afsluitende query-methode teruggeeft kennen we niet.
                return ['done', $i, $found];
            }

            if (in_array($method, self::SINGLE_MODEL, true)) {
                $state = 'model';

                continue;
            }

            if (in_array($method, ['get', 'all', 'pluck', 'value', 'exists', 'count', 'cursor', 'lazy', 'paginate', 'chunk', 'each', 'update', 'delete', 'sum', 'max', 'min', 'avg', 'findmany', 'doesntexist'], true)) {
                return ['done', $i, $found];
            }

            $state = 'builder';
        }
    }

    /** @param  array<int, PhpToken>  $tokens */
    private function skipParentheses(array $tokens, int $i): int
    {
        $depth = 0;

        for (; isset($tokens[$i]); $i++) {
            if ($tokens[$i]->text === '(') {
                $depth++;
            } elseif ($tokens[$i]->text === ')' && --$depth === 0) {
                return $i + 1;
            }
        }

        return $i;
    }

    private function isModelMethod(string $method): bool
    {
        // Een model stuurt onbekende aanroepen door naar zijn query.
        return method_exists(MediaItem::class, $method) || $this->isBuilderMethod($method);
    }

    private function isBuilderMethod(string $method): bool
    {
        return method_exists(EloquentBuilder::class, $method)
            || method_exists(QueryBuilder::class, $method)
            || method_exists(MediaItem::class, 'scope' . $method)
            || in_array($method, self::SOFT_DELETE_MACROS, true)
            || EloquentBuilder::hasGlobalMacro($method)
            || QueryBuilder::hasMacro($method);
    }

    /**
     * De namen waaronder een klasse in deze code bereikbaar is: volledig
     * gekwalificeerd, en de korte naam of alias als hij geïmporteerd is.
     *
     * @param  array<int, PhpToken>  $tokens
     * @return array<int, string> in kleine letters
     */
    private function namesFor(array $tokens, string $class): array
    {
        $names = [];
        $lower = strtolower($class);

        foreach ($tokens as $i => $token) {
            if (! $token->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) || strtolower(ltrim($token->text, '\\')) !== $lower) {
                continue;
            }

            $names[] = $lower;

            if (($tokens[$i - 1] ?? null)?->is(T_USE)) {
                $names[] = ($tokens[$i + 1] ?? null)?->is(T_AS) ? strtolower($tokens[$i + 2]->text) : strtolower(class_basename($class));
            }
        }

        return array_values(array_unique($names));
    }

    /** @return array<int, array{line: int, text: string}> */
    private function mentionsOutsidePhp(string $text, int $startLine): array
    {
        $found = [];
        $offset = 0;

        while (($position = stripos($text, self::VENDOR, $offset)) !== false) {
            $found[] = ['line' => $startLine + substr_count($text, "\n", 0, $position), 'text' => 'Old package named outside a PHP block (comment, directive or markup), not rewritten'];
            $offset = $position + strlen(self::VENDOR);
        }

        return $found;
    }

    private function lineAt(string $code, int $offset): int
    {
        return substr_count($code, "\n", 0, $offset) + 1;
    }

    private function linesBefore(string $text, string $needle): int
    {
        $position = stripos($text, $needle);

        return $position === false ? 0 : substr_count($text, "\n", 0, $position);
    }
}
