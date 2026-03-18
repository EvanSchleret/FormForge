<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Facades;

use EvanSchleret\FormForge\FormManager;
use Illuminate\Support\Facades\Facade;

class Form extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return FormManager::class;
    }
}
