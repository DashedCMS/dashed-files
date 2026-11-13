<?php

namespace Dashed\DashedFiles\Classes\Access;

use RuntimeException;

/**
 * Een bestand van het item is groter dan de wachtrijtaak in één keer mag
 * kopiëren (`dashed-files.private.queue_max_mb`). Geen mislukking: het item
 * blijft wachten, de doctor meldt het, en `dashed:media-private relocate
 * --now` verhuist het buiten de wachtrij.
 */
final class RelocationTooLarge extends RuntimeException
{
}
