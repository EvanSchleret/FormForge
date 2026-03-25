<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Http\Resources;

use EvanSchleret\FormForge\Exceptions\FormForgeException;
use EvanSchleret\FormForge\Models\FormSubmission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
        $submitterResourceClass = $this->normalizeResourceClass(config('formforge.http.resources.submitter'));

        if ($submitterResourceClass !== null) {
            $submitter = $submission->relationLoaded('submitter')
                ? $submission->getRelation('submitter')
                : $submission->submitter()->first();

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
}
