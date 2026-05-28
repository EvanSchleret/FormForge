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
use EvanSchleret\FormForge\Support\ModelClassResolver;
use EvanSchleret\FormForge\Ownership\OwnershipReference;
use EvanSchleret\FormForge\Persistence\FormDefinitionRepository;
use EvanSchleret\FormForge\Submissions\SubmissionReadService;
use EvanSchleret\FormForge\Submissions\SubmissionExportService;
use EvanSchleret\FormForge\Submissions\SubmissionPrivacyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
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
        $filters = $this->submissionFilters($request);

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

    public function exportResponses(
        Request $request,
        FormDefinitionRepository $repository,
        SubmissionReadService $submissions,
        SubmissionExportService $exports,
    ): StreamedResponse {
        $key = $this->routeRequired($request, 'key');
        $owner = $this->resolvedOwner($request, 'responses_export');
        $knownForm = $repository->keyExists($key, true, $owner);
        $knownBySubmissionOnly = $submissions->existsForForm($key, $owner);

        if (! $knownForm && ! $knownBySubmissionOnly) {
            throw new NotFoundHttpException("Form [{$key}] not found.");
        }

        $format = is_string($request->query('format')) ? trim((string) $request->query('format')) : 'csv';
        $filename = is_string($request->query('filename')) ? trim((string) $request->query('filename')) : null;
        $withHeader = ! $this->toBool($request->query('no_header', false));

        return $exports->downloadResponse(
            formKey: $key,
            format: $format,
            filters: $this->submissionFilters($request),
            owner: $owner,
            filename: $filename,
            withHeader: $withHeader,
        );
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

    public function upsertGdprPolicy(
        Request $request,
        FormDefinitionRepository $repository,
        SubmissionReadService $submissions,
        SubmissionPrivacyService $privacy,
    ): JsonResponse {
        $key = $this->routeRequired($request, 'key');
        $owner = $this->resolvedOwner($request, 'gdpr_policy');
        $knownForm = $repository->keyExists($key, true, $owner);
        $knownBySubmissionOnly = $submissions->existsForForm($key, $owner);

        if (! $knownForm && ! $knownBySubmissionOnly) {
            throw new NotFoundHttpException("Form [{$key}] not found.");
        }

        try {
            $policy = $privacy->upsertFormPolicy($key, $request->all());
        } catch (FormForgeException $exception) {
            throw ValidationException::withMessages([
                'gdpr_policy' => [$exception->getMessage()],
            ]);
        }

        return response()->json([
            'data' => [
                'scope' => (string) $policy->scope,
                'form_key' => (string) ($policy->form_key ?? ''),
                'action' => (string) $policy->action,
                'after_days' => $policy->after_days,
                'anonymize_fields' => is_array($policy->anonymize_fields) ? $policy->anonymize_fields : [],
                'delete_files' => (bool) $policy->delete_files,
                'redact_submitter' => (bool) $policy->redact_submitter,
                'redact_network' => (bool) $policy->redact_network,
                'enabled' => (bool) $policy->enabled,
            ],
        ]);
    }

    public function anonymizeResponse(
        Request $request,
        SubmissionPrivacyService $privacy,
    ): JsonResponse {
        $key = $this->routeRequired($request, 'key');
        $submissionUuid = $this->routeRequired($request, 'submissionUuid');
        $owner = $this->resolvedOwner($request, 'response_gdpr_anonymize');
        $payload = $request->all();

        if (! array_key_exists('now', $payload) && ! array_key_exists('execute_at', $payload) && ! array_key_exists('at', $payload)) {
            $payload['now'] = true;
        }

        try {
            $result = $privacy->scheduleResponseAction(
                formKey: $key,
                submissionUuid: $submissionUuid,
                action: 'anonymize',
                input: $payload,
                owner: $owner,
                requestedBy: $request->user(),
            );
        } catch (FormForgeException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'not found')) {
                throw new NotFoundHttpException($exception->getMessage(), $exception);
            }

            throw ValidationException::withMessages([
                'gdpr' => [$exception->getMessage()],
            ]);
        }

        /** @var \EvanSchleret\FormForge\Models\SubmissionPrivacyOverride $override */
        $override = $result['override'];

        return response()->json([
            'data' => [
                'override_id' => (int) $override->getKey(),
                'action' => (string) $override->action,
                'execute_at' => $override->execute_at?->toIso8601String(),
                'processed_at' => $override->processed_at?->toIso8601String(),
                'executed' => (bool) $result['executed'],
                'anonymized' => (bool) $result['anonymized'],
                'deleted' => (bool) $result['deleted'],
            ],
        ]);
    }

    public function deleteResponseByGdpr(
        Request $request,
        SubmissionPrivacyService $privacy,
    ): JsonResponse {
        $key = $this->routeRequired($request, 'key');
        $submissionUuid = $this->routeRequired($request, 'submissionUuid');
        $owner = $this->resolvedOwner($request, 'response_gdpr_delete');
        $payload = $request->all();

        if (! array_key_exists('now', $payload) && ! array_key_exists('execute_at', $payload) && ! array_key_exists('at', $payload)) {
            $payload['now'] = true;
        }

        try {
            $result = $privacy->scheduleResponseAction(
                formKey: $key,
                submissionUuid: $submissionUuid,
                action: 'delete',
                input: $payload,
                owner: $owner,
                requestedBy: $request->user(),
            );
        } catch (FormForgeException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'not found')) {
                throw new NotFoundHttpException($exception->getMessage(), $exception);
            }

            throw ValidationException::withMessages([
                'gdpr' => [$exception->getMessage()],
            ]);
        }

        /** @var \EvanSchleret\FormForge\Models\SubmissionPrivacyOverride $override */
        $override = $result['override'];

        return response()->json([
            'data' => [
                'override_id' => (int) $override->getKey(),
                'action' => (string) $override->action,
                'execute_at' => $override->execute_at?->toIso8601String(),
                'processed_at' => $override->processed_at?->toIso8601String(),
                'executed' => (bool) $result['executed'],
                'anonymized' => (bool) $result['anonymized'],
                'deleted' => (bool) $result['deleted'],
            ],
        ]);
    }

    public function runGdpr(
        Request $request,
        SubmissionPrivacyService $privacy,
    ): JsonResponse {
        $owner = $this->resolvedOwner($request, 'gdpr_run');

        $result = $privacy->run([
            'dry_run' => $this->toBool($request->input('dry_run', $request->query('dry_run', false))),
            'chunk' => $request->input('chunk', $request->query('chunk')),
        ], $owner);

        return response()->json([
            'data' => $result,
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

    public function formRoute(
        Request $request,
        FormMutationService $mutations,
    ): JsonResponse {
        $routeKey = $this->routeRequired($request, 'routeKey');
        $owner = $this->resolvedOwner($request, 'form_route');
        $perPage = $this->boundedInt($request->query('per_page', 15), 1, 100, 15);

        $configured = config('formforge.http.query_routes.forms', []);
        $definition = is_array($configured) ? ($configured[$routeKey] ?? null) : null;

        if (! is_array($definition)) {
            throw new NotFoundHttpException("Form route [{$routeKey}] not found.");
        }

        $query = $mutations->queryActive([], $owner);
        $this->applyQueryRouteWhere($query, $definition['where'] ?? null, 'forms');

        $paginator = $query->paginate($perPage);
        $paginator->appends($request->query());

        return response()->json([
            'data' => [
                'data' => $this->serializeFormDefinitionCollection($request, $mutations, $paginator->items()),
            ],
            'meta' => [
                'route_key' => $routeKey,
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

    public function categoryRoute(
        Request $request,
        FormCategoryService $categories,
    ): JsonResponse {
        $routeKey = $this->routeRequired($request, 'routeKey');
        $owner = $this->resolvedOwner($request, 'category_route');
        $perPage = $this->boundedInt($request->query('per_page', 15), 1, 100, 15);
        $configured = config('formforge.http.query_routes.categories', []);
        $definition = is_array($configured) ? ($configured[$routeKey] ?? null) : null;

        if (! is_array($definition)) {
            throw new NotFoundHttpException("Category route [{$routeKey}] not found.");
        }

        $query = ModelClassResolver::formCategory()::query()->orderBy('key');
        app(\EvanSchleret\FormForge\Ownership\OwnershipManager::class)->applyScope($query, $owner);
        $this->applyQueryRouteWhere($query, $definition['where'] ?? null, 'categories');
        $paginator = $query->paginate($perPage);
        $paginator->appends($request->query());
        $rows = $paginator->getCollection()->map(fn ($category): array => $categories->toArray($category))->values()->all();

        return response()->json([
            'data' => ['data' => $rows],
            'meta' => [
                'route_key' => $routeKey,
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
            if ($this->shouldAutoPublish($payload)) {
                $definition = $mutations->publish((string) $definition->key, $request->user(), $owner);
            }
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
            if ($this->shouldAutoPublish($payload)) {
                $definition = $mutations->publish((string) $definition->key, $request->user(), $owner);
            }
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

    private function submissionFilters(Request $request): array
    {
        return [
            'version' => is_string($request->query('version')) ? trim((string) $request->query('version')) : null,
            'is_test' => $request->query('is_test'),
            'submitted_by_type' => is_string($request->query('submitted_by_type')) ? trim((string) $request->query('submitted_by_type')) : null,
            'submitted_by_id' => is_string($request->query('submitted_by_id')) ? trim((string) $request->query('submitted_by_id')) : null,
            'has_files' => $request->query('has_files'),
            'from' => is_string($request->query('from')) ? trim((string) $request->query('from')) : null,
            'to' => is_string($request->query('to')) ? trim((string) $request->query('to')) : null,
            'created_from' => is_string($request->query('created_from')) ? trim((string) $request->query('created_from')) : null,
            'created_to' => is_string($request->query('created_to')) ? trim((string) $request->query('created_to')) : null,
        ];
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

    private function shouldAutoPublish(array $payload): bool
    {
        if (array_key_exists('auto_publish', $payload)) {
            return $this->toBool($payload['auto_publish']);
        }

        if (array_key_exists('autoPublish', $payload)) {
            return $this->toBool($payload['autoPublish']);
        }

        return false;
    }

    private function resolvedOwner(Request $request, string $action): ?OwnershipReference
    {
        $owner = $this->resolveOwner($request, $action);
        $this->authorizeAction($request, $action, $owner);

        return $owner;
    }

    private function applyQueryRouteWhere(Builder $query, mixed $where, string $resource): void
    {
        if (! is_array($where)) {
            return;
        }
        if (array_key_exists('all', $where) && is_array($where['all'])) {
            $query->where(function (Builder $builder) use ($where, $resource): void {
                foreach ($where['all'] as $node) {
                    $this->applyQueryRouteNode($builder, $node, $resource, 'and');
                }
            });
            return;
        }
        if (array_key_exists('any', $where) && is_array($where['any'])) {
            $query->where(function (Builder $builder) use ($where, $resource): void {
                foreach ($where['any'] as $node) {
                    $this->applyQueryRouteNode($builder, $node, $resource, 'or');
                }
            });
        }
    }

    private function applyQueryRouteNode(Builder $query, mixed $node, string $resource, string $boolean): void
    {
        if (! is_array($node)) {
            return;
        }
        if (array_key_exists('all', $node) || array_key_exists('any', $node)) {
            $method = $boolean === 'or' ? 'orWhere' : 'where';
            $query->{$method}(function (Builder $builder) use ($node, $resource): void {
                $this->applyQueryRouteWhere($builder, $node, $resource);
            });
            return;
        }
        $field = is_string($node['field'] ?? null) ? trim((string) $node['field']) : '';
        $op = is_string($node['op'] ?? null) ? trim((string) $node['op']) : '';
        $value = $node['value'] ?? null;
        if ($field === '' || $op === '') {
            return;
        }
        if ($field === 'responses_count' || $field === 'forms_count') {
            $this->applyQueryRouteAggregateNode($query, $resource, $field, $op, $value, $boolean);
            return;
        }
        $column = $this->queryRouteColumn($resource, $field);
        $method = $boolean === 'or' ? 'orWhere' : 'where';
        if ($column === null) {
            throw ValidationException::withMessages([
                'route' => ["Unsupported field [{$field}] for resource [{$resource}]."],
            ]);
        }
        match ($op) {
            'eq' => $query->{$method}($column, '=', $value),
            'neq' => $query->{$method}($column, '!=', $value),
            'gt' => $query->{$method}($column, '>', $value),
            'gte' => $query->{$method}($column, '>=', $value),
            'lt' => $query->{$method}($column, '<', $value),
            'lte' => $query->{$method}($column, '<=', $value),
            'contains' => $query->{$method}($column, 'like', '%' . (string) $value . '%'),
            'starts_with' => $query->{$method}($column, 'like', (string) $value . '%'),
            'ends_with' => $query->{$method}($column, 'like', '%' . (string) $value),
            'is_null' => $boolean === 'or' ? $query->orWhereNull($column) : $query->whereNull($column),
            'not_null' => $boolean === 'or' ? $query->orWhereNotNull($column) : $query->whereNotNull($column),
            'in' => is_array($value) ? ($boolean === 'or' ? $query->orWhereIn($column, $value) : $query->whereIn($column, $value)) : null,
            'not_in' => is_array($value) ? ($boolean === 'or' ? $query->orWhereNotIn($column, $value) : $query->whereNotIn($column, $value)) : null,
            'between' => is_array($value) && count($value) === 2 ? ($boolean === 'or' ? $query->orWhereBetween($column, [$value[0], $value[1]]) : $query->whereBetween($column, [$value[0], $value[1]])) : null,
            default => throw ValidationException::withMessages([
                'route' => ["Unsupported operator [{$op}] for field [{$field}]."],
            ]),
        };
    }

    private function applyQueryRouteAggregateNode(Builder $query, string $resource, string $field, string $op, mixed $value, string $boolean): void
    {
        $formTable = ModelClassResolver::formDefinition()::query()->getModel()->getTable();
        $submissionTable = ModelClassResolver::formSubmission()::query()->getModel()->getTable();
        $categoryTable = ModelClassResolver::formCategory()::query()->getModel()->getTable();
        $operators = ['eq' => '=', 'neq' => '!=', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='];

        if (! array_key_exists($op, $operators) || ! is_numeric($value)) {
            throw ValidationException::withMessages([
                'route' => ["Invalid aggregate condition [{$field} {$op}]."],
            ]);
        }

        $sql = $field === 'responses_count' && $resource === 'forms'
            ? "(select count(*) from {$submissionTable} where form_key = {$formTable}.key) {$operators[$op]} ?"
            : "(select count(*) from {$formTable} where form_category_id = {$categoryTable}.id and deleted_at is null) {$operators[$op]} ?";

        if ($boolean === 'or') {
            $query->orWhereRaw($sql, [(int) $value]);
            return;
        }

        $query->whereRaw($sql, [(int) $value]);
    }

    private function queryRouteColumn(string $resource, string $field): ?string
    {
        $forms = ['key', 'title', 'category', 'is_published', 'created_at', 'updated_at', 'owner_type', 'owner_id'];
        $categories = ['key', 'slug', 'name', 'is_active', 'is_system', 'created_at', 'updated_at', 'owner_type', 'owner_id'];
        if ($resource === 'forms' && in_array($field, $forms, true)) {
            return $field;
        }
        if ($resource === 'categories' && in_array($field, $categories, true)) {
            return $field;
        }
        return null;
    }
}
