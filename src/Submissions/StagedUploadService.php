<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Submissions;

use EvanSchleret\FormForge\Exceptions\FormForgeException;
use EvanSchleret\FormForge\Models\StagedUpload;
use EvanSchleret\FormForge\Support\ModelClassResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StagedUploadService
{
    public function cleanupExpired(bool $dryRun = false, bool $deleteFiles = true, int $chunk = 500): array
    {
        $chunk = max(1, $chunk);

        $query = ModelClassResolver::stagedUpload()::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', Carbon::now());

        $expired = (int) (clone $query)->count();

        $stats = [
            'dry_run' => $dryRun,
            'delete_files' => $deleteFiles,
            'expired_tokens' => $expired,
            'deleted_tokens' => 0,
            'row_delete_failed' => 0,
            'files_deleted' => 0,
            'files_missing' => 0,
            'files_failed' => 0,
        ];

        if ($dryRun || $expired === 0) {
            return $stats;
        }

        $query
            ->orderBy('id')
            ->chunkById($chunk, function ($uploads) use (&$stats, $deleteFiles): void {
                foreach ($uploads as $upload) {
                    if (! $upload instanceof StagedUpload) {
                        continue;
                    }

                    if ($deleteFiles) {
                        $this->deleteStagedFile($upload, $stats);
                    }

                    try {
                        $deleted = $upload->delete();

                        if ($deleted) {
                            $stats['deleted_tokens']++;
                        } else {
                            $stats['row_delete_failed']++;
                        }
                    } catch (\Throwable) {
                        $stats['row_delete_failed']++;
                    }
                }
            });

        return $stats;
    }

    public function stage(array $form, array $field, UploadedFile $file, ?Model $uploadedBy = null): array
    {
        $this->assertFileField($field);
        $this->validateUploadedFile($file, $field);

        $disk = trim((string) config('formforge.uploads.temporary_disk', config('filesystems.default')));
        $directory = $this->buildTemporaryDirectory(
            trim((string) config('formforge.uploads.temporary_directory', 'formforge/tmp'), '/'),
            $form,
            $field,
        );

        if ($disk === '') {
            throw new FormForgeException('Temporary upload disk cannot be empty.');
        }

        $originalName = $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension() ?: $file->extension() ?: '';
        $storedName = $this->storedFilename($originalName, $extension);
        $path = Storage::disk($disk)->putFileAs($directory, $file, $storedName);

        if (! is_string($path) || $path === '') {
            throw new FormForgeException('Unable to store staged upload file.');
        }

        $size = (int) ($file->getSize() ?? 0);
        $mimeType = (string) ($file->getClientMimeType() ?: $file->getMimeType() ?: Storage::disk($disk)->mimeType($path) ?: 'application/octet-stream');
        $checksum = $this->checksumFromUploadedFile($file, $disk, $path);
        $token = $this->newToken();
        $ttl = (int) config('formforge.uploads.temporary_ttl_minutes', 1440);
        $expiresAt = $ttl > 0 ? Carbon::now()->addMinutes($ttl) : null;

        ModelClassResolver::stagedUpload()::query()->create([
            'token' => $token,
            'form_key' => (string) ($form['key'] ?? ''),
            'form_version' => (string) ($form['version'] ?? ''),
            'field_key' => (string) ($field['field_key'] ?? $field['name'] ?? ''),
            'field_name' => (string) ($field['name'] ?? ''),
            'disk' => $disk,
            'path' => $path,
            'original_name' => $originalName,
            'stored_name' => $storedName,
            'mime_type' => $mimeType,
            'extension' => $extension,
            'size' => $size,
            'checksum' => $checksum,
            'metadata' => ['mode' => 'staged_token'],
            'uploaded_by_type' => $uploadedBy?->getMorphClass(),
            'uploaded_by_id' => $uploadedBy?->getKey(),
            'expires_at' => $expiresAt,
        ]);

        return [
            'upload_token' => $token,
            'expires_at' => $expiresAt?->toIso8601String(),
            'disk' => $disk,
            'path' => $path,
            'original_name' => $originalName,
            'stored_name' => $storedName,
            'mime_type' => $mimeType,
            'extension' => $extension,
            'size' => $size,
            'checksum' => $checksum,
        ];
    }

    public function lockAvailableToken(
        string $token,
        array $form,
        array $field,
        ?Model $submittedBy = null,
    ): StagedUpload {
        $candidate = trim($token);

        if ($candidate === '') {
            throw new FormForgeException('Upload token cannot be empty.');
        }

        $upload = ModelClassResolver::stagedUpload()::query()
            ->byToken($candidate)
            ->available()
            ->lockForUpdate()
            ->first();

        if (! $upload instanceof StagedUpload) {
            throw new FormForgeException('Upload token is invalid, expired, or already consumed.');
        }

        $this->assertTokenMatchesFormField($upload, $form, $field);
        $this->assertTokenOwnership($upload, $submittedBy);

        return $upload;
    }

    public function consume(StagedUpload $upload): void
    {
        $upload->forceFill([
            'consumed_at' => Carbon::now(),
        ])->save();
    }

    public function metadataFromToken(StagedUpload $upload): array
    {
        $metadata = $upload->metadata;

        if (! is_array($metadata)) {
            $metadata = [];
        }

        $metadata['mode'] = 'staged_token';
        $metadata['upload_token'] = (string) $upload->token;

        return [
            'disk' => (string) $upload->disk,
            'path' => (string) $upload->path,
            'original_name' => (string) $upload->original_name,
            'stored_name' => (string) $upload->stored_name,
            'mime_type' => (string) $upload->mime_type,
            'extension' => (string) ($upload->extension ?? ''),
            'size' => (int) ($upload->size ?? 0),
            'checksum' => (string) ($upload->checksum ?? ''),
            'metadata' => $metadata,
        ];
    }

    private function assertFileField(array $field): void
    {
        if ((string) ($field['type'] ?? '') !== 'file') {
            throw new FormForgeException('Only file fields can stage uploads.');
        }
    }

    private function validateUploadedFile(UploadedFile $file, array $field): void
    {
        $maxSize = isset($field['max_size']) ? (int) $field['max_size'] : null;

        if ($maxSize !== null && $maxSize > 0) {
            $size = (int) ($file->getSize() ?? 0);

            if ($size > $maxSize) {
                throw new FormForgeException("Staged upload exceeds max_size [{$maxSize}] bytes.");
            }
        }

        $accept = $field['accept'] ?? [];

        if (! is_array($accept) || $accept === []) {
            return;
        }

        $extension = strtolower((string) ($file->getClientOriginalExtension() ?: $file->extension() ?: ''));
        $mimeType = strtolower((string) ($file->getClientMimeType() ?: $file->getMimeType() ?: ''));

        $accepted = false;

        foreach ($accept as $raw) {
            $rule = strtolower(trim((string) $raw));

            if ($rule === '' || $rule === '*') {
                $accepted = true;
                break;
            }

            if ($rule === 'image/*' && str_starts_with($mimeType, 'image/')) {
                $accepted = true;
                break;
            }

            if (str_starts_with($rule, '.') && $extension !== '' && $extension === ltrim($rule, '.')) {
                $accepted = true;
                break;
            }

            if (str_contains($rule, '/')) {
                if ($mimeType === $rule) {
                    $accepted = true;
                    break;
                }

                continue;
            }

            if ($extension !== '' && $extension === $rule) {
                $accepted = true;
                break;
            }
        }

        if (! $accepted) {
            throw new FormForgeException('Staged upload does not match accepted file types.');
        }
    }

    private function assertTokenMatchesFormField(StagedUpload $upload, array $form, array $field): void
    {
        $formKey = (string) ($form['key'] ?? '');
        $formVersion = (string) ($form['version'] ?? '');
        $fieldKey = (string) ($field['field_key'] ?? $field['name'] ?? '');

        if ((string) $upload->form_key !== $formKey || (string) $upload->form_version !== $formVersion) {
            throw new FormForgeException('Upload token does not match the targeted form version.');
        }

        if ((string) $upload->field_key !== $fieldKey) {
            throw new FormForgeException('Upload token does not match the targeted file field.');
        }
    }

    private function assertTokenOwnership(StagedUpload $upload, ?Model $submittedBy): void
    {
        $requiresSameUser = (bool) config('formforge.uploads.staged.require_same_user', true);

        if (! $requiresSameUser) {
            return;
        }

        $uploadedByType = (string) ($upload->uploaded_by_type ?? '');
        $uploadedById = (string) ($upload->uploaded_by_id ?? '');

        if ($uploadedByType === '' || $uploadedById === '') {
            return;
        }

        $submittedByType = (string) ($submittedBy?->getMorphClass() ?? '');
        $submittedById = (string) ($submittedBy?->getKey() ?? '');

        if ($submittedByType !== $uploadedByType || $submittedById !== $uploadedById) {
            throw new FormForgeException('Upload token ownership mismatch.');
        }
    }

    private function buildTemporaryDirectory(string $baseDirectory, array $form, array $field): string
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

        try {
            $checksum = Storage::disk($disk)->checksum($path);

            if (is_string($checksum) && $checksum !== '') {
                return $checksum;
            }
        } catch (\Throwable) {
        }

        return '';
    }

    private function newToken(): string
    {
        return 'upl_' . Str::lower(Str::random(48));
    }

    private function deleteStagedFile(StagedUpload $upload, array &$stats): void
    {
        $disk = trim((string) $upload->disk);
        $path = trim((string) $upload->path);

        if ($disk === '' || $path === '') {
            $stats['files_missing']++;

            return;
        }

        try {
            if (! Storage::disk($disk)->exists($path)) {
                $stats['files_missing']++;

                return;
            }

            $deleted = Storage::disk($disk)->delete($path);

            if ($deleted) {
                $stats['files_deleted']++;
            } else {
                $stats['files_failed']++;
            }
        } catch (\Throwable) {
            $stats['files_failed']++;
        }
    }
}
