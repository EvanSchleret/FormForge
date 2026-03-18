<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Http\Controllers;

use EvanSchleret\FormForge\Exceptions\FormForgeException;
use EvanSchleret\FormForge\Exceptions\FormNotFoundException;
use EvanSchleret\FormForge\FormInstance;
use EvanSchleret\FormForge\FormManager;
use EvanSchleret\FormForge\Submissions\UploadManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class FormUploadController
{
    public function stageLatest(Request $request, FormManager $forms, UploadManager $uploads, string $key): JsonResponse
    {
        $version = $request->input('version');

        if (! is_string($version) || trim($version) === '') {
            $version = null;
        }

        return $this->stage($request, $forms, $uploads, $key, $version);
    }

    public function stageVersion(
        Request $request,
        FormManager $forms,
        UploadManager $uploads,
        string $key,
        string $version,
    ): JsonResponse {
        return $this->stage($request, $forms, $uploads, $key, $version);
    }

    private function stage(
        Request $request,
        FormManager $forms,
        UploadManager $uploads,
        string $key,
        ?string $version,
    ): JsonResponse {
        try {
            if ((string) config('formforge.uploads.mode', 'managed') !== 'staged') {
                throw new FormForgeException('Upload staging endpoint requires uploads.mode=staged.');
            }

            $form = $request->attributes->get('formforge.form');

            if (! $form instanceof FormInstance || $form->key() !== $key || ($version !== null && $form->version() !== $version)) {
                $form = $forms->get($key, $version);
            }

            $this->assertUploadAllowed($form);
            $field = $this->resolveField($form, $request);
            $file = $this->resolveUploadedFile($request, $field);

            $staged = $uploads->stage(
                form: $form->toArray(),
                field: $field,
                file: $file,
                uploadedBy: $request->user(),
            );
        } catch (FormNotFoundException $exception) {
            throw new NotFoundHttpException($exception->getMessage(), $exception);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (FormForgeException $exception) {
            throw ValidationException::withMessages([
                'upload' => [$exception->getMessage()],
            ]);
        }

        return response()->json([
            'data' => [
                'form_key' => $form->key(),
                'form_version' => $form->version(),
                'field_key' => (string) ($field['field_key'] ?? ''),
                'field_name' => (string) ($field['name'] ?? ''),
                'staged' => $staged,
            ],
        ], 201);
    }

    private function resolveField(FormInstance $form, Request $request): array
    {
        $fieldKey = trim((string) $request->input('field_key', ''));
        $fieldName = trim((string) $request->input('field', $request->input('field_name', '')));

        foreach ($form->fields() as $field) {
            if (! is_array($field)) {
                continue;
            }

            if ($fieldKey !== '' && (string) ($field['field_key'] ?? '') === $fieldKey) {
                return $field;
            }

            if ($fieldName !== '' && (string) ($field['name'] ?? '') === $fieldName) {
                return $field;
            }
        }

        throw ValidationException::withMessages([
            'field' => ['Unable to resolve a file field. Provide field_key or field name.'],
        ]);
    }

    private function resolveUploadedFile(Request $request, array $field): UploadedFile
    {
        $file = $request->file('file');

        if ($file instanceof UploadedFile) {
            return $file;
        }

        $fieldName = (string) ($field['name'] ?? '');
        $fieldFile = $fieldName !== '' ? $request->file($fieldName) : null;

        if ($fieldFile instanceof UploadedFile) {
            return $fieldFile;
        }

        throw ValidationException::withMessages([
            'file' => ['A file upload is required. Send multipart/form-data with a file part.'],
        ]);
    }

    private function assertUploadAllowed(FormInstance $form): void
    {
        $requiresPublished = (bool) config('formforge.http.upload.require_published', false);

        if (! $requiresPublished || $form->isPublished()) {
            return;
        }

        throw ValidationException::withMessages([
            'form' => ['This form is not published yet.'],
        ]);
    }
}
