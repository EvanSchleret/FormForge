<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Exceptions;

class DuplicateFormVersionException extends FormForgeException
{
    public static function forKeyVersion(string $key, string $version): self
    {
        return new self("Duplicate runtime form detected for [{$key}] version [{$version}].");
    }
}
