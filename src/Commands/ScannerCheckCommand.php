<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Commands;

use EvanSchleret\FormForge\Support\Antivirus\ScannerManager;
use Illuminate\Console\Command;

class ScannerCheckCommand extends Command
{
    protected $signature = 'formforge:scanner:check';

    protected $description = 'Check the configured FormForge upload scanner and signature status.';

    public function handle(ScannerManager $scanners): int
    {
        $status = $scanners->status();

        if (! ($status['configured'] ?? false)) {
            $this->warn((string) ($status['message'] ?? trans('formforge::messages.upload_scanner_not_configured')));

            return self::SUCCESS;
        }

        $driver = (string) ($status['driver'] ?? 'unknown');
        $message = $status['message'] ?? null;

        if (! ($status['ready'] ?? false)) {
            $this->error((string) ($message ?? trans('formforge::messages.upload_antivirus_scan_failed')));

            return self::FAILURE;
        }

        $this->info(trans('formforge::messages.upload_scanner_ready', ['driver' => $driver]));

        if (is_string($status['signature_updated_at'] ?? null)) {
            $this->line(trans('formforge::messages.upload_scanner_signature_age', [
                'date' => $status['signature_updated_at'],
                'days' => (int) ($status['signature_age_days'] ?? 0),
            ]));
        } elseif (($status['signature_freshness_supported'] ?? true) === false) {
            $this->line(trans('formforge::messages.upload_scanner_external_freshness'));
        }

        return self::SUCCESS;
    }
}
