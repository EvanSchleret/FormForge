<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Commands;

use EvanSchleret\FormForge\Ownership\OwnershipReference;
use EvanSchleret\FormForge\Submissions\SubmissionPrivacyService;
use Illuminate\Console\Command;

class GdprRunCommand extends Command
{
    protected $signature = 'formforge:gdpr:run
                            {--dry-run : Preview GDPR actions without persisting changes}
                            {--chunk= : Number of rows processed per chunk}
                            {--owner-type= : Optional owner type for scoped run}
                            {--owner-id= : Optional owner id for scoped run}';

    protected $description = 'Run FormForge GDPR retention/anonymization engine';

    public function handle(SubmissionPrivacyService $privacy): int
    {
        $ownerType = $this->normalizeOptionalString($this->option('owner-type'));
        $ownerId = $this->normalizeOptionalString($this->option('owner-id'));
        $owner = null;

        if (($ownerType === null) !== ($ownerId === null)) {
            $this->error('Both --owner-type and --owner-id must be provided together.');

            return self::FAILURE;
        }

        if ($ownerType !== null && $ownerId !== null) {
            $owner = OwnershipReference::from([
                'type' => $ownerType,
                'id' => $ownerId,
            ]);
        }

        $chunk = $this->normalizeChunk($this->option('chunk'));

        if ($chunk !== null && $chunk < 1) {
            $this->error('The [--chunk] option must be greater than or equal to 1.');

            return self::FAILURE;
        }

        $result = $privacy->run([
            'dry_run' => (bool) $this->option('dry-run'),
            'chunk' => $chunk,
        ], $owner);

        $this->table(['metric', 'value'], [
            ['enabled', (bool) $result['enabled'] ? 'yes' : 'no'],
            ['dry_run', (bool) $result['dry_run'] ? 'yes' : 'no'],
            ['scanned', (string) $result['scanned']],
            ['eligible', (string) $result['eligible']],
            ['eligible_anonymize', (string) $result['eligible_anonymize']],
            ['eligible_delete', (string) $result['eligible_delete']],
            ['anonymized', (string) $result['anonymized']],
            ['deleted', (string) $result['deleted']],
            ['failed', (string) $result['failed']],
            ['skipped', (string) $result['skipped']],
        ]);

        if ((bool) $result['dry_run']) {
            $this->info('GDPR dry-run completed.');

            return self::SUCCESS;
        }

        if ((int) $result['failed'] > 0) {
            $this->warn('GDPR run completed with errors.');

            return self::FAILURE;
        }

        $this->info('GDPR run completed successfully.');

        return self::SUCCESS;
    }

    private function normalizeChunk(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}

