<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Support\Antivirus;

use EvanSchleret\FormForge\Exceptions\FormForgeException;
use Illuminate\Http\UploadedFile;

final class ScannerManager
{
    public function driver(): ?string
    {
        $driver = config('formforge.uploads.scanner.driver');

        if (is_string($driver) && trim($driver) !== '') {
            return trim($driver);
        }

        if ((bool) config('formforge.uploads.antivirus.enabled', false)) {
            return 'clamav-rest';
        }

        return null;
    }

    public function availableInstallers(): array
    {
        $installers = config('formforge.uploads.scanner.installers', ['phpmussel']);

        if (! is_array($installers)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $driver): string => trim((string) $driver),
            $installers,
        ), fn (string $driver): bool => isset($this->drivers()[$driver])));
    }

    public function resolve(?string $driver = null): ?FileScanner
    {
        $driver ??= $this->driver();

        if ($driver === null || trim($driver) === '') {
            return null;
        }

        $drivers = $this->drivers();
        $class = $drivers[$driver] ?? (class_exists($driver) ? $driver : null);

        if (! is_string($class) || ! class_exists($class) || ! is_subclass_of($class, FileScanner::class)) {
            throw new FormForgeException(trans('formforge::messages.upload_scanner_driver_invalid', ['driver' => $driver]));
        }

        if ($driver === 'phpmussel' && ! class_exists(\phpMussel\Core\Loader::class)) {
            throw new FormForgeException(trans('formforge::messages.upload_scanner_dependency_missing', [
                'command' => 'composer require phpmussel/core',
            ]));
        }

        return app($class);
    }

    public function scan(UploadedFile $file): void
    {
        $this->resolve()?->scan($file);
    }

    public function status(): array
    {
        $driver = $this->driver();

        if ($driver === null) {
            return [
                'configured' => false,
                'ready' => false,
                'driver' => null,
                'message' => trans('formforge::messages.upload_scanner_not_configured'),
            ];
        }

        try {
            $scanner = $this->resolve($driver);
        } catch (FormForgeException $exception) {
            return [
                'configured' => true,
                'ready' => false,
                'driver' => $driver,
                'message' => $exception->getMessage(),
            ];
        }

        if ($scanner instanceof ScannerStatusProvider) {
            return ['configured' => true, 'driver' => $driver, ...$scanner->status()];
        }

        return [
            'configured' => true,
            'ready' => true,
            'driver' => $driver,
            'message' => null,
            'signature_freshness_supported' => false,
        ];
    }

    private function drivers(): array
    {
        $drivers = config('formforge.uploads.scanner.drivers', []);

        return is_array($drivers) ? $drivers : [];
    }
}
