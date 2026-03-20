<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Http\Controllers;

use EvanSchleret\FormForge\Exceptions\FormConflictException;
use EvanSchleret\FormForge\Exceptions\FormForgeException;
use EvanSchleret\FormForge\Exceptions\FormNotFoundException;
use EvanSchleret\FormForge\Management\FormMutationService;
use EvanSchleret\FormForge\Management\IdempotencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class FormManagementController
{
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
}
