<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Automations\Contracts;

use EvanSchleret\FormForge\Models\FormSubmission;

interface SubmissionAutomation
{
    public function handle(FormSubmission $submission): void;
}
