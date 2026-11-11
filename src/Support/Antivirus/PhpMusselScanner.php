<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Support\Antivirus;

use EvanSchleret\FormForge\Exceptions\FormForgeException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

final class PhpMusselScanner implements FileScanner, ScannerStatusProvider
{
    public function status(): array
    {
        if (! class_exists(\phpMussel\Core\Loader::class)) {
            return [
                'ready' => false,
                'message' => trans('formforge::messages.upload_scanner_dependency_missing', [
                    'command' => 'composer require phpmussel/core',
                ]),
                'signature_freshness_supported' => true,
                'signature_updated_at' => null,
                'signature_age_days' => null,
            ];
        }

        if (! function_exists('gzdecode')) {
            return [
                'ready' => false,
                'message' => trans('formforge::messages.upload_scanner_gzip_missing'),
                'signature_freshness_supported' => true,
                'signature_updated_at' => null,
                'signature_age_days' => null,
            ];
        }

        $configurationPath = (string) config('formforge.uploads.scanner.phpmussel.configuration_path', '');
        $directories = [
            config('formforge.uploads.scanner.phpmussel.cache_directory'),
            config('formforge.uploads.scanner.phpmussel.quarantine_directory'),
            config('formforge.uploads.scanner.phpmussel.signature_directory'),
        ];

        if ($configurationPath === '' || ! is_file($configurationPath) || ! is_readable($configurationPath)) {
            return [
                'ready' => false,
                'message' => trans('formforge::messages.upload_scanner_configuration_missing'),
                'signature_freshness_supported' => true,
                'signature_updated_at' => null,
                'signature_age_days' => null,
            ];
        }

        foreach ($directories as $directory) {
            if (! is_string($directory) || ! is_dir($directory) || ! is_writable($directory)) {
                return [
                    'ready' => false,
                    'message' => trans('formforge::messages.upload_scanner_directories_unwritable'),
                    'signature_freshness_supported' => true,
                    'signature_updated_at' => null,
                    'signature_age_days' => null,
                ];
            }
        }

        $signatureDirectory = (string) config('formforge.uploads.scanner.phpmussel.signature_directory', '');
        $signatureFiles = config('formforge.uploads.scanner.phpmussel.signature_files', []);

        if ($signatureDirectory === '' || ! is_array($signatureFiles) || $signatureFiles === []) {
            return [
                'ready' => false,
                'message' => trans('formforge::messages.upload_scanner_signatures_missing'),
                'signature_freshness_supported' => true,
                'signature_updated_at' => null,
                'signature_age_days' => null,
            ];
        }

        foreach ($signatureFiles as $signatureFile) {
            $path = rtrim($signatureDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $signatureFile;
            $size = is_file($path) ? filesize($path) : false;

            if (! is_readable($path) || ! is_int($size) || $size === 0) {
                return [
                    'ready' => false,
                    'message' => trans('formforge::messages.upload_scanner_signatures_missing'),
                    'signature_freshness_supported' => true,
                    'signature_updated_at' => null,
                    'signature_age_days' => null,
                ];
            }
        }

        $statePath = (string) config('formforge.uploads.scanner.phpmussel.state_path', '');
        $stateContents = $statePath !== '' && is_file($statePath) ? file_get_contents($statePath) : false;
        $state = is_string($stateContents) ? json_decode($stateContents, true) : null;
        try {
            $sourceUpdatedAt = is_array($state) && is_string($state['source_updated_at'] ?? null)
                ? Carbon::parse($state['source_updated_at'])
                : null;
        } catch (\Throwable) {
            $sourceUpdatedAt = null;
        }

        if ($sourceUpdatedAt === null) {
            return [
                'ready' => false,
                'message' => trans('formforge::messages.upload_scanner_signatures_never_updated'),
                'signature_freshness_supported' => true,
                'signature_updated_at' => null,
                'signature_age_days' => null,
            ];
        }

        $ageSeconds = max(0, Carbon::now()->timestamp - $sourceUpdatedAt->timestamp);
        $ageDays = (int) floor($ageSeconds / 86400);
        $maxAgeDays = max(0, (int) config('formforge.uploads.scanner.phpmussel.signature_max_age_days', 7));

        if ($maxAgeDays > 0 && $ageSeconds > $maxAgeDays * 86400) {
            return [
                'ready' => false,
                'message' => trans('formforge::messages.upload_scanner_signatures_stale', ['days' => $maxAgeDays]),
                'signature_freshness_supported' => true,
                'signature_updated_at' => $sourceUpdatedAt->toIso8601String(),
                'signature_age_days' => $ageDays,
            ];
        }

        return [
            'ready' => true,
            'message' => null,
            'signature_freshness_supported' => true,
            'signature_updated_at' => $sourceUpdatedAt->toIso8601String(),
            'signature_age_days' => $ageDays,
        ];
    }

    public function scan(UploadedFile $file): void
    {
        $status = $this->status();

        if (! ($status['ready'] ?? false)) {
            throw new FormForgeException((string) ($status['message'] ?? trans('formforge::messages.upload_antivirus_scan_failed')));
        }

        $path = $file->getRealPath();

        if (! is_string($path) || $path === '') {
            throw new FormForgeException(trans('formforge::messages.upload_antivirus_scan_failed'));
        }

        $configurationPath = (string) config('formforge.uploads.scanner.phpmussel.configuration_path', '');
        $cacheDirectory = (string) config('formforge.uploads.scanner.phpmussel.cache_directory', '');
        $quarantineDirectory = (string) config('formforge.uploads.scanner.phpmussel.quarantine_directory', '');
        $signatureDirectory = (string) config('formforge.uploads.scanner.phpmussel.signature_directory', '');

        try {
            $loader = new \phpMussel\Core\Loader(
                $configurationPath,
                $cacheDirectory,
                $quarantineDirectory,
                $signatureDirectory,
                base_path('vendor'),
            );
            $loader->Configuration['signatures']['active'] = implode(',', config('formforge.uploads.scanner.phpmussel.signature_files', []));
            $loader->Configuration['signatures']['fail_silently'] = false;
            $loader->Configuration['signatures']['fail_extensions_silently'] = false;

            $scanner = new \phpMussel\Core\Scanner($loader);
            $results = $scanner->scan($path, 1);
            $codes = is_array($results) ? array_values($results) : [$results];

            if (in_array(2, $codes, true)) {
                throw new FormForgeException(trans('formforge::messages.upload_antivirus_infected'));
            }

            if ($codes === [] || array_filter($codes, static fn (mixed $code): bool => $code !== 1) !== []) {
                throw new FormForgeException(trans('formforge::messages.upload_antivirus_scan_failed'));
            }
        } catch (FormForgeException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::warning('FormForge phpMussel scan failed.', ['exception' => $exception]);

            throw new FormForgeException(trans('formforge::messages.upload_antivirus_scan_failed'), previous: $exception);
        }
    }
}
