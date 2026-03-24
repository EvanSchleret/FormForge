<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Tests\Fixtures;

use EvanSchleret\FormForge\Automations\Contracts\SubmissionAutomation;
use EvanSchleret\FormForge\Models\FormSubmission;
use Illuminate\Support\Facades\DB;

class CreateMembershipFromSubmissionAutomation implements SubmissionAutomation
{
    public function handle(FormSubmission $submission): void
    {
        $payload = is_array($submission->payload) ? $submission->payload : [];

        DB::table('memberships')->insert([
            'form_submission_id' => (int) $submission->getKey(),
            'email' => (string) ($payload['email'] ?? ''),
            'plan' => (string) ($payload['plan'] ?? ''),
        ]);
    }
}
