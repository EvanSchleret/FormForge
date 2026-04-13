<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Tests\Fixtures;

use EvanSchleret\FormForge\Automations\Contracts\SubmissionAutomation;
use EvanSchleret\FormForge\Models\FormSubmission;
use Illuminate\Support\Facades\DB;

class CreateRecordFromSubmissionAutomation implements SubmissionAutomation
{
    public function handle(FormSubmission $submission): void
    {
        $payload = is_array($submission->payload) ? $submission->payload : [];

        $recordId = DB::table('records')->insertGetId([
            'form_submission_id' => (int) $submission->getKey(),
            'email' => (string) ($payload['email'] ?? ''),
            'plan' => (string) ($payload['plan'] ?? ''),
        ]);

        $submission->meta('record_id', (int) $recordId);
    }
}
