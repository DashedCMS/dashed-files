<?php

namespace Qubiqx\QcommerceFiles\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Qubiqx\QcommerceFiles\QcommerceFiles
 */
class QcommerceFiles extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'qcommerce-files';
    }
}
