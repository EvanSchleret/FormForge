<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Support;

class Schema
{
    public static function canonicalize(array $value): array
    {
        return self::sortRecursive($value);
    }

    public static function encode(array $value): string
    {
        $encoded = json_encode(self::canonicalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? '[]' : $encoded;
    }

    public static function hash(array $value): string
    {
        return hash('sha256', self::encode($value));
    }

    private static function sortRecursive(array $value): array
    {
        if (! self::isAssoc($value)) {
            foreach ($value as $index => $item) {
                if (is_array($item)) {
                    $value[$index] = self::sortRecursive($item);
                }
            }

            return $value;
        }

        ksort($value);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortRecursive($item);
            }
        }

        return $value;
    }

    private static function isAssoc(array $value): bool
    {
        return $value !== [] && array_keys($value) !== range(0, count($value) - 1);
    }
}
