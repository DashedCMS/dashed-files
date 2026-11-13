<?php

namespace Dashed\DashedFiles\Classes;

use InvalidArgumentException;

/**
 * Een alt-tekst, bijschrift of titel die langer is dan de kolom toelaat.
 * Een eigen klasse, zodat een scherm er een nette melding van kan maken.
 */
class MediaTextTooLongException extends InvalidArgumentException
{
    public function __construct(public readonly int $max)
    {
        parent::__construct("A media text may be at most {$max} characters.");
    }
}
