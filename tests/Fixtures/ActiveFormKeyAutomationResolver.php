<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Tests\Fixtures;

use EvanSchleret\FormForge\Automations\Contracts\SubmissionAutomationResolver;
use EvanSchleret\FormForge\Models\FormSubmission;

class ActiveFormKeyAutomationResolver implements SubmissionAutomationResolver
{
    public function matches(FormSubmission $submission): bool
    {
        $activeFormKey = config('formforge.tests.active_form_key');

        if (! is_string($activeFormKey)) {
            return false;
        }

        $activeFormKey = trim($activeFormKey);

        if ($activeFormKey === '') {
            return false;
        }

        return (string) $submission->form_key === $activeFormKey;
    }
}

