<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Commands;

use EvanSchleret\FormForge\Exceptions\FormForgeException;
use EvanSchleret\FormForge\Support\Antivirus\ScannerManager;
use EvanSchleret\FormForge\Support\Antivirus\SignatureUpdater;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ScannerInstallCommand extends Command
{
    protected $signature = 'formforge:scanner:install {--scanner= : Scanner driver to install}';

    protected $description = 'Install and configure a FormForge upload scanner.';

    public function handle(ScannerManager $scanners, SignatureUpdater $updater): int
    {
        $installers = $scanners->availableInstallers();

        if ($installers === []) {
            $this->error(trans('formforge::messages.upload_scanner_no_installers'));

            return self::FAILURE;
        }

        $driver = trim((string) $this->option('scanner'));

        if ($driver === '') {
            if (! $this->input->isInteractive()) {
                $this->error(trans('formforge::messages.upload_scanner_choice_required', [
                    'drivers' => implode(', ', $installers),
                ]));

                return self::FAILURE;
            }

            $driver = (string) $this->choice(
                trans('formforge::messages.upload_scanner_choose'),
                $installers,
                0,
            );
        }

        if (! in_array($driver, $installers, true)) {
            $this->error(trans('formforge::messages.upload_scanner_driver_invalid', ['driver' => $driver]));

            return self::FAILURE;
        }

        if ($driver === 'phpmussel' && ! class_exists(\phpMussel\Core\Loader::class)) {
            $this->error(trans('formforge::messages.upload_scanner_dependency_missing', [
                'command' => 'composer require phpmussel/core',
            ]));

            return self::FAILURE;
        }

        config()->set('formforge.uploads.scanner.driver', $driver);

        if (! $this->persistDriver($driver)) {
            $this->warn(trans('formforge::messages.upload_scanner_env_not_updated', ['driver' => $driver]));
        }

        if ($driver !== 'phpmussel') {
            $this->info(trans('formforge::messages.upload_scanner_install_complete', ['driver' => $driver]));

            return self::SUCCESS;
        }

        $directories = [
            config('formforge.uploads.scanner.phpmussel.cache_directory'),
            config('formforge.uploads.scanner.phpmussel.quarantine_directory'),
            config('formforge.uploads.scanner.phpmussel.signature_directory'),
        ];

        foreach ($directories as $directory) {
            if (is_string($directory) && trim($directory) !== '') {
                File::ensureDirectoryExists($directory, 0700, true);
                @chmod($directory, 0700);
            }
        }

        $configurationPath = (string) config('formforge.uploads.scanner.phpmussel.configuration_path');
        $signatureFiles = config('formforge.uploads.scanner.phpmussel.signature_files', []);

        if (! is_file($configurationPath)) {
            $configurationDirectory = dirname($configurationPath);
            File::ensureDirectoryExists($configurationDirectory, 0700, true);
            @chmod($configurationDirectory, 0700);

            $configuration = implode("\n", [
                'core:',
                "  hide_version: true",
                "  error_log: ''",
                "  scan_log: ''",
                "  scan_log_serialized: ''",
                'signatures:',
                '  active: "' . implode(',', $signatureFiles) . '"',
                '  fail_silently: false',
                '  fail_extensions_silently: false',
                '',
            ]);

            if (! File::put($configurationPath, $configuration)) {
                $this->error(trans('formforge::messages.upload_scanner_configuration_missing'));

                return self::FAILURE;
            }
            @chmod($configurationPath, 0600);
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

        return $this->call('formforge:scanner:check');
    }

    private function persistDriver(string $driver): bool
    {
        $environmentPath = base_path('.env');

        if (! is_file($environmentPath) || ! is_writable($environmentPath)) {
            return false;
        }

        $contents = file_get_contents($environmentPath);

        if (! is_string($contents)) {
            return false;
        }

        $line = 'FORMFORGE_UPLOAD_SCANNER=' . $driver;

        if (preg_match('/^\s*FORMFORGE_UPLOAD_SCANNER\s*=.*$/m', $contents) === 1) {
            $contents = preg_replace('/^\s*FORMFORGE_UPLOAD_SCANNER\s*=.*$/m', $line, $contents, 1) ?? $contents;
        } else {
            $contents = rtrim($contents) . PHP_EOL . $line . PHP_EOL;
        }

        if (file_put_contents($environmentPath, $contents, LOCK_EX) === false) {
            return false;
        }

        if (app()->configurationIsCached()) {
            $this->call('config:clear');
        }

        return true;
    }
}
