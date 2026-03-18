<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Exceptions;

class UnsupportedUploadModeException extends FormForgeException
{
    public static function forMode(string $mode): self
    {
        return new self("Upload mode [{$mode}] is not supported.");
    }
}
