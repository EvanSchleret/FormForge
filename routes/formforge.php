<?php

declare(strict_types=1);

use EvanSchleret\FormForge\Http\Controllers\FormDraftController;
use EvanSchleret\FormForge\Http\Controllers\FormManagementController;
use EvanSchleret\FormForge\Http\Controllers\FormResolveController;
use EvanSchleret\FormForge\Http\Controllers\FormSchemaController;
use EvanSchleret\FormForge\Http\Controllers\FormSubmissionController;
use EvanSchleret\FormForge\Http\Controllers\FormUploadController;
use EvanSchleret\FormForge\Http\ScopedRouteManager;
use Illuminate\Support\Facades\Route;

$prefix = trim((string) config('formforge.http.prefix', 'api/formforge/v1'), '/');
$middleware = config('formforge.http.middleware', ['api']);
$endpoints = config('formforge.http.endpoints', []);
$controllers = config('formforge.http.controllers', []);
$scopedRouteManager = app(ScopedRouteManager::class);
$scopedRoutes = $scopedRouteManager->all();

if (! is_array($middleware)) {
    $middleware = ['api'];
}

if (! is_array($controllers)) {
    $controllers = [];
}

$endpointEnabled = static function (string $endpoint) use ($endpoints): bool {
    if (! is_array($endpoints) || ! array_key_exists($endpoint, $endpoints)) {
        return true;
    }

    return (bool) $endpoints[$endpoint];
};

$resolveController = static function (string $key, string $fallback) use ($controllers): string {
    $configured = $controllers[$key] ?? $fallback;

    if (! is_string($configured) || trim($configured) === '') {
        throw new \InvalidArgumentException("Invalid formforge.http.controllers.{$key} controller class.");
    }

    if ($configured !== $fallback && ! is_subclass_of($configured, $fallback)) {
        throw new \InvalidArgumentException("Configured formforge.http.controllers.{$key} must extend [{$fallback}].");
    }

    return $configured;
};

$schemaController = $resolveController('schema', FormSchemaController::class);
$submissionController = $resolveController('submission', FormSubmissionController::class);
$uploadController = $resolveController('upload', FormUploadController::class);
$resolveControllerClass = $resolveController('resolve', FormResolveController::class);
$draftController = $resolveController('draft', FormDraftController::class);
$managementController = $resolveController('management', FormManagementController::class);

$routeMiddleware = static function (string $endpoint, ?string $action = null, ?string $scopeName = null): array {
    $items = [];

    if (is_string($scopeName) && trim($scopeName) !== '') {
        $items[] = 'formforge.scope:' . trim($scopeName);
    }

    $items[] = is_string($action) && trim($action) !== ''
        ? 'formforge.endpoint:' . $endpoint . ',' . trim($action)
        : 'formforge.endpoint:' . $endpoint;

    return $items;
};

