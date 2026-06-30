<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Exceptions;

class FormNotFoundException extends FormForgeException
{
    public static function forKey(string $key, ?string $version = null): self
    {
        if ($version === null) {
            return new self(trans('formforge::validation.form_not_found', ['key' => $key]));
        }

        return new self(trans('formforge::validation.form_version_not_found', ['key' => $key, 'version' => $version]));
    }

    public static function forUuid(string $formUuid): self
    {
        return new self(trans('formforge::validation.form_not_found', ['key' => $formUuid]));
    }
}
