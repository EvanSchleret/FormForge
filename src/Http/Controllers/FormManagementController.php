<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Http\Controllers;

use EvanSchleret\FormForge\Exceptions\FormConflictException;
use EvanSchleret\FormForge\Exceptions\FormForgeException;
use EvanSchleret\FormForge\Exceptions\FormNotFoundException;
use EvanSchleret\FormForge\Http\Resources\SubmissionHttpResource;
use EvanSchleret\FormForge\Management\FormCategoryService;
use EvanSchleret\FormForge\Management\FormMutationService;
use EvanSchleret\FormForge\Management\IdempotencyService;
use EvanSchleret\FormForge\Models\FormDefinition;
use EvanSchleret\FormForge\Ownership\OwnershipReference;
use EvanSchleret\FormForge\Persistence\FormDefinitionRepository;
use EvanSchleret\FormForge\Submissions\SubmissionReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class FormManagementController
{
    public function categories(Request $request, FormCategoryService $categories): JsonResponse
    {
        $owner = $this->resolvedOwner($request, 'categories');
        $perPage = $this->boundedInt($request->query('per_page', 15), 1, 100, 15);
        $filters = [
            'search' => is_string($request->query('search')) ? trim((string) $request->query('search')) : null,
            'is_active' => $request->query('is_active'),
        ];

        $paginator = $categories->paginate($perPage, $filters, $owner);
        $paginator->appends($request->query());
        $rows = $paginator->getCollection()
            ->map(fn ($category): array => $categories->toArray($category))
            ->values()
            ->all();

        return response()->json([
            'data' => [
                'data' => $rows,
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

    public function category(Request $request, FormCategoryService $categories): JsonResponse
    {
        $categoryKey = $this->routeRequired($request, 'categoryKey');
        $owner = $this->resolvedOwner($request, 'category');
        $category = $categories->findByKey($categoryKey, $owner);

        if ($category === null) {
            throw new NotFoundHttpException("Category [{$categoryKey}] not found.");
        }

        return response()->json([
            'data' => $categories->toArray($category),
        ]);
    }

    public function createCategory(Request $request, FormCategoryService $categories): JsonResponse
    {
        $owner = $this->resolvedOwner($request, 'category_create');

        try {
            $category = $categories->create($request->all(), $owner);
        } catch (FormConflictException $exception) {
            throw new ConflictHttpException($exception->getMessage(), $exception);
        } catch (FormForgeException $exception) {
            throw ValidationException::withMessages([
                'category' => [$exception->getMessage()],
            ]);
        }

        return response()->json([
            'data' => $categories->toArray($category),
        ], 201);
    }

    public function updateCategory(Request $request, FormCategoryService $categories): JsonResponse
    {
        $categoryKey = $this->routeRequired($request, 'categoryKey');
        $owner = $this->resolvedOwner($request, 'category_update');

        try {
            $category = $categories->update($categoryKey, $request->all(), $owner);
        } catch (FormForgeException $exception) {
            if (str_contains($exception->getMessage(), 'not found')) {
                throw new NotFoundHttpException($exception->getMessage(), $exception);
            }

            throw ValidationException::withMessages([
                'category' => [$exception->getMessage()],
            ]);
        }

        return response()->json([
            'data' => $categories->toArray($category),
        ]);
    }

    public function deleteCategory(Request $request, FormCategoryService $categories): JsonResponse
    {
        $categoryKey = $this->routeRequired($request, 'categoryKey');
        $owner = $this->resolvedOwner($request, 'category_delete');

        try {
            $deleted = $categories->delete($categoryKey, $owner);
        } catch (FormConflictException $exception) {
            throw new ConflictHttpException($exception->getMessage(), $exception);
        }

        if (! $deleted) {
            throw new NotFoundHttpException("Category [{$categoryKey}] not found.");
        }

        return response()->json([
            'data' => [
                'key' => $categoryKey,
                'deleted' => true,
            ],
        ]);
    }

    public function responses(
        Request $request,
        FormDefinitionRepository $repository,
        SubmissionReadService $submissions,
        SubmissionHttpResource $resources,
    ): JsonResponse {
        $key = $this->routeRequired($request, 'key');
        $owner = $this->resolvedOwner($request, 'responses');

        $knownForm = $repository->keyExists($key, true, $owner);
        $knownBySubmissionOnly = $submissions->existsForForm($key, $owner);

        if (! $knownForm && ! $knownBySubmissionOnly) {
            throw new NotFoundHttpException("Form [{$key}] not found.");
        }

        $perPage = $this->boundedInt($request->query('per_page', 15), 1, 100, 15);
        $filters = [
            'version' => is_string($request->query('version')) ? trim((string) $request->query('version')) : null,
            'is_test' => $request->query('is_test'),
        ];

        $paginator = $submissions->paginateForForm($key, $perPage, $filters, $owner);
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
    ): JsonResponse {
        $key = $this->routeRequired($request, 'key');
        $submissionUuid = $this->routeRequired($request, 'submissionUuid');
        $owner = $this->resolvedOwner($request, 'response');

        $knownForm = $repository->keyExists($key, true, $owner);
        $knownBySubmissionOnly = $submissions->existsForForm($key, $owner);

        if (! $knownForm && ! $knownBySubmissionOnly) {
            throw new NotFoundHttpException("Form [{$key}] not found.");
        }

        $submissionUuid = trim($submissionUuid);
        $submission = $submissions->findForForm($key, $submissionUuid, $owner);

        if ($submission === null) {
            throw new NotFoundHttpException("Submission [{$submissionUuid}] not found for form [{$key}].");
        }

        return response()->json([
            'data' => $resources->toArray($submission, $request),
        ]);
    }

    public function deleteResponse(
        Request $request,
        FormDefinitionRepository $repository,
        SubmissionReadService $submissions,
    ): JsonResponse {
        $key = $this->routeRequired($request, 'key');
        $submissionUuid = $this->routeRequired($request, 'submissionUuid');
        $owner = $this->resolvedOwner($request, 'response_delete');

        $knownForm = $repository->keyExists($key, true, $owner);
        $knownBySubmissionOnly = $submissions->existsForForm($key, $owner);

        if (! $knownForm && ! $knownBySubmissionOnly) {
            throw new NotFoundHttpException("Form [{$key}] not found.");
        }

        $submissionUuid = trim($submissionUuid);
        $deleted = $submissions->deleteForForm($key, $submissionUuid, $owner);

        if (! $deleted) {
            throw new NotFoundHttpException("Submission [{$submissionUuid}] not found for form [{$key}].");
        }

        return response()->json([
            'data' => [
                'form_key' => $key,
                'submission_uuid' => $submissionUuid,
                'deleted' => true,
            ],
        ]);
    }

    public function index(Request $request, FormMutationService $mutations): JsonResponse
    {
        $owner = $this->resolvedOwner($request, 'index');
        $perPage = $this->boundedInt($request->query('per_page', 15), 1, 100, 15);
        $filters = [
            'category' => is_string($request->query('category')) ? trim((string) $request->query('category')) : null,
            'is_published' => $request->query('is_published'),
        ];

        $paginator = $mutations->queryActive($filters, $owner)->paginate($perPage);
        $paginator->appends($request->query());

        return response()->json([
            'data' => [
                'data' => $this->serializeFormDefinitionCollection($request, $mutations, $paginator->items()),
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
        $owner = $this->resolvedOwner($request, 'create');
        $payload = $request->all();
        $hash = $idempotency->payloadHash($payload);

        try {
            $replay = $idempotency->replay($this->idempotencyKey($request), 'management.create', 'POST', $hash);

            if ($replay !== null) {
                return response()->json($replay['body'], (int) $replay['status_code']);
            }

            $definition = $mutations->create($payload, $request->user(), $owner);
            $data = $this->serializeFormDefinition($request, $mutations, $definition);
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
    ): JsonResponse {
        $key = $this->routeRequired($request, 'key');
        $owner = $this->resolvedOwner($request, 'update');
        $payload = $request->all();
        $hash = $idempotency->payloadHash($payload);

        try {
            $replay = $idempotency->replay($this->idempotencyKey($request), 'management.update', 'PATCH', $hash);

            if ($replay !== null) {
                return response()->json($replay['body'], (int) $replay['status_code']);
            }

            $definition = $mutations->patch($key, $payload, $request->user(), $owner);
            $data = $this->serializeFormDefinition($request, $mutations, $definition);
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
    ): JsonResponse {
        $key = $this->routeRequired($request, 'key');
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
    ): JsonResponse {
        $key = $this->routeRequired($request, 'key');
        return $this->togglePublication(
            request: $request,
            mutations: $mutations,
            idempotency: $idempotency,
            key: $key,
            target: 'unpublish',
        );
    }

    public function delete(Request $request, FormMutationService $mutations): JsonResponse
    {
        $key = $this->routeRequired($request, 'key');
        $owner = $this->resolvedOwner($request, 'delete');

        try {
            $deleted = $mutations->softDelete($key, $request->user(), $owner);
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

    public function revisions(Request $request, FormMutationService $mutations): JsonResponse
    {
        $key = $this->routeRequired($request, 'key');
        $owner = $this->resolvedOwner($request, 'revisions');
        $includeDeleted = $this->toBool($request->query('include_deleted', false));

        try {
            $rows = $mutations->revisions($key, $includeDeleted, $owner);
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
    ): JsonResponse {
        $key = $this->routeRequired($request, 'key');
        $fromVersion = $this->routeIntRequired($request, 'fromVersion');
        $toVersion = $this->routeIntRequired($request, 'toVersion');
        $owner = $this->resolvedOwner($request, 'diff');
        $includeDeleted = $this->toBool($request->query('include_deleted', false));

        try {
            $diff = $mutations->diff($key, $fromVersion, $toVersion, $includeDeleted, $owner);
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
        $owner = $this->resolvedOwner($request, $target === 'publish' ? 'publish' : 'unpublish');
        $payload = $request->all();
        $hash = $idempotency->payloadHash($payload);
        $endpoint = $target === 'publish' ? 'management.publish' : 'management.unpublish';

        try {
            $replay = $idempotency->replay($this->idempotencyKey($request), $endpoint, 'POST', $hash);

            if ($replay !== null) {
                return response()->json($replay['body'], (int) $replay['status_code']);
            }

            $definition = $target === 'publish'
                ? $mutations->publish($key, $request->user(), $owner)
                : $mutations->unpublish($key, $request->user(), $owner);

            $data = $this->serializeFormDefinition($request, $mutations, $definition);
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

    protected function resolveOwner(Request $request, string $action): ?OwnershipReference
    {
        $candidate = $request->attributes->get('formforge.ownership.reference');

        return $candidate instanceof OwnershipReference ? $candidate : null;
    }

    protected function authorizeAction(Request $request, string $action, ?OwnershipReference $owner): void
    {
    }

    private function routeRequired(Request $request, string $name): string
    {
        $attribute = $request->attributes->get('formforge.route.' . $name);

        if (is_scalar($attribute)) {
            $resolved = trim((string) $attribute);

            if ($resolved !== '') {
                return $resolved;
            }
        }

        $value = $request->route($name);

        if (! is_scalar($value)) {
            throw new NotFoundHttpException("Route parameter [{$name}] is required.");
        }

        $resolved = trim((string) $value);

        if ($resolved === '') {
            throw new NotFoundHttpException("Route parameter [{$name}] is required.");
        }

        return $resolved;
    }

    private function routeIntRequired(Request $request, string $name): int
    {
        $value = $this->routeRequired($request, $name);

        if (! is_numeric($value)) {
            throw new NotFoundHttpException("Route parameter [{$name}] must be numeric.");
        }

        return (int) $value;
    }

    private function serializeFormDefinition(Request $request, FormMutationService $mutations, FormDefinition $definition): array
    {
        $resourceClass = $this->formDefinitionResourceClass();

        if ($resourceClass === null) {
            return $mutations->toDetailArray($definition);
        }

        $resource = new $resourceClass($definition);
        $resolved = $resource->toArray($request);

        return is_array($resolved) ? $resolved : [];
    }

    private function serializeFormDefinitionCollection(Request $request, FormMutationService $mutations, array $definitions): array
    {
        $resourceClass = $this->formDefinitionResourceClass();

        if ($resourceClass === null) {
            return array_values(array_map(
                static fn (FormDefinition $definition): array => $mutations->toDetailArray($definition),
                $definitions,
            ));
        }

        $resolved = $resourceClass::collection($definitions)->toArray($request);

        return is_array($resolved) ? array_values($resolved) : [];
    }

    private function formDefinitionResourceClass(): ?string
    {
        $configured = config('formforge.http.resources.form_definition');

        if (! is_string($configured) || trim($configured) === '') {
            return null;
        }

        if (! is_subclass_of($configured, JsonResource::class)) {
            return null;
        }

        return $configured;
    }

    private function resolvedOwner(Request $request, string $action): ?OwnershipReference
    {
        $owner = $this->resolveOwner($request, $action);
        $this->authorizeAction($request, $action, $owner);

        return $owner;
    }
}
