<?php

namespace Dashed\DashedFiles\Classes\Sources;

use RuntimeException;

/**
 * Een externe bron die niet kan leveren. De melding is vertaald, bedoeld
 * voor een beheerder, en bevat nooit een sleutel, een adres met een sleutel
 * erin of de inhoud van een antwoord van de aanbieder. Draagt daarom ook
 * nooit de oorspronkelijke uitzondering mee.
 */
final class MediaSourceException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'failed')
    {
        parent::__construct($message);
    }
}
