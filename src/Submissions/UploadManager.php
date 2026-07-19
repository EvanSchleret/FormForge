<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Submissions;

use EvanSchleret\FormForge\Exceptions\FormForgeException;
use EvanSchleret\FormForge\Exceptions\UnsupportedUploadModeException;
use EvanSchleret\FormForge\Models\StagedUpload;
use EvanSchleret\FormForge\Support\Antivirus\FileScanner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UploadManager
{
    public function __construct(
        private readonly StagedUploadService $stagedUploads,
        private readonly FileScanner $scanner,
    ) {
    }

    public function process(array $form, array $field, mixed $value, ?Model $submittedBy = null): array
    {
        $mode = (string) config('formforge.uploads.mode', 'managed');

        return match ($mode) {
            'managed' => $this->processManaged($form, $field, $value),
            'direct' => $this->processDirect($field, $value),
            'staged' => $this->processStaged($form, $field, $value, $submittedBy),
            default => throw UnsupportedUploadModeException::forMode($mode),
        };
    }

    public function stage(array $form, array $field, UploadedFile $file, ?Model $uploadedBy = null): array
    {
        return $this->stagedUploads->stage($form, $field, $file, $uploadedBy);
    }

    private function processManaged(array $form, array $field, mixed $value): array
    {
        $files = $this->normalizeManagedInput($value, (bool) ($field['multiple'] ?? false));

        if ($files === []) {
            return [
                'payload' => null,
                'files' => [],
            ];
        }

        $metadata = [];

        foreach ($files as $file) {
            $metadata[] = $this->storeUploadedFile($form, $field, $file);
        }

        $multiple = (bool) ($field['multiple'] ?? false);

        return [
            'payload' => $multiple ? $metadata : $metadata[0],
            'files' => $metadata,
        ];
    }

    private function processDirect(array $field, mixed $value): array
    {
        $items = $this->normalizeMetadataInput($value, (bool) ($field['multiple'] ?? false));

        if ($items === []) {
            return [
                'payload' => null,
                'files' => [],
            ];
        }

        $metadata = [];

        foreach ($items as $item) {
            $metadata[] = $this->normalizeExistingMetadata($field, $item);
        }

        $this->validateMetadataLimits($field, $metadata);

        $multiple = (bool) ($field['multiple'] ?? false);

        return [
            'payload' => $multiple ? $metadata : $metadata[0],
            'files' => $metadata,
        ];
    }

    private function processStaged(array $form, array $field, mixed $value, ?Model $submittedBy = null): array
    {
        $items = $this->normalizeMetadataInput($value, (bool) ($field['multiple'] ?? false));

        if ($items === []) {
            return [
                'payload' => null,
                'files' => [],
            ];
        }

        $metadata = [];

        foreach ($items as $item) {
            $metadata[] = $this->commitStagedFile($form, $field, $item, $submittedBy);
        }

        $multiple = (bool) ($field['multiple'] ?? false);

        return [
            'payload' => $multiple ? $metadata : $metadata[0],
            'files' => $metadata,
        ];
    }

    private function normalizeManagedInput(mixed $value, bool $multiple): array
    {
        if ($value === null) {
            return [];
        }

        if ($multiple) {
            if ($value instanceof UploadedFile) {
                return [$value];
            }

            if (! is_array($value)) {
                throw new FormForgeException(trans('formforge::messages.upload_expected_file_array'));
            }

            $files = [];

            foreach ($value as $item) {
                if ($item instanceof UploadedFile) {
                    $files[] = $item;
                }
            }

            return $files;
        }

        if ($value instanceof UploadedFile) {
            return [$value];
        }

        throw new FormForgeException(trans('formforge::messages.upload_expected_file_instance'));
    }

    private function normalizeMetadataInput(mixed $value, bool $multiple): array
    {
        if ($value === null) {
            return [];
        }

        if ($multiple) {
            if (! is_array($value)) {
                throw new FormForgeException(trans('formforge::messages.upload_expected_metadata_array'));
            }

            $items = [];

            foreach ($value as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }

            return $items;
        }

        if (! is_array($value)) {
            throw new FormForgeException(trans('formforge::messages.upload_expected_metadata'));
        }

        return [$value];
    }

    private function storeUploadedFile(array $form, array $field, UploadedFile $file): array
    {
        $storage = $this->resolvedStorage($field);
        $disk = $storage['disk'];
        $visibility = $storage['visibility'];
        $directory = $this->buildDirectory($storage['directory'], $form, $field);

        $originalName = $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension() ?: $file->extension() ?: '';
        $storedName = $this->storedFilename($originalName, $extension);

        if ((bool) config('formforge.uploads.antivirus.enabled', false)) {
            $this->scanner->scan($file);
        }

        $options = [];

        if ($visibility !== null) {
            $options['visibility'] = $visibility;
        }

        $path = Storage::disk($disk)->putFileAs($directory, $file, $storedName, $options);

        if (! is_string($path) || $path === '') {
            throw new FormForgeException(trans('formforge::messages.upload_store_failed'));
        }

        $size = (int) ($file->getSize() ?? 0);
        $mimeType = (string) ($file->getClientMimeType() ?: $file->getMimeType() ?: Storage::disk($disk)->mimeType($path) ?: 'application/octet-stream');
        $checksum = $this->checksumFromUploadedFile($file, $disk, $path);

        return [
            'disk' => $disk,
            'path' => $path,
            'original_name' => $originalName,
            'stored_name' => $storedName,
            'mime_type' => $mimeType,
            'extension' => $extension,
            'size' => $size,
            'checksum' => $checksum,
            'metadata' => [
                'visibility' => $visibility,
                'mode' => 'managed',
            ],
        ];
    }

    private function normalizeExistingMetadata(array $field, array $item): array
    {
        $storage = $this->resolvedStorage($field);
        $disk = trim((string) Arr::get($item, 'disk', $storage['disk']));
        $path = trim((string) Arr::get($item, 'path', ''));

        if ($path === '') {
            throw new FormForgeException(trans('formforge::messages.upload_metadata_path_required'));
        }

        if (! Storage::disk($disk)->exists($path)) {
            throw new FormForgeException("File [{$path}] does not exist on disk [{$disk}].");
        }

        $storedName = (string) Arr::get($item, 'stored_name', basename($path));
        $originalName = (string) Arr::get($item, 'original_name', $storedName);
        $mimeType = (string) (Storage::disk($disk)->mimeType($path) ?: Arr::get($item, 'mime_type', 'application/octet-stream'));
        $extension = (string) Arr::get($item, 'extension', pathinfo($path, PATHINFO_EXTENSION));
        $size = (int) Storage::disk($disk)->size($path);
        $checksum = (string) Arr::get($item, 'checksum', $this->safeChecksum($disk, $path));
        $metadata = Arr::get($item, 'metadata', []);

        if (! is_array($metadata)) {
            $metadata = [];
        }

        $metadata['mode'] = 'direct';

        return [
            'disk' => $disk,
            'path' => $path,
            'original_name' => $originalName,
            'stored_name' => $storedName,
            'mime_type' => $mimeType,
            'extension' => $extension,
            'size' => $size,
            'checksum' => $checksum,
            'metadata' => $metadata,
        ];
    }

    private function validateMetadataLimits(array $field, array $metadata): void
    {
        $maxFiles = isset($field['max_files']) ? (int) $field['max_files'] : null;

        if ($maxFiles !== null && $maxFiles > 0 && count($metadata) > $maxFiles) {
            throw new FormForgeException(trans('formforge::messages.upload_max_files'));
        }

        $maxSize = isset($field['max_size']) ? (int) $field['max_size'] : null;
        $maxTotalSize = isset($field['max_total_size']) ? (int) $field['max_total_size'] : null;
        $totalSize = 0;

        foreach ($metadata as $item) {
            $size = (int) ($item['size'] ?? 0);
            $totalSize += $size;

            if ($maxSize !== null && $maxSize > 0 && $size > $maxSize) {
                throw new FormForgeException(trans('formforge::messages.staged_upload_max_size', ['max' => $maxSize]));
            }

            if (! $this->metadataMatchesAccept($item, $field['accept'] ?? [])) {
                throw new FormForgeException(trans('formforge::messages.staged_upload_accepted_types_mismatch'));
            }
        }

        if ($maxTotalSize !== null && $maxTotalSize > 0 && $totalSize > $maxTotalSize) {
            throw new FormForgeException(trans('formforge::messages.upload_max_total_size'));
        }
    }

    private function metadataMatchesAccept(array $metadata, mixed $accept): bool
    {
        if (! is_array($accept) || $accept === []) {
            return true;
        }

        $extension = strtolower(ltrim((string) ($metadata['extension'] ?? ''), '.'));
        $mimeType = strtolower((string) ($metadata['mime_type'] ?? ''));

        foreach ($accept as $rawRule) {
            $rule = strtolower(trim((string) $rawRule));

            if ($rule === '' || $rule === '*' || ($rule === 'image/*' && str_starts_with($mimeType, 'image/'))) {
                return true;
            }

            if (str_starts_with($rule, '.') && $extension === ltrim($rule, '.')) {
                return true;
            }

            if (str_contains($rule, '/') && $mimeType === $rule) {
                return true;
            }

            if (! str_contains($rule, '/') && $extension === $rule) {
                return true;
            }
        }

        return false;
    }

    private function commitStagedFile(array $form, array $field, array $item, ?Model $submittedBy = null): array
    {
        $stagedUpload = null;
        $token = trim((string) Arr::get($item, 'upload_token', ''));

        if ($token !== '') {
            $stagedUpload = $this->stagedUploads->lockAvailableToken($token, $form, $field, $submittedBy);
            $item = $this->stagedUploads->metadataFromToken($stagedUpload);
        }

        $sourceDisk = trim((string) Arr::get($item, 'disk', config('formforge.uploads.temporary_disk', config('filesystems.default'))));
        $sourcePath = trim((string) Arr::get($item, 'path', ''));

        if ($sourcePath === '') {
            throw new FormForgeException(trans('formforge::messages.staged_file_path_required'));
        }

        if (! Storage::disk($sourceDisk)->exists($sourcePath)) {
            throw new FormForgeException("Staged file [{$sourcePath}] does not exist on disk [{$sourceDisk}].");
        }

        $storage = $this->resolvedStorage($field);
        $targetDisk = $storage['disk'];
        $targetDirectory = $this->buildDirectory($storage['directory'], $form, $field);
        $visibility = $storage['visibility'];

        $sourceBasename = basename($sourcePath);
        $originalName = (string) Arr::get($item, 'original_name', $sourceBasename);
        $extension = (string) Arr::get($item, 'extension', pathinfo($sourceBasename, PATHINFO_EXTENSION));
        $storedName = (string) Arr::get($item, 'stored_name', $this->storedFilename($originalName, $extension));
        $targetPath = trim($targetDirectory . '/' . $storedName, '/');

        if ($sourceDisk === $targetDisk) {
            $moved = Storage::disk($sourceDisk)->move($sourcePath, $targetPath);

            if (! $moved) {
                throw new FormForgeException(trans('formforge::messages.staged_file_move_failed'));
            }

            if ($visibility !== null) {
                Storage::disk($targetDisk)->setVisibility($targetPath, $visibility);
            }
        } else {
            $stream = Storage::disk($sourceDisk)->readStream($sourcePath);

            if ($stream === false) {
                throw new FormForgeException(trans('formforge::messages.staged_file_stream_read_failed'));
            }

            $options = [];

            if ($visibility !== null) {
                $options['visibility'] = $visibility;
            }

            $written = Storage::disk($targetDisk)->writeStream($targetPath, $stream, $options);

            if (is_resource($stream)) {
                fclose($stream);
            }

            if (! $written) {
                throw new FormForgeException(trans('formforge::messages.staged_file_write_failed'));
            }

            Storage::disk($sourceDisk)->delete($sourcePath);
        }

        $mimeType = (string) Arr::get($item, 'mime_type', Storage::disk($targetDisk)->mimeType($targetPath) ?: 'application/octet-stream');
        $size = (int) Arr::get($item, 'size', Storage::disk($targetDisk)->size($targetPath));
        $checksum = (string) Arr::get($item, 'checksum', $this->safeChecksum($targetDisk, $targetPath));
        $metadata = Arr::get($item, 'metadata', []);

        if (! is_array($metadata)) {
            $metadata = [];
        }

        $metadata['mode'] = 'staged';
        $metadata['source_disk'] = $sourceDisk;
        $metadata['source_path'] = $sourcePath;

        if ($stagedUpload instanceof StagedUpload) {
            $this->stagedUploads->consume($stagedUpload);
        }

        return [
            'disk' => $targetDisk,
            'path' => $targetPath,
            'original_name' => $originalName,
            'stored_name' => $storedName,
            'mime_type' => $mimeType,
            'extension' => $extension,
            'size' => $size,
            'checksum' => $checksum,
            'metadata' => $metadata,
        ];
    }

    private function resolvedStorage(array $field): array
    {
        $storage = is_array($field['storage'] ?? null) ? $field['storage'] : [];

        $disk = trim((string) ($storage['disk'] ?? config('formforge.uploads.disk', config('filesystems.default'))));
        $directory = trim((string) ($storage['directory'] ?? config('formforge.uploads.directory', 'formforge')), '/');
        $visibility = trim((string) ($storage['visibility'] ?? config('formforge.uploads.visibility', 'private')));

        if ($disk === '') {
            throw new FormForgeException(trans('formforge::messages.upload_disk_empty'));
        }

        return [
            'disk' => $disk,
            'directory' => $directory,
            'visibility' => $visibility === '' ? null : $visibility,
        ];
    }

    private function buildDirectory(string $baseDirectory, array $form, array $field): string
    {
        $baseDirectory = trim($baseDirectory, '/');
        $key = trim((string) ($form['key'] ?? ''), '/');
        $version = trim((string) ($form['version'] ?? ''), '/');
        $fieldKey = trim((string) ($field['field_key'] ?? $field['name'] ?? 'file'), '/');

        $segments = array_filter([$baseDirectory, $key, $version, $fieldKey], static fn (string $value): bool => $value !== '');

        return implode('/', $segments);
    }

    private function storedFilename(string $originalName, string $extension): string
    {
        $preserve = (bool) config('formforge.uploads.preserve_original_filename', false);

        if ($preserve) {
            $name = pathinfo($originalName, PATHINFO_FILENAME);
            $name = trim($name);

            if ($name === '') {
                $name = 'file';
            }

            $slug = Str::slug($name, '_');
            $suffix = Str::lower(Str::random(6));
            $ext = $extension !== '' ? '.' . strtolower($extension) : '';

            return $slug . '_' . $suffix . $ext;
        }

        $ext = $extension !== '' ? '.' . strtolower($extension) : '';

        return (string) Str::uuid() . $ext;
    }

    private function checksumFromUploadedFile(UploadedFile $file, string $disk, string $path): string
    {
        $realPath = $file->getRealPath();

        if (is_string($realPath) && $realPath !== '' && is_file($realPath)) {
            $hash = hash_file('sha256', $realPath);

            if (is_string($hash) && $hash !== '') {
                return $hash;
            }
        }

        return $this->safeChecksum($disk, $path);
    }

    private function safeChecksum(string $disk, string $path): string
    {
        try {
            $checksum = Storage::disk($disk)->checksum($path);

            if (is_string($checksum) && $checksum !== '') {
                return $checksum;
            }
        } catch (\Throwable) {
        }

        return '';
    }
}
