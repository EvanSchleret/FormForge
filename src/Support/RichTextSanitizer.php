<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Support;

use Mews\Purifier\Facades\Purifier;

final class RichTextSanitizer
{
    private const PURIFIER_CONFIG = [
        'HTML.Allowed' => 'a[href],b,br,em,i,li,ol,p,strong,u,ul',
        'AutoFormat.AutoParagraph' => false,
        'AutoFormat.RemoveEmpty' => false,
    ];

    public static function sanitize(?string $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return '';
        }

        return Purifier::clean($value, self::PURIFIER_CONFIG);
    }
}
