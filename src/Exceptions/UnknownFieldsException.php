<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Exceptions;

class UnknownFieldsException extends FormForgeException
{
    public static function fromFields(array $fields): self
    {
        $list = implode(', ', $fields);

        return new self("Payload contains unknown fields: {$list}.");
    }
}
