<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Support\Antivirus;

use EvanSchleret\FormForge\Exceptions\FormForgeException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

final class ClamAvRestScanner implements FileScanner
{
    public function scan(UploadedFile $file): void
    {
        $endpoint = trim((string) config('formforge.uploads.antivirus.endpoint', ''));

        if ($endpoint === '') {
            throw new FormForgeException(trans('formforge::messages.upload_antivirus_endpoint_missing'));
        }

        $path = $file->getRealPath();

        if (! is_string($path) || $path === '') {
            throw new FormForgeException(trans('formforge::messages.upload_antivirus_scan_failed'));
        }

        $request = Http::timeout(max(1, (int) config('formforge.uploads.antivirus.timeout', 30)));
        $username = trim((string) config('formforge.uploads.antivirus.username', ''));
        $password = (string) config('formforge.uploads.antivirus.password', '');

        if ($username !== '') {
            $request = $request->withBasicAuth($username, $password);
        }

        $stream = null;

        try {
            $stream = fopen($path, 'rb');

            if ($stream === false) {
                throw new FormForgeException(trans('formforge::messages.upload_antivirus_scan_failed'));
            }

            $response = $request
                ->attach('file', $stream, $file->getClientOriginalName())
                ->post($endpoint);

        } catch (FormForgeException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new FormForgeException(trans('formforge::messages.upload_antivirus_scan_failed'), previous: $exception);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($response->status() === 406) {
            throw new FormForgeException(trans('formforge::messages.upload_antivirus_infected'));
        }

        if (! $response->successful()) {
            throw new FormForgeException(trans('formforge::messages.upload_antivirus_scan_failed'));
        }
    }
}
