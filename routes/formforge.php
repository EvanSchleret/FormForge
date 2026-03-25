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

if (! is_array($middleware)) {
    $middleware = ['api'];
}

Route::prefix($prefix)
    ->middleware($middleware)
    ->group(static function (): void {
        Route::middleware('formforge.endpoint:schema')->group(static function (): void {
            Route::get('/forms/{key}', [FormSchemaController::class, 'latest']);
            Route::get('/forms/{key}/versions', [FormSchemaController::class, 'versions']);
            Route::get('/forms/{key}/versions/{version}', [FormSchemaController::class, 'show']);
        });

        Route::middleware('formforge.endpoint:submission')->group(static function (): void {
            Route::post('/forms/{key}/submit', [FormSubmissionController::class, 'submitLatest']);
            Route::post('/forms/{key}/versions/{version}/submit', [FormSubmissionController::class, 'submitVersion']);
        });

        Route::middleware('formforge.endpoint:upload')->group(static function (): void {
            Route::post('/forms/{key}/uploads/stage', [FormUploadController::class, 'stageLatest']);
            Route::post('/forms/{key}/versions/{version}/uploads/stage', [FormUploadController::class, 'stageVersion']);
        });

        Route::middleware('formforge.endpoint:resolve')->group(static function (): void {
            Route::post('/forms/{key}/resolve', [FormResolveController::class, 'resolveLatest']);
            Route::post('/forms/{key}/versions/{version}/resolve', [FormResolveController::class, 'resolveVersion']);
        });

        Route::middleware('formforge.endpoint:draft')->group(static function (): void {
            Route::post('/forms/{key}/drafts', [FormDraftController::class, 'save']);
            Route::get('/forms/{key}/drafts/current', [FormDraftController::class, 'current']);
            Route::delete('/forms/{key}/drafts/current', [FormDraftController::class, 'delete']);
        });

        Route::middleware('formforge.endpoint:management,index')->group(static function (): void {
            Route::get('/forms', [FormManagementController::class, 'index']);
        });

        Route::middleware('formforge.endpoint:management,create')->group(static function (): void {
            Route::post('/forms', [FormManagementController::class, 'create']);
        });

        Route::middleware('formforge.endpoint:management,update')->group(static function (): void {
            Route::patch('/forms/{key}', [FormManagementController::class, 'patch']);
        });

        Route::middleware('formforge.endpoint:management,publish')->group(static function (): void {
            Route::post('/forms/{key}/publish', [FormManagementController::class, 'publish']);
        });

        Route::middleware('formforge.endpoint:management,unpublish')->group(static function (): void {
            Route::post('/forms/{key}/unpublish', [FormManagementController::class, 'unpublish']);
        });

        Route::middleware('formforge.endpoint:management,delete')->group(static function (): void {
            Route::delete('/forms/{key}', [FormManagementController::class, 'delete']);
        });

        Route::middleware('formforge.endpoint:management,revisions')->group(static function (): void {
            Route::get('/forms/{key}/revisions', [FormManagementController::class, 'revisions']);
        });

        Route::middleware('formforge.endpoint:management,diff')->group(static function (): void {
            Route::get('/forms/{key}/diff/{fromVersion}/{toVersion}', [FormManagementController::class, 'diff']);
        });

        Route::middleware('formforge.endpoint:management,responses')->group(static function (): void {
            Route::get('/forms/{key}/responses', [FormManagementController::class, 'responses']);
        });

        Route::middleware('formforge.endpoint:management,response')->group(static function (): void {
            Route::get('/forms/{key}/responses/{submissionId}', [FormManagementController::class, 'response']);
        });

        Route::middleware('formforge.endpoint:management,response_delete')->group(static function (): void {
            Route::delete('/forms/{key}/responses/{submissionId}', [FormManagementController::class, 'deleteResponse']);
        });
    });
