<?php

namespace Noria\Payments\Facades;

use Illuminate\Support\Facades\Facade;
use Noria\Payments\PaymentsManager;

class Payments extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PaymentsManager::class;
    }
}
