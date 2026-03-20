<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Http\Controllers;

use EvanSchleret\FormForge\Exceptions\FormForgeException;
use EvanSchleret\FormForge\Exceptions\FormNotFoundException;
use EvanSchleret\FormForge\FormInstance;
use EvanSchleret\FormForge\FormManager;
use EvanSchleret\FormForge\Models\FormDraft;
use EvanSchleret\FormForge\Submissions\DraftStateService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Illuminate\Auth\AuthenticationException;

class FormDraftController
{
    public function save(Request $request, FormManager $forms, DraftStateService $drafts, string $key): JsonResponse
    {
        try {
            $form = $this->resolveForm($request, $forms, $key);
            $owner = $this->resolveOwner($request);
            $draft = $drafts->save($form, $this->extractPayload($request), $this->extractMeta($request), $owner);
        } catch (FormNotFoundException $exception) {
            throw new NotFoundHttpException($exception->getMessage(), $exception);
        } catch (FormForgeException $exception) {
            throw ValidationException::withMessages([
                'draft' => [$exception->getMessage()],
            ]);
        }

        return response()->json([
            'data' => $this->toDraftArray($draft),
        ]);
    }

    public function current(Request $request, FormManager $forms, DraftStateService $drafts, string $key): JsonResponse
    {
        try {
            $form = $this->resolveForm($request, $forms, $key);
            $owner = $this->resolveOwner($request);
            $draft = $drafts->current($form, $owner);
        } catch (FormNotFoundException $exception) {
            throw new NotFoundHttpException($exception->getMessage(), $exception);
        } catch (FormForgeException $exception) {
            throw ValidationException::withMessages([
                'draft' => [$exception->getMessage()],
            ]);
        }

        return response()->json([
            'data' => $draft instanceof FormDraft ? $this->toDraftArray($draft) : null,
        ]);
    }

    public function delete(Request $request, FormManager $forms, DraftStateService $drafts, string $key): JsonResponse
    {
        try {
            $form = $this->resolveForm($request, $forms, $key);
            $owner = $this->resolveOwner($request);
            $deleted = $drafts->delete($form, $owner);
        } catch (FormNotFoundException $exception) {
            throw new NotFoundHttpException($exception->getMessage(), $exception);
        } catch (FormForgeException $exception) {
            throw ValidationException::withMessages([
                'draft' => [$exception->getMessage()],
            ]);
        }

        return response()->json([
            'data' => [
                'form_key' => $key,
                'deleted' => $deleted,
            ],
        ]);
    }

    private function resolveForm(Request $request, FormManager $forms, string $key): FormInstance
    {
        $version = $request->input('version');

        if (! is_string($version) || trim($version) === '') {
            $version = null;
        }

        $form = $request->attributes->get('formforge.form');

        if (! $form instanceof FormInstance || $form->key() !== $key || ($version !== null && $form->version() !== $version)) {
            $form = $forms->get($key, $version);
        }

        return $form;
    }

    private function resolveOwner(Request $request): Model
    {
        $user = $request->user();

        if (! $user instanceof Model) {
            throw new AuthenticationException('Unauthenticated.');
        }

        return $user;
    }

    private function extractPayload(Request $request): array
    {
        $payload = $request->input('payload');

        if (is_array($payload)) {
            return $payload;
        }

        $all = $request->all();
        unset($all['version'], $all['payload'], $all['meta']);

        return is_array($all) ? $all : [];
    }

    private function extractMeta(Request $request): array
    {
        $meta = $request->input('meta');

        return is_array($meta) ? $meta : [];
    }

    private function toDraftArray(FormDraft $draft): array
    {
        return [
            'form_key' => (string) $draft->form_key,
            'form_version' => (string) ($draft->form_version ?? ''),
            'owner_type' => (string) ($draft->owner_type ?? ''),
            'owner_id' => (string) ($draft->owner_id ?? ''),
            'payload' => is_array($draft->payload) ? $draft->payload : [],
            'meta' => is_array($draft->meta) ? $draft->meta : [],
            'expires_at' => $draft->expires_at?->toIso8601String(),
            'created_at' => $draft->created_at?->toIso8601String(),
            'updated_at' => $draft->updated_at?->toIso8601String(),
        ];
    }
}
