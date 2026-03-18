<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Definition;

use EvanSchleret\FormForge\Exceptions\InvalidFieldDefinitionException;

final class FieldType
{
    public const TEXT = 'text';
    public const TEXTAREA = 'textarea';
    public const EMAIL = 'email';
    public const NUMBER = 'number';
    public const SELECT = 'select';
    public const SELECT_MENU = 'select_menu';
    public const RADIO = 'radio';
    public const CHECKBOX = 'checkbox';
    public const CHECKBOX_GROUP = 'checkbox_group';
    public const SWITCH = 'switch';
    public const DATE = 'date';
    public const TIME = 'time';
    public const DATETIME = 'datetime';
    public const DATE_RANGE = 'date_range';
    public const DATETIME_RANGE = 'datetime_range';
    public const FILE = 'file';

    public static function all(): array
    {
        return [
            self::TEXT,
            self::TEXTAREA,
            self::EMAIL,
            self::NUMBER,
            self::SELECT,
            self::SELECT_MENU,
            self::RADIO,
            self::CHECKBOX,
            self::CHECKBOX_GROUP,
            self::SWITCH,
            self::DATE,
            self::TIME,
            self::DATETIME,
            self::DATE_RANGE,
            self::DATETIME_RANGE,
            self::FILE,
        ];
    }

    public static function assert(string $type): void
    {
        if (! in_array($type, self::all(), true)) {
            throw new InvalidFieldDefinitionException("Unsupported field type [{$type}].");
        }
    }

    public static function isOptionBased(string $type): bool
    {
        return in_array($type, [self::SELECT, self::SELECT_MENU, self::RADIO, self::CHECKBOX_GROUP], true);
    }

    public static function isBoolean(string $type): bool
    {
        return in_array($type, [self::CHECKBOX, self::SWITCH], true);
    }

    public static function isRange(string $type): bool
    {
        return in_array($type, [self::DATE_RANGE, self::DATETIME_RANGE], true);
    }

    public static function isDateLike(string $type): bool
    {
        return in_array($type, [self::DATE, self::DATETIME], true);
    }

    public static function isFile(string $type): bool
    {
        return $type === self::FILE;
    }
}
