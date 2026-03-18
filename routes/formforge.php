<?php

declare(strict_types=1);

use EvanSchleret\FormForge\Http\Controllers\FormSchemaController;
use EvanSchleret\FormForge\Http\Controllers\FormSubmissionController;
use EvanSchleret\FormForge\Http\Controllers\FormUploadController;
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
    });
