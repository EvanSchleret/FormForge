<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Automations\Contracts;

use EvanSchleret\FormForge\Models\FormSubmission;

interface SubmissionAutomationResolver
{
    public function matches(FormSubmission $submission): bool;
}

