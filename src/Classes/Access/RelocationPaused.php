<?php

namespace Dashed\DashedFiles\Classes\Access;

use RuntimeException;

/**
 * Een verhuizing die netjes stopt (het tijdsbudget is op, of een slot dreigt
 * te verlopen) en bij de volgende slag verdergaat. Geen mislukking.
 */
final class RelocationPaused extends RuntimeException
{
}
