<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Http\Controllers;

use EvanSchleret\FormForge\Exceptions\FormForgeException;
use EvanSchleret\FormForge\Exceptions\FormNotFoundException;
use EvanSchleret\FormForge\FormInstance;
use EvanSchleret\FormForge\FormManager;
use EvanSchleret\FormForge\Http\Resources\SubmissionHttpResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class FormSubmissionController
{
    public function submitLatest(Request $request, FormManager $forms, SubmissionHttpResource $resources, string $key): JsonResponse
    {
        $version = $request->input('version');

        if (! is_string($version) || trim($version) === '') {
            $version = null;
        }

        return $this->submit($request, $forms, $resources, $key, $version);
    }

    public function submitVersion(Request $request, FormManager $forms, SubmissionHttpResource $resources, string $key, string $version): JsonResponse
    {
        return $this->submit($request, $forms, $resources, $key, $version);
    }

    private function submit(
        Request $request,
        FormManager $forms,
        SubmissionHttpResource $resources,
        string $key,
        ?string $version,
    ): JsonResponse
    {
        try {
            $form = $request->attributes->get('formforge.form');

            if (! $form instanceof FormInstance || $form->key() !== $key || ($version !== null && $form->version() !== $version)) {
                $form = $forms->get($key, $version);
            }

            $isTest = $this->resolveTestMode($request);
            $this->assertSubmissionAllowed($form, $isTest);

            $submission = $form->submit(
                payload: $this->extractPayload($request),
                submittedBy: $request->user(),
                request: $request,
                isTest: $isTest,
                submissionMeta: $this->extractSubmissionMeta($request, $isTest),
            );
        } catch (FormNotFoundException $exception) {
            throw new NotFoundHttpException($exception->getMessage(), $exception);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (FormForgeException $exception) {
            throw ValidationException::withMessages([
                'form' => [$exception->getMessage()],
            ]);
        }

        return response()->json([
            'data' => $resources->toArray($submission, $request),
        ], 201);
    }

    private function extractPayload(Request $request): array
    {
        $payload = $request->input('payload');

        if (is_array($payload)) {
            $merged = $payload;

            foreach ($request->allFiles() as $key => $file) {
                if (! array_key_exists($key, $merged)) {
                    $merged[$key] = $file;
                }
            }

            $flag = trim((string) config('formforge.submissions.testing.flag', '_formforge_test'));

            if ($flag !== '') {
                unset($merged[$flag]);
            }

            return $merged;
        }

        $all = $request->all();
        unset($all['version'], $all['payload'], $all['meta']);

        $flag = trim((string) config('formforge.submissions.testing.flag', '_formforge_test'));

        if ($flag !== '') {
            unset($all[$flag]);
        }

        return is_array($all) ? $all : [];
    }

    private function resolveTestMode(Request $request): bool
    {
        $enabled = (bool) config('formforge.submissions.testing.enabled', true);
        $headerName = trim((string) config('formforge.submissions.testing.header', 'X-FormForge-Test'));
        $flagName = trim((string) config('formforge.submissions.testing.flag', '_formforge_test'));
        $requested = false;
        $value = null;

        if ($headerName !== '' && $request->hasHeader($headerName)) {
            $requested = true;
            $value = $request->header($headerName);
        } elseif ($flagName !== '' && $request->exists($flagName)) {
            $requested = true;
            $value = $request->input($flagName);
        }

        if (! $requested) {
            return false;
        }

        $resolved = $this->toBoolean($value);

        if ($resolved === null) {
            throw ValidationException::withMessages([
                'test' => ['Invalid test mode flag value.'],
            ]);
        }

        if ($resolved && ! $enabled) {
            throw ValidationException::withMessages([
                'test' => ['Test submissions are disabled.'],
            ]);
        }

        if ($resolved && ! $this->isTestModeAvailable()) {
            throw ValidationException::withMessages([
                'test' => ['Test submissions are not available in this environment.'],
            ]);
        }

        return $resolved && $enabled;
    }

    private function assertSubmissionAllowed(FormInstance $form, bool $isTest): void
    {
        $requiresPublished = (bool) config('formforge.http.submission.require_published', true);

        if (! $requiresPublished || $form->isPublished()) {
            return;
        }

        $allowUnpublishedTest = (bool) config('formforge.submissions.testing.allow_on_unpublished', true);

        if ($isTest && $allowUnpublishedTest) {
            return;
        }

        throw ValidationException::withMessages([
            'form' => ['This form is not published yet.'],
        ]);
    }

    private function extractSubmissionMeta(Request $request, bool $isTest): array
    {
        $meta = $request->input('meta');

        if (! is_array($meta)) {
            $meta = [];
        }

        $meta['mode'] = $isTest ? 'test' : 'live';
        $meta['channel'] = 'http';

        return $meta;
    }

    private function toBoolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value !== 0.0;
        }

        if (! is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));

        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return null;
    }

    private function isTestModeAvailable(): bool
    {
        $environments = config('formforge.submissions.testing.enabled_environments', ['local', 'testing']);

        if (! is_array($environments) || $environments === []) {
            return false;
        }

        $environments = array_values(array_filter(array_map(static fn (mixed $value): string => trim((string) $value), $environments), static fn (string $value): bool => $value !== ''));

        if ($environments === []) {
            return false;
        }

        return app()->environment($environments);
    }
}
