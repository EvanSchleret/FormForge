<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Support\Uploads;

use Illuminate\Http\UploadedFile;
use Symfony\Component\Mime\MimeTypes;

final class UploadPolicy
{
    public function allowedExtensions(): array
    {
        $extensions = config('formforge.uploads.allowed_extensions', []);

        if (is_string($extensions)) {
            $extensions = explode(',', $extensions);
        }

        if (! is_array($extensions)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $extension): string => strtolower(ltrim(trim((string) $extension), '.')),
            $extensions,
        ))));
    }

    public function acceptedTypes(array $fieldAccept = []): array
    {
        $allowedExtensions = $this->allowedExtensions();
        $requested = array_values(array_filter(array_map(
            static fn (mixed $type): string => strtolower(trim((string) $type)),
            $fieldAccept,
        )));

        if ($allowedExtensions === []) {
            return ['.formforge-disabled'];
        }

        if ($requested === [] || in_array('*', $requested, true)) {
            return array_map(static fn (string $extension): string => '.' . $extension, $allowedExtensions);
        }

        $accepted = [];

        foreach ($requested as $type) {
            if ($type === 'image/*') {
                foreach ($allowedExtensions as $extension) {
                    if ($this->extensionHasImageMimeType($extension)) {
                        $accepted[] = '.' . $extension;
                    }
                }

                continue;
            }

            if (str_starts_with($type, '.')) {
                $extension = ltrim($type, '.');

                if (in_array($extension, $allowedExtensions, true)) {
                    $accepted[] = '.' . $extension;
                }

                continue;
            }

            if (str_contains($type, '/')) {
                foreach ($allowedExtensions as $extension) {
                    if (in_array($type, $this->mimeTypesFor($extension), true)) {
                        $accepted[] = '.' . $extension;
                        $accepted[] = $type;
                    }
                }

                continue;
            }

            if (in_array($type, $allowedExtensions, true)) {
                $accepted[] = '.' . $type;
            }
        }

        if ($accepted === []) {
            throw new \InvalidArgumentException('The file field accept rule does not match the configured upload extensions.');
        }

        return array_values(array_unique($accepted));
    }

    public function accepts(UploadedFile $file, array $fieldAccept = []): bool
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $mimeType = strtolower((string) ($file->getMimeType() ?? ''));

        if ($extension === '' || ! in_array($extension, $this->allowedExtensions(), true)) {
            return false;
        }

        if ($mimeType === '' || ! in_array($mimeType, $this->mimeTypesFor($extension), true)) {
            return false;
        }

        $acceptedTypes = $this->acceptedTypes($fieldAccept);

        foreach ($acceptedTypes as $type) {
            if (str_starts_with($type, '.') && ltrim($type, '.') === $extension) {
                return true;
            }

            if ($type === $mimeType || ($type === 'image/*' && str_starts_with($mimeType, 'image/'))) {
                return true;
            }
        }

        return false;
    }

    public function acceptsMetadata(array $metadata, array $fieldAccept = []): bool
    {
        $extension = strtolower(ltrim((string) ($metadata['extension'] ?? ''), '.'));
        $mimeType = strtolower((string) ($metadata['mime_type'] ?? ''));

        if ($extension === '' || ! in_array($extension, $this->allowedExtensions(), true)) {
            return false;
        }

        if ($mimeType === '' || ! in_array($mimeType, $this->mimeTypesFor($extension), true)) {
            return false;
        }

        foreach ($this->acceptedTypes($fieldAccept) as $type) {
            if ((str_starts_with($type, '.') && ltrim($type, '.') === $extension) || $type === $mimeType) {
                return true;
            }
        }

        return false;
    }

    public function maxFileSize(?int $fieldLimit = null): int
    {
        $limit = (int) config('formforge.uploads.limits.max_file_size', 10 * 1024 * 1024);

        if ($fieldLimit !== null && $fieldLimit > 0) {
            $limit = min($limit, $fieldLimit);
        }

        $caps = [$limit, $this->phpPostBodyLimit()];
        $phpUploadLimit = $this->iniBytes(ini_get('upload_max_filesize'));

        if ($phpUploadLimit !== null) {
            $caps[] = $phpUploadLimit;
        }

        return max(1, min(array_filter($caps, static fn (?int $cap): bool => $cap !== null)));
    }

    public function maxFieldTotalSize(?int $fieldLimit = null): int
    {
        $limit = (int) config('formforge.uploads.limits.max_total_size', 20 * 1024 * 1024);

        if ($fieldLimit !== null && $fieldLimit > 0) {
            $limit = min($limit, $fieldLimit);
        }

        return $this->limitToPhpPostSize($limit);
    }

    public function maxSubmissionSize(): int
    {
        return $this->limitToPhpPostSize((int) config('formforge.uploads.limits.max_total_size', 20 * 1024 * 1024));
    }

    public function maxFiles(?int $fieldLimit = null): int
    {
        $limit = (int) config('formforge.uploads.limits.max_files', 5);

        if ($fieldLimit !== null && $fieldLimit > 0) {
            $limit = min($limit, $fieldLimit);
        }

        $phpLimit = (int) ini_get('max_file_uploads');

        if ($phpLimit > 0) {
            $limit = min($limit, $phpLimit);
        }

        return max(1, $limit);
    }

    public function phpUploadLimit(): ?int
    {
        return $this->iniBytes(ini_get('upload_max_filesize'));
    }

    public function phpPostLimit(): ?int
    {
        return $this->iniBytes(ini_get('post_max_size'));
    }

    public function phpMaxFiles(): ?int
    {
        $limit = (int) ini_get('max_file_uploads');

        return $limit > 0 ? $limit : null;
    }

    public function mimeTypesFor(string $extension): array
    {
        $configured = config('formforge.uploads.allowed_mime_types', []);
        $extension = strtolower(ltrim($extension, '.'));

        if (is_array($configured) && isset($configured[$extension]) && is_array($configured[$extension])) {
            return array_values(array_unique(array_map(
                'strtolower',
                array_map('strval', $configured[$extension]),
            )));
        }

        return array_values(array_unique(array_map(
            'strtolower',
            MimeTypes::getDefault()->getMimeTypes($extension),
        )));
    }

    private function extensionHasImageMimeType(string $extension): bool
    {
        foreach ($this->mimeTypesFor($extension) as $mimeType) {
            if (str_starts_with($mimeType, 'image/')) {
                return true;
            }
        }

        return false;
    }

    private function limitToPhpPostSize(int $limit): int
    {
        $phpPostLimit = $this->phpPostLimit();

        if ($phpPostLimit === null) {
            return max(1, $limit);
        }

        $reserve = min(
            (int) config('formforge.uploads.limits.multipart_reserve', 1024 * 1024),
            max(16 * 1024, (int) floor($phpPostLimit * 0.05)),
        );

        return max(1, min($limit, $phpPostLimit - $reserve));
    }

    private function phpPostBodyLimit(): ?int
    {
        $phpPostLimit = $this->phpPostLimit();

        return $phpPostLimit === null ? null : $this->limitToPhpPostSize($phpPostLimit);
    }

    private function iniBytes(string|false $value): ?int
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || $value === '0') {
            return null;
        }

        if (! preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*([KMG])?B?$/i', $value, $matches)) {
            return null;
        }

        $unit = strtoupper($matches[2] ?? '');
        $factor = match ($unit) {
            'K' => 1024,
            'M' => 1024 ** 2,
            'G' => 1024 ** 3,
            default => 1,
        };

        return (int) floor((float) $matches[1] * $factor);
    }
}
