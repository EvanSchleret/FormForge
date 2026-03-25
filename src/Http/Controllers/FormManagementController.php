<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Http\Controllers;

use EvanSchleret\FormForge\Exceptions\FormConflictException;
use EvanSchleret\FormForge\Exceptions\FormForgeException;
use EvanSchleret\FormForge\Exceptions\FormNotFoundException;
use EvanSchleret\FormForge\Http\Resources\SubmissionHttpResource;
use EvanSchleret\FormForge\Management\FormMutationService;
use EvanSchleret\FormForge\Management\IdempotencyService;
use EvanSchleret\FormForge\Persistence\FormDefinitionRepository;
use EvanSchleret\FormForge\Submissions\SubmissionReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class FormManagementController
{
    public function responses(
        Request $request,
        FormDefinitionRepository $repository,
        SubmissionReadService $submissions,
        SubmissionHttpResource $resources,
        string $key,
    ): JsonResponse {
        if (! $repository->keyExists($key, true) && ! $submissions->existsForForm($key)) {
            throw new NotFoundHttpException("Form [{$key}] not found.");
        }

        $perPage = $this->boundedInt($request->query('per_page', 15), 1, 100, 15);
        $filters = [
            'version' => is_string($request->query('version')) ? trim((string) $request->query('version')) : null,
            'is_test' => $request->query('is_test'),
        ];

        $paginator = $submissions->paginateForForm($key, $perPage, $filters);
        $paginator->appends($request->query());

        return response()->json([
            'data' => [
                'data' => $resources->collection($paginator->items(), $request),
            ],
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'from' => $paginator->firstItem(),
                'last_page' => $paginator->lastPage(),
                'path' => $paginator->path(),
                'per_page' => $paginator->perPage(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
            ],
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ]);
    }

    public function response(
        Request $request,
        FormDefinitionRepository $repository,
        SubmissionReadService $submissions,
        SubmissionHttpResource $resources,
        string $key,
        string $submissionId,
    ): JsonResponse {
        if (! $repository->keyExists($key, true) && ! $submissions->existsForForm($key)) {
            throw new NotFoundHttpException("Form [{$key}] not found.");
        }

        if (! ctype_digit($submissionId)) {
            throw new NotFoundHttpException("Submission [{$submissionId}] not found for form [{$key}].");
        }

        $submission = $submissions->findForForm($key, (int) $submissionId);

        if ($submission === null) {
            throw new NotFoundHttpException("Submission [{$submissionId}] not found for form [{$key}].");
        }

        return response()->json([
            'data' => $resources->toArray($submission, $request),
        ]);
    }

    public function deleteResponse(
        FormDefinitionRepository $repository,
        SubmissionReadService $submissions,
        string $key,
        string $submissionId,
    ): JsonResponse {
        if (! $repository->keyExists($key, true) && ! $submissions->existsForForm($key)) {
            throw new NotFoundHttpException("Form [{$key}] not found.");
        }

        if (! ctype_digit($submissionId)) {
            throw new NotFoundHttpException("Submission [{$submissionId}] not found for form [{$key}].");
        }

        $deleted = $submissions->deleteForForm($key, (int) $submissionId);

        if (! $deleted) {
            throw new NotFoundHttpException("Submission [{$submissionId}] not found for form [{$key}].");
        }

        return response()->json([
            'data' => [
                'form_key' => $key,
                'submission_id' => (int) $submissionId,
                'deleted' => true,
            ],
        ]);
    }

    public function index(Request $request, FormMutationService $mutations): JsonResponse
    {
        $perPage = $this->boundedInt($request->query('per_page', 15), 1, 100, 15);
        $filters = [
            'category' => is_string($request->query('category')) ? trim((string) $request->query('category')) : null,
            'is_published' => $request->query('is_published'),
        ];

        $paginator = $mutations->paginateActive($perPage, $filters);
        $paginator->appends($request->query());

        return response()->json([
            'data' => [
                'data' => $paginator->items(),
            ],
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'from' => $paginator->firstItem(),
                'last_page' => $paginator->lastPage(),
                'path' => $paginator->path(),
                'per_page' => $paginator->perPage(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
            ],
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ]);
    }

    public function create(Request $request, FormMutationService $mutations, IdempotencyService $idempotency): JsonResponse
    {
        $payload = $request->all();
        $hash = $idempotency->payloadHash($payload);

        try {
            $replay = $idempotency->replay($this->idempotencyKey($request), 'management.create', 'POST', $hash);

            if ($replay !== null) {
                return response()->json($replay['body'], (int) $replay['status_code']);
            }

            $definition = $mutations->create($payload, $request->user());
            $data = $mutations->toDetailArray($definition);
            $body = [
                'data' => $data,
                'meta' => ['replayed' => false],
            ];

            $idempotency->store(
                idempotencyKey: $this->idempotencyKey($request),
                endpoint: 'management.create',
                method: 'POST',
                resourceKey: (string) $definition->key,
                requestHash: $hash,
                statusCode: 201,
                responseBody: $body,
                revisionId: (string) ($definition->revision_id ?? null),
                versionNumber: (int) ($definition->version_number ?? 0),
            );
        } catch (FormConflictException $exception) {
            throw new ConflictHttpException($exception->getMessage(), $exception);
        } catch (FormForgeException $exception) {
            throw ValidationException::withMessages([
                'form' => [$exception->getMessage()],
            ]);
        }

        return response()->json($body, 201);
    }

    public function patch(
        Request $request,
        FormMutationService $mutations,
        IdempotencyService $idempotency,
        string $key,
    ): JsonResponse {
        $payload = $request->all();
        $hash = $idempotency->payloadHash($payload);

        try {
            $replay = $idempotency->replay($this->idempotencyKey($request), 'management.update', 'PATCH', $hash);

            if ($replay !== null) {
                return response()->json($replay['body'], (int) $replay['status_code']);
            }

            $definition = $mutations->patch($key, $payload, $request->user());
            $data = $mutations->toDetailArray($definition);
            $body = [
                'data' => $data,
                'meta' => ['replayed' => false],
            ];

            $idempotency->store(
                idempotencyKey: $this->idempotencyKey($request),
                endpoint: 'management.update',
                method: 'PATCH',
                resourceKey: (string) $definition->key,
                requestHash: $hash,
                statusCode: 200,
                responseBody: $body,
                revisionId: (string) ($definition->revision_id ?? null),
                versionNumber: (int) ($definition->version_number ?? 0),
            );
        } catch (FormNotFoundException $exception) {
            throw new NotFoundHttpException($exception->getMessage(), $exception);
        } catch (FormConflictException $exception) {
            throw new ConflictHttpException($exception->getMessage(), $exception);
        } catch (FormForgeException $exception) {
            throw ValidationException::withMessages([
                'form' => [$exception->getMessage()],
            ]);
        }

        return response()->json($body);
    }

    public function publish(
        Request $request,
        FormMutationService $mutations,
        IdempotencyService $idempotency,
        string $key,
    ): JsonResponse {
        return $this->togglePublication(
            request: $request,
            mutations: $mutations,
            idempotency: $idempotency,
            key: $key,
            target: 'publish',
        );
    }

    public function unpublish(
        Request $request,
        FormMutationService $mutations,
        IdempotencyService $idempotency,
        string $key,
    ): JsonResponse {
        return $this->togglePublication(
            request: $request,
            mutations: $mutations,
            idempotency: $idempotency,
            key: $key,
            target: 'unpublish',
        );
    }

    public function delete(Request $request, FormMutationService $mutations, string $key): JsonResponse
    {
        try {
            $deleted = $mutations->softDelete($key, $request->user());
        } catch (FormNotFoundException $exception) {
            throw new NotFoundHttpException($exception->getMessage(), $exception);
        }

        return response()->json([
            'data' => [
                'key' => $key,
                'deleted_revisions' => $deleted,
            ],
        ]);
    }

    public function revisions(Request $request, FormMutationService $mutations, string $key): JsonResponse
    {
        $includeDeleted = $this->toBool($request->query('include_deleted', false));

        try {
            $rows = $mutations->revisions($key, $includeDeleted);
        } catch (FormNotFoundException $exception) {
            throw new NotFoundHttpException($exception->getMessage(), $exception);
        }

        return response()->json([
            'data' => [
                'key' => $key,
                'revisions' => $rows,
            ],
        ]);
    }

    public function diff(
        Request $request,
        FormMutationService $mutations,
        string $key,
        int $fromVersion,
        int $toVersion,
    ): JsonResponse {
        $includeDeleted = $this->toBool($request->query('include_deleted', false));

        try {
            $diff = $mutations->diff($key, $fromVersion, $toVersion, $includeDeleted);
        } catch (FormNotFoundException $exception) {
            throw new NotFoundHttpException($exception->getMessage(), $exception);
        }

        return response()->json([
            'data' => $diff,
        ]);
    }

    private function togglePublication(
        Request $request,
        FormMutationService $mutations,
        IdempotencyService $idempotency,
        string $key,
        string $target,
    ): JsonResponse {
        $payload = $request->all();
        $hash = $idempotency->payloadHash($payload);
        $endpoint = $target === 'publish' ? 'management.publish' : 'management.unpublish';

        try {
            $replay = $idempotency->replay($this->idempotencyKey($request), $endpoint, 'POST', $hash);

            if ($replay !== null) {
                return response()->json($replay['body'], (int) $replay['status_code']);
            }

            $definition = $target === 'publish'
                ? $mutations->publish($key, $request->user())
                : $mutations->unpublish($key, $request->user());

            $data = $mutations->toDetailArray($definition);
            $body = [
                'data' => $data,
                'meta' => ['replayed' => false],
            ];

            $idempotency->store(
                idempotencyKey: $this->idempotencyKey($request),
                endpoint: $endpoint,
                method: 'POST',
                resourceKey: (string) $definition->key,
                requestHash: $hash,
                statusCode: 200,
                responseBody: $body,
                revisionId: (string) ($definition->revision_id ?? null),
                versionNumber: (int) ($definition->version_number ?? 0),
            );
        } catch (FormNotFoundException $exception) {
            throw new NotFoundHttpException($exception->getMessage(), $exception);
        } catch (FormConflictException $exception) {
            throw new ConflictHttpException($exception->getMessage(), $exception);
        } catch (FormForgeException $exception) {
            throw ValidationException::withMessages([
                'form' => [$exception->getMessage()],
            ]);
        }

        return response()->json($body);
    }

    private function idempotencyKey(Request $request): ?string
    {
        $value = $request->header('Idempotency-Key');

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value !== 0.0;
        }

        if (! is_string($value)) {
            return false;
        }

        $normalized = strtolower(trim($value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    private function boundedInt(mixed $value, int $min, int $max, int $default): int
    {
        if (is_int($value)) {
            $candidate = $value;
        } elseif (is_numeric($value)) {
            $candidate = (int) $value;
        } else {
            return $default;
        }

        if ($candidate < $min) {
            return $min;
        }

        if ($candidate > $max) {
            return $max;
        }

        return $candidate;
    }
}
