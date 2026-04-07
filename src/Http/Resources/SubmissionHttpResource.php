<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Http\Resources;

use EvanSchleret\FormForge\Exceptions\FormForgeException;
use EvanSchleret\FormForge\Models\FormSubmission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class SubmissionHttpResource
{
    public function toArray(FormSubmission $submission, ?Request $request = null): array
    {
        $request = $request ?? $this->resolveRequest();
        $resourceClass = $this->normalizeResourceClass(config('formforge.http.resources.submission'));

        if ($resourceClass !== null) {
            return $this->resolveResource($resourceClass, $submission, $request);
        }

        $data = $submission->toArray();
        $data['id'] = (string) ($submission->uuid ?? '');
        unset($data['uuid']);

        if (array_key_exists('payload', $data)) {
            $data['payload'] = $this->attachFileUrls($data['payload']);
        }

        if (isset($data['files']) && is_array($data['files'])) {
            $data['files'] = array_map(function (mixed $file): array {
                if (! is_array($file)) {
                    return [];
                }

                unset($file['id'], $file['form_submission_id']);
                $file = $this->attachFileUrls($file);

                return $file;
            }, $data['files']);
        }

        $submitterResourceClass = $this->normalizeResourceClass(config('formforge.http.resources.submitter'));

        if ($submitterResourceClass !== null) {
            $submitter = $submission->relationLoaded('submitter')
                ? $submission->getRelation('submitter')
                : $this->resolveSubmitter($submission);

            $data['submitted_by'] = $submitter === null
                ? null
                : $this->resolveResource($submitterResourceClass, $submitter, $request);
        }

        return $data;
    }

    /**
     * @param iterable<FormSubmission> $submissions
     * @return array<int, array<string, mixed>>
     */
    public function collection(iterable $submissions, ?Request $request = null): array
    {
        $rows = [];

        foreach ($submissions as $submission) {
            if (! $submission instanceof FormSubmission) {
                continue;
            }

            $rows[] = $this->toArray($submission, $request);
        }

        return $rows;
    }

    private function resolveResource(string $resourceClass, mixed $resource, Request $request): array
    {
        if (! class_exists($resourceClass)) {
            throw new FormForgeException("Configured HTTP resource class [{$resourceClass}] does not exist.");
        }

        if (! is_subclass_of($resourceClass, JsonResource::class)) {
            throw new FormForgeException("Configured HTTP resource class [{$resourceClass}] must extend [" . JsonResource::class . '].');
        }

        /** @var JsonResource $instance */
        $instance = new $resourceClass($resource);

        $resolved = $instance->resolve($request);

        return is_array($resolved) ? $resolved : [];
    }

    private function normalizeResourceClass(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function resolveRequest(): Request
    {
        if (! app()->bound('request')) {
            return Request::create('/', 'GET');
        }

        $request = app('request');

        return $request instanceof Request ? $request : Request::create('/', 'GET');
    }

    private function attachFileUrls(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if ($this->looksLikeStoredFile($value)) {
            $url = $this->resolveFileUrl($value);
            $key = $this->fileUrlKey();

            if ($url !== null && $key !== '') {
                $value[$key] = $url;
            }
        }

        foreach ($value as $entryKey => $entryValue) {
            $value[$entryKey] = $this->attachFileUrls($entryValue);
        }

        return $value;
    }

    private function looksLikeStoredFile(array $value): bool
    {
        $disk = $value['disk'] ?? null;
        $path = $value['path'] ?? null;

        return is_string($disk) && trim($disk) !== '' && is_string($path) && trim($path) !== '';
    }

    private function resolveFileUrl(array $file): ?string
    {
        if (! (bool) config('formforge.http.resources.file_urls.enabled', false)) {
            return null;
        }

        $disk = trim((string) ($file['disk'] ?? ''));
        $path = trim((string) ($file['path'] ?? ''));

        if ($disk === '' || $path === '') {
            return null;
        }

        $temporary = (bool) config('formforge.http.resources.file_urls.temporary', true);
        $ttl = max(1, (int) config('formforge.http.resources.file_urls.ttl_seconds', 900));

        if ($temporary) {
            try {
                return Storage::disk($disk)->temporaryUrl($path, now()->addSeconds($ttl));
            } catch (\Throwable) {
            }
        }

        try {
            return Storage::disk($disk)->url($path);
        } catch (\Throwable) {
            return null;
        }
    }

    private function fileUrlKey(): string
    {
        $key = trim((string) config('formforge.http.resources.file_urls.key', 'url'));

        return $key === '' ? 'url' : $key;
    }

    private function resolveSubmitter(FormSubmission $submission): mixed
    {
        $submittedByType = trim((string) ($submission->submitted_by_type ?? ''));
        $submittedById = trim((string) ($submission->submitted_by_id ?? ''));

        if ($submittedByType === '' || $submittedById === '') {
            return null;
        }

        try {
            return $submission->submitter()->first();
        } catch (\Throwable) {
            return null;
        }
    }
}
