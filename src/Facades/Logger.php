<?php

namespace Timewave\LaravelLogger\Facades;

use Illuminate\Support\Facades\Facade;
use Timewave\LaravelLogger\Classes\CustomLogger;

class Logger extends Facade
{
    public static function getFacadeAccessor(): string
    {
        return CustomLogger::class;
    }
}