$registerRoutes = static function (?string $scopeName, callable $isEndpointEnabled) use (
    $routeMiddleware,
    $schemaController,
    $submissionController,
    $uploadController,
    $resolveControllerClass,
    $draftController,
    $managementController,
): void {
    if ($isEndpointEnabled('schema')) {
        Route::middleware($routeMiddleware('schema', 'latest', $scopeName))->get('/forms/{key}', [$schemaController, 'latest']);
        Route::middleware($routeMiddleware('schema', 'versions', $scopeName))->get('/forms/{key}/versions', [$schemaController, 'versions']);
        Route::middleware($routeMiddleware('schema', 'show', $scopeName))->get('/forms/{key}/versions/{version}', [$schemaController, 'show']);
    }

    if ($isEndpointEnabled('submission')) {
        Route::middleware($routeMiddleware('submission', 'submit_latest', $scopeName))->post('/forms/{key}/submit', [$submissionController, 'submitLatest']);
        Route::middleware($routeMiddleware('submission', 'submit_version', $scopeName))->post('/forms/{key}/versions/{version}/submit', [$submissionController, 'submitVersion']);
    }

    if ($isEndpointEnabled('upload')) {
        Route::middleware($routeMiddleware('upload', 'stage_latest', $scopeName))->post('/forms/{key}/uploads/stage', [$uploadController, 'stageLatest']);
        Route::middleware($routeMiddleware('upload', 'stage_version', $scopeName))->post('/forms/{key}/versions/{version}/uploads/stage', [$uploadController, 'stageVersion']);
    }

    if ($isEndpointEnabled('resolve')) {
        Route::middleware($routeMiddleware('resolve', 'resolve_latest', $scopeName))->post('/forms/{key}/resolve', [$resolveControllerClass, 'resolveLatest']);
        Route::middleware($routeMiddleware('resolve', 'resolve_version', $scopeName))->post('/forms/{key}/versions/{version}/resolve', [$resolveControllerClass, 'resolveVersion']);
    }

    if ($isEndpointEnabled('draft')) {
        Route::middleware($routeMiddleware('draft', 'save', $scopeName))->post('/forms/{key}/drafts', [$draftController, 'save']);
        Route::middleware($routeMiddleware('draft', 'current', $scopeName))->get('/forms/{key}/drafts/current', [$draftController, 'current']);
        Route::middleware($routeMiddleware('draft', 'delete', $scopeName))->delete('/forms/{key}/drafts/current', [$draftController, 'delete']);
    }

    if ($isEndpointEnabled('management')) {
        Route::middleware($routeMiddleware('management', 'index', $scopeName))->get('/forms', [$managementController, 'index']);
        Route::middleware($routeMiddleware('management', 'categories', $scopeName))->get('/categories', [$managementController, 'categories']);
        Route::middleware($routeMiddleware('management', 'category', $scopeName))->get('/categories/{categoryKey}', [$managementController, 'category']);
        Route::middleware($routeMiddleware('management', 'category_create', $scopeName))->post('/categories', [$managementController, 'createCategory']);
        Route::middleware($routeMiddleware('management', 'category_update', $scopeName))->patch('/categories/{categoryKey}', [$managementController, 'updateCategory']);
        Route::middleware($routeMiddleware('management', 'category_delete', $scopeName))->delete('/categories/{categoryKey}', [$managementController, 'deleteCategory']);
        Route::middleware($routeMiddleware('management', 'create', $scopeName))->post('/forms', [$managementController, 'create']);
        Route::middleware($routeMiddleware('management', 'update', $scopeName))->patch('/forms/{key}', [$managementController, 'patch']);
        Route::middleware($routeMiddleware('management', 'publish', $scopeName))->post('/forms/{key}/publish', [$managementController, 'publish']);
        Route::middleware($routeMiddleware('management', 'unpublish', $scopeName))->post('/forms/{key}/unpublish', [$managementController, 'unpublish']);
        Route::middleware($routeMiddleware('management', 'delete', $scopeName))->delete('/forms/{key}', [$managementController, 'delete']);
        Route::middleware($routeMiddleware('management', 'revisions', $scopeName))->get('/forms/{key}/revisions', [$managementController, 'revisions']);
        Route::middleware($routeMiddleware('management', 'diff', $scopeName))->get('/forms/{key}/diff/{fromVersion}/{toVersion}', [$managementController, 'diff']);
        Route::middleware($routeMiddleware('management', 'responses', $scopeName))->get('/forms/{key}/responses', [$managementController, 'responses']);
        Route::middleware($routeMiddleware('management', 'responses_export', $scopeName))->get('/forms/{key}/responses/export', [$managementController, 'exportResponses']);
        Route::middleware($routeMiddleware('management', 'gdpr_policy', $scopeName))->put('/forms/{key}/gdpr-policy', [$managementController, 'upsertGdprPolicy']);
        Route::middleware($routeMiddleware('management', 'response', $scopeName))->get('/forms/{key}/responses/{submissionUuid}', [$managementController, 'response']);
        Route::middleware($routeMiddleware('management', 'response_delete', $scopeName))->delete('/forms/{key}/responses/{submissionUuid}', [$managementController, 'deleteResponse']);
        Route::middleware($routeMiddleware('management', 'response_gdpr_anonymize', $scopeName))->post('/forms/{key}/responses/{submissionUuid}/gdpr/anonymize', [$managementController, 'anonymizeResponse']);
        Route::middleware($routeMiddleware('management', 'response_gdpr_delete', $scopeName))->post('/forms/{key}/responses/{submissionUuid}/gdpr/delete', [$managementController, 'deleteResponseByGdpr']);
        Route::middleware($routeMiddleware('management', 'gdpr_run', $scopeName))->post('/gdpr/run', [$managementController, 'runGdpr']);
    }
};

Route::prefix($prefix)
    ->middleware($middleware)
    ->group(static function () use ($registerRoutes, $endpointEnabled): void {
        $registerRoutes(null, $endpointEnabled);
    });

foreach ($scopedRoutes as $scopeName => $scope) {
    if (! is_array($scope) || ! (bool) ($scope['enabled'] ?? true)) {
        continue;
    }

    $scopePrefix = trim((string) ($scope['prefix'] ?? ''), '/');

    if ($scopePrefix === '') {
        continue;
    }

    $scopeMiddleware = $scope['middleware'] ?? [];

    if (! is_array($scopeMiddleware)) {
        $scopeMiddleware = [];
    }

    $scopeEnabled = static function (string $endpoint) use ($scopedRouteManager, $scope): bool {
        return $scopedRouteManager->endpointEnabled($scope, $endpoint);
    };

    Route::prefix(trim($prefix . '/' . $scopePrefix, '/'))
        ->middleware(array_values(array_unique([...$middleware, ...$scopeMiddleware])))
        ->group(static function () use ($registerRoutes, $scopeEnabled, $scopeName): void {
            $registerRoutes($scopeName, $scopeEnabled);
        });
}
