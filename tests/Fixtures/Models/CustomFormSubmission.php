<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Tests\Fixtures\Models;

use EvanSchleret\FormForge\Models\FormSubmission;

class CustomFormSubmission extends FormSubmission
{
    protected static function booted(): void
    {
        parent::booted();

        static::creating(static function (self $submission): void {
            $meta = $submission->meta;

            if (! is_array($meta)) {
                $meta = [];
            }

            $meta['custom_model'] = true;
            $submission->meta = $meta;
        });
    }
}
