<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Exceptions;

class ImmutableVersionException extends FormForgeException
{
    public static function forKeyVersion(string $key, string $version): self
    {
        return new self(trans('formforge::validation.immutable_version', ['key' => $key, 'version' => $version]));
    }
}
