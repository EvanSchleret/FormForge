<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Exceptions;

class ImmutableVersionException extends FormForgeException
{
    public static function forKeyVersion(string $key, string $version): self
    {
        return new self("Form [{$key}] version [{$version}] is immutable and cannot be changed.");
    }
}
