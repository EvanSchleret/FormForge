<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Exceptions;

class FormNotFoundException extends FormForgeException
{
    public static function forKey(string $key, ?string $version = null): self
    {
        if ($version === null) {
            return new self("Form [{$key}] was not found.");
        }

        return new self("Form [{$key}] with version [{$version}] was not found.");
    }
}
