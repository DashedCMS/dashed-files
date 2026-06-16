<?php

namespace Dashed\DashedFiles\Exceptions;

use Exception;

class DisallowedFileTypeException extends Exception
{
    public function __construct(string $fileName, string $extension)
    {
        parent::__construct("Het bestand \"{$fileName}\" is geweigerd: bestandstype \".{$extension}\" is niet toegestaan om veiligheidsredenen.");
    }
}
