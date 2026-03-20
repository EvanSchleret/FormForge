<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Commands;

use EvanSchleret\FormForge\Submissions\DraftStateService;
use Illuminate\Console\Command;

class DraftsCleanupCommand extends Command
{
    protected $signature = 'formforge:drafts:cleanup
                            {--dry-run : Preview expired draft cleanup without deleting anything}
                            {--chunk=500 : Number of rows processed per database chunk}';

    protected $description = 'Cleanup expired form drafts';

    public function handle(DraftStateService $drafts): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunk = (int) $this->option('chunk');

        if ($chunk < 1) {
            $this->error('The [--chunk] option must be greater than or equal to 1.');

            return self::FAILURE;
        }

        $result = $drafts->cleanupExpired(
            dryRun: $dryRun,
            chunk: $chunk,
        );

        $this->table(['metric', 'value'], [
            ['expired_drafts', (string) $result['expired_drafts']],
            ['deleted_drafts', (string) $result['deleted_drafts']],
            ['delete_failed', (string) $result['delete_failed']],
            ['dry_run', $result['dry_run'] ? 'yes' : 'no'],
        ]);

        if ($dryRun) {
            $this->info('Dry-run completed. No drafts were deleted.');

            return self::SUCCESS;
        }

        if ($result['delete_failed'] > 0) {
            $this->warn('Cleanup completed with deletion errors.');

            return self::FAILURE;
        }

        $this->info('Expired draft cleanup completed successfully.');

        return self::SUCCESS;
    }
}
