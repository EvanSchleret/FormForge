<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Definition;

use EvanSchleret\FormForge\Exceptions\InvalidFieldDefinitionException;

final class FieldType
{
    public const TEXT = 'text';
    public const NUMBER = 'number';
    public const RADIO = 'radio';
    public const CONSENT = 'consent';
    public const CHECKBOX_GROUP = 'checkbox_group';
    public const TEMPORAL = 'temporal';
    public const DATE = 'date';
    public const TIME = 'time';
    public const FILE = 'file';
    public const ADDRESS = 'address';

    public static function all(): array
    {
        return [
            self::TEXT,
            self::NUMBER,
            self::RADIO,
            self::CONSENT,
            self::CHECKBOX_GROUP,
            self::TEMPORAL,
            self::FILE,
            self::ADDRESS,
        ];
    }

    public static function assert(string $type): void
    {
        $normalized = self::normalize($type);

        if (! in_array($normalized, self::all(), true)) {
            throw new InvalidFieldDefinitionException("Unsupported field type [{$type}].");
        }
    }

    public static function normalize(string $type): string
    {
        return match ($type) {
            self::DATE,
            self::TIME => self::TEMPORAL,
            default => $type,
        };
    }

    public static function temporalMode(string $type): ?string
    {
        return match ($type) {
            self::DATE => 'date',
            self::TIME => 'time',
            default => null,
        };
    }

    public static function isOptionBased(string $type): bool
    {
        return in_array($type, [self::RADIO, self::CHECKBOX_GROUP], true);
    }

    public static function isBoolean(string $type): bool
    {
        return $type === self::CONSENT;
    }

    public static function isRange(string $type): bool
    {
        return false;
    }

    public static function isDateLike(string $type): bool
    {
        return in_array($type, [self::DATE, self::TIME], true);
    }

    public static function isFile(string $type): bool
    {
        return $type === self::FILE;
    }
}
