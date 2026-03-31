<?php

declare(strict_types=1);

use EvanSchleret\FormForge\Http\Controllers\FormSchemaController;
use EvanSchleret\FormForge\Http\Controllers\FormSubmissionController;
use EvanSchleret\FormForge\Http\Controllers\FormUploadController;
use EvanSchleret\FormForge\Http\Controllers\FormManagementController;
use EvanSchleret\FormForge\Http\Controllers\FormResolveController;
use EvanSchleret\FormForge\Http\Controllers\FormDraftController;
use Illuminate\Support\Facades\Route;

$prefix = trim((string) config('formforge.http.prefix', 'api/formforge/v1'), '/');
$middleware = config('formforge.http.middleware', ['api']);
$controllers = config('formforge.http.controllers', []);

if (! is_array($middleware)) {
    $middleware = ['api'];
}

if (! is_array($controllers)) {
    $controllers = [];
}

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

Route::prefix($prefix)
    ->middleware($middleware)
    ->group(static function () use (
        $schemaController,
        $submissionController,
        $uploadController,
        $resolveControllerClass,
        $draftController,
        $managementController,
    ): void {
        Route::middleware('formforge.endpoint:schema')->get('/forms/{key}', [$schemaController, 'latest']);
        Route::middleware('formforge.endpoint:schema')->get('/forms/{key}/versions', [$schemaController, 'versions']);
        Route::middleware('formforge.endpoint:schema')->get('/forms/{key}/versions/{version}', [$schemaController, 'show']);

        Route::middleware('formforge.endpoint:submission')->post('/forms/{key}/submit', [$submissionController, 'submitLatest']);
        Route::middleware('formforge.endpoint:submission')->post('/forms/{key}/versions/{version}/submit', [$submissionController, 'submitVersion']);

        Route::middleware('formforge.endpoint:upload')->post('/forms/{key}/uploads/stage', [$uploadController, 'stageLatest']);
        Route::middleware('formforge.endpoint:upload')->post('/forms/{key}/versions/{version}/uploads/stage', [$uploadController, 'stageVersion']);

        Route::middleware('formforge.endpoint:resolve')->post('/forms/{key}/resolve', [$resolveControllerClass, 'resolveLatest']);
        Route::middleware('formforge.endpoint:resolve')->post('/forms/{key}/versions/{version}/resolve', [$resolveControllerClass, 'resolveVersion']);

        Route::middleware('formforge.endpoint:draft')->post('/forms/{key}/drafts', [$draftController, 'save']);
        Route::middleware('formforge.endpoint:draft')->get('/forms/{key}/drafts/current', [$draftController, 'current']);
        Route::middleware('formforge.endpoint:draft')->delete('/forms/{key}/drafts/current', [$draftController, 'delete']);

        Route::middleware('formforge.endpoint:management,index')->get('/forms', [$managementController, 'index']);
        Route::middleware('formforge.endpoint:management,categories')->get('/categories', [$managementController, 'categories']);
        Route::middleware('formforge.endpoint:management,category')->get('/categories/{categoryKey}', [$managementController, 'category']);
        Route::middleware('formforge.endpoint:management,category_create')->post('/categories', [$managementController, 'createCategory']);
        Route::middleware('formforge.endpoint:management,category_update')->patch('/categories/{categoryKey}', [$managementController, 'updateCategory']);
        Route::middleware('formforge.endpoint:management,category_delete')->delete('/categories/{categoryKey}', [$managementController, 'deleteCategory']);
        Route::middleware('formforge.endpoint:management,create')->post('/forms', [$managementController, 'create']);
        Route::middleware('formforge.endpoint:management,update')->patch('/forms/{key}', [$managementController, 'patch']);
        Route::middleware('formforge.endpoint:management,publish')->post('/forms/{key}/publish', [$managementController, 'publish']);
        Route::middleware('formforge.endpoint:management,unpublish')->post('/forms/{key}/unpublish', [$managementController, 'unpublish']);
        Route::middleware('formforge.endpoint:management,delete')->delete('/forms/{key}', [$managementController, 'delete']);
        Route::middleware('formforge.endpoint:management,revisions')->get('/forms/{key}/revisions', [$managementController, 'revisions']);
        Route::middleware('formforge.endpoint:management,diff')->get('/forms/{key}/diff/{fromVersion}/{toVersion}', [$managementController, 'diff']);
        Route::middleware('formforge.endpoint:management,responses')->get('/forms/{key}/responses', [$managementController, 'responses']);
        Route::middleware('formforge.endpoint:management,response')->get('/forms/{key}/responses/{submissionUuid}', [$managementController, 'response']);
        Route::middleware('formforge.endpoint:management,response_delete')->delete('/forms/{key}/responses/{submissionUuid}', [$managementController, 'deleteResponse']);
    });
