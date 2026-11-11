<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Commands;

use EvanSchleret\FormForge\Exceptions\FormForgeException;
use EvanSchleret\FormForge\Support\Antivirus\ScannerManager;
use EvanSchleret\FormForge\Support\Antivirus\SignatureUpdater;
use Illuminate\Console\Command;

class ScannerUpdateCommand extends Command
{
    protected $signature = 'formforge:scanner:update';

    protected $description = 'Update phpMussel signatures used by FormForge uploads.';

    public function handle(ScannerManager $scanners, SignatureUpdater $updater): int
    {
        if ($scanners->driver() !== 'phpmussel') {
            $this->error(trans('formforge::messages.upload_scanner_update_requires_phpmussel'));

            return self::FAILURE;
        }

        try {
            $state = $updater->update();
        } catch (FormForgeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(trans('formforge::messages.upload_scanner_signatures_installed', [
            'date' => $state['source_updated_at'],
        ]));

        return self::SUCCESS;
    }
}
