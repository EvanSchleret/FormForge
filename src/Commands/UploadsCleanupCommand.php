<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Commands;

use EvanSchleret\FormForge\Submissions\StagedUploadService;
use Illuminate\Console\Command;

class UploadsCleanupCommand extends Command
{
    protected $signature = 'formforge:uploads:cleanup
                            {--dry-run : Preview expired staged upload cleanup without deleting anything}
                            {--keep-files : Keep temporary files on disk and only delete tokens}
                            {--chunk=500 : Number of rows processed per database chunk}';

    protected $description = 'Cleanup expired staged upload tokens and temporary files';

    public function handle(StagedUploadService $stagedUploads): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $keepFiles = (bool) $this->option('keep-files');
        $chunk = (int) $this->option('chunk');

        if ($chunk < 1) {
            $this->error('The [--chunk] option must be greater than or equal to 1.');

            return self::FAILURE;
        }

        $result = $stagedUploads->cleanupExpired(
            dryRun: $dryRun,
            deleteFiles: ! $keepFiles,
            chunk: $chunk,
        );

        $rows = [
            ['expired_tokens', (string) $result['expired_tokens']],
            ['deleted_tokens', (string) $result['deleted_tokens']],
            ['row_delete_failed', (string) $result['row_delete_failed']],
            ['files_deleted', (string) $result['files_deleted']],
            ['files_missing', (string) $result['files_missing']],
            ['files_failed', (string) $result['files_failed']],
            ['delete_files', $result['delete_files'] ? 'yes' : 'no'],
            ['dry_run', $result['dry_run'] ? 'yes' : 'no'],
        ];

        $this->table(['metric', 'value'], $rows);

        if ($dryRun) {
            $this->info('Dry-run completed. No records or files were deleted.');

            return self::SUCCESS;
        }

        if ($result['row_delete_failed'] > 0) {
            $this->error('Cleanup completed with database deletion errors.');

            return self::FAILURE;
        }

        if ($result['files_failed'] > 0) {
            $this->warn('Cleanup completed with file deletion errors.');
        } else {
            $this->info('Expired staged upload cleanup completed successfully.');
        }

        return self::SUCCESS;
    }
}
