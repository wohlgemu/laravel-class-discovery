<?php

namespace Schildhain\ClassDiscovery;

use Illuminate\Support\Facades\Facade as IlluminatedFacade;

/**
 * @see ClassDiscovery
 */
class Facade extends FacadesFacade
{
    protected static function getFacadeAccessor(): string
    {
        return ClassDiscovery::class;
    }
}
