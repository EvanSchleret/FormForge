<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Support\Antivirus;

use EvanSchleret\FormForge\Exceptions\FormForgeException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class SignatureUpdater
{
    public function update(): array
    {
        if (! class_exists(\phpMussel\Core\Loader::class)) {
            throw new FormForgeException(trans('formforge::messages.upload_scanner_dependency_missing', [
                'command' => 'composer require phpmussel/core',
            ]));
        }

        if (! function_exists('gzdecode')) {
            throw new FormForgeException(trans('formforge::messages.upload_scanner_gzip_missing'));
        }

        $signatureFiles = config('formforge.uploads.scanner.phpmussel.signature_files', []);
        $signatureDirectory = trim((string) config('formforge.uploads.scanner.phpmussel.signature_directory', ''));
        $statePath = trim((string) config('formforge.uploads.scanner.phpmussel.state_path', ''));

        if (! is_array($signatureFiles) || $signatureFiles === [] || $signatureDirectory === '' || $statePath === '') {
            throw new FormForgeException(trans('formforge::messages.upload_scanner_signatures_missing'));
        }

        $timeout = max(1, (int) config('formforge.uploads.scanner.phpmussel.request_timeout', 30));
        $apiUrl = rtrim((string) config('formforge.uploads.scanner.phpmussel.signature_source_api'), '/');
        $rawUrl = rtrim((string) config('formforge.uploads.scanner.phpmussel.signature_source_raw'), '/');
        try {
            $commitResponse = Http::acceptJson()
                ->withUserAgent('FormForge Signature Updater')
                ->timeout($timeout)
                ->get($apiUrl, ['path' => 'signatures.dat', 'per_page' => 1]);
        } catch (\Throwable $exception) {
            throw new FormForgeException(trans('formforge::messages.upload_scanner_signature_update_failed'), previous: $exception);
        }

        if (! $commitResponse->successful()) {
            throw new FormForgeException(trans('formforge::messages.upload_scanner_signature_update_failed'));
        }

        $commit = $commitResponse->json('0');
        $commitHash = is_array($commit) ? (string) data_get($commit, 'sha', '') : '';
        $sourceUpdatedAt = is_array($commit) ? data_get($commit, 'commit.committer.date') : null;

        if ($commitHash === '' || ! is_string($sourceUpdatedAt)) {
            throw new FormForgeException(trans('formforge::messages.upload_scanner_signature_update_failed'));
        }

        try {
            $sourceDate = Carbon::parse($sourceUpdatedAt);
        } catch (\Throwable $exception) {
            throw new FormForgeException(trans('formforge::messages.upload_scanner_signature_update_failed'), previous: $exception);
        }

        if ($sourceDate->isFuture()) {
            throw new FormForgeException(trans('formforge::messages.upload_scanner_signature_update_failed'));
        }

        $directory = rtrim($signatureDirectory, DIRECTORY_SEPARATOR);
        $parentDirectory = dirname($directory);
        $stagingDirectory = $parentDirectory . DIRECTORY_SEPARATOR . '.formforge-signatures-' . Str::random(12);

        File::ensureDirectoryExists($stagingDirectory, 0700, true);
        File::ensureDirectoryExists($directory, 0700, true);
        @chmod($stagingDirectory, 0700);
        @chmod($directory, 0700);

        try {
            foreach ($signatureFiles as $signatureFile) {
                $signatureFile = basename((string) $signatureFile);

                if (! preg_match('/^[a-z0-9_]+\.(?:db|cedb)$/i', $signatureFile)) {
                    throw new FormForgeException(trans('formforge::messages.upload_scanner_signatures_missing'));
                }

                $url = $rawUrl . '/' . $commitHash . '/clamav/' . $signatureFile . '.gz';
                try {
                    $response = Http::retry(2, 200)
                        ->withUserAgent('FormForge Signature Updater')
                        ->timeout($timeout)
                        ->get($url);
                } catch (\Throwable $exception) {
                    throw new FormForgeException(trans('formforge::messages.upload_scanner_signature_update_failed'), previous: $exception);
                }

                if (! $response->successful()) {
                    throw new FormForgeException(trans('formforge::messages.upload_scanner_signature_update_failed'));
                }

                $contents = gzdecode($response->body());

                if (! is_string($contents) || $contents === '') {
                    throw new FormForgeException(trans('formforge::messages.upload_scanner_signature_update_failed'));
                }

                $temporaryPath = $stagingDirectory . DIRECTORY_SEPARATOR . $signatureFile;

                if (file_put_contents($temporaryPath, $contents, LOCK_EX) === false) {
                    throw new FormForgeException(trans('formforge::messages.upload_scanner_signature_update_failed'));
                }
                @chmod($temporaryPath, 0600);
            }

            foreach ($signatureFiles as $signatureFile) {
                $signatureFile = basename((string) $signatureFile);
                $source = $stagingDirectory . DIRECTORY_SEPARATOR . $signatureFile;
                $target = $directory . DIRECTORY_SEPARATOR . $signatureFile;

                if (! rename($source, $target)) {
                    throw new FormForgeException(trans('formforge::messages.upload_scanner_signature_update_failed'));
                }
            }

            $state = json_encode([
                'source_updated_at' => $sourceDate->toIso8601String(),
                'synced_at' => Carbon::now()->toIso8601String(),
                'source_commit' => $commitHash,
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

            File::ensureDirectoryExists(dirname($statePath), 0700, true);
            @chmod(dirname($statePath), 0700);

            if (file_put_contents($statePath, $state, LOCK_EX) === false) {
                throw new FormForgeException(trans('formforge::messages.upload_scanner_signature_update_failed'));
            }
            @chmod($statePath, 0600);
        } finally {
            File::deleteDirectory($stagingDirectory);
        }

        return [
            'source_updated_at' => $sourceDate->toIso8601String(),
            'synced_at' => Carbon::now()->toIso8601String(),
            'source_commit' => $commitHash,
            'files' => array_values($signatureFiles),
        ];
    }
}
