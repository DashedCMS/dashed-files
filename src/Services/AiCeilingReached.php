<?php

namespace Dashed\DashedFiles\Services;

use RuntimeException;

/**
 * Het dagplafond voor AI-beelden is bereikt (of het plafond kon niet veilig
 * worden getoetst). Er is niets verstuurd en niets betaald. De melding is
 * vertaald en bedoeld voor een beheerder.
 */
final class AiCeilingReached extends RuntimeException
{
}
