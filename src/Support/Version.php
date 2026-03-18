<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Support;

final class Version
{
    public static function compare(string $left, string $right): int
    {
        $leftSemver = self::looksLikeVersion($left);
        $rightSemver = self::looksLikeVersion($right);

        if ($leftSemver && $rightSemver) {
            return version_compare($left, $right);
        }

        $natural = strnatcmp($left, $right);

        if ($natural !== 0) {
            return $natural;
        }

        return strcmp($left, $right);
    }

    public static function sort(array $versions, bool $descending = false): array
    {
        usort($versions, static function (string $left, string $right) use ($descending): int {
            $comparison = self::compare($left, $right);

            return $descending ? $comparison * -1 : $comparison;
        });

        return $versions;
    }

    private static function looksLikeVersion(string $value): bool
    {
        return preg_match('/^[0-9]+(?:\.[0-9A-Za-z-]+)*$/', $value) === 1;
    }
}
