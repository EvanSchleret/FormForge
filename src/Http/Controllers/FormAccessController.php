<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Http\Controllers;

use EvanSchleret\FormForge\Access\FormAccessChallengeException;
use EvanSchleret\FormForge\Access\FormAccessChallengeService;
use EvanSchleret\FormForge\FormInstance;
use EvanSchleret\FormForge\Ownership\OwnershipReference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class FormAccessController
{
    public function status(Request $request, FormAccessChallengeService $access): JsonResponse
    {
        try {
            $required = $access->isRequired($this->form($request), $this->owner($request));
        } catch (FormAccessChallengeException $exception) {
            return $this->error($exception);
        }

        return response()->json([
            'data' => [
                'required' => $required,
            ],
        ]);
    }

    public function createChallenge(Request $request, FormAccessChallengeService $access): JsonResponse
    {
        try {
            $challenge = $access->createChallenge(
                $this->form($request),
                $this->owner($request),
                $this->scopeName($request),
            );
        } catch (FormAccessChallengeException $exception) {
            return $this->error($exception);
        }

        return response()->json(['data' => $challenge], 201);
    }

    public function verifyChallenge(Request $request, FormAccessChallengeService $access): JsonResponse
    {
        $validated = Validator::make($request->only('code'), [
            'code' => ['required', 'string', 'min:1', 'max:255'],
        ])->validate();

        try {
            $authorization = $access->verifyChallenge(
                $this->routeRequired($request, 'challenge'),
                (string) $validated['code'],
                $this->form($request),
                $this->owner($request),
                $this->scopeName($request),
            );
        } catch (FormAccessChallengeException $exception) {
            return $this->error($exception);
        }

        return response()->json(['data' => $authorization]);
    }

    private function form(Request $request): FormInstance
    {
        $form = $request->attributes->get('formforge.form');

        if ($form instanceof FormInstance) {
            return $form;
        }

        throw new NotFoundHttpException();
    }

    private function owner(Request $request): ?OwnershipReference
    {
        $owner = $request->attributes->get('formforge.ownership.reference');

        return $owner instanceof OwnershipReference ? $owner : null;
    }

    private function scopeName(Request $request): ?string
    {
        $scopeName = $request->attributes->get('formforge.http.scope.name');

        return is_string($scopeName) && trim($scopeName) !== '' ? trim($scopeName) : null;
    }

    private function routeRequired(Request $request, string $name): string
    {
        $value = $request->route($name);

        if (! is_scalar($value) || trim((string) $value) === '') {
            throw new NotFoundHttpException();
        }

        return trim((string) $value);
    }

    private function error(FormAccessChallengeException $exception): JsonResponse
    {
        $payload = [
            'message' => $exception->getMessage(),
            'code' => $exception->errorCode,
        ];

        if ($exception->attemptsRemaining !== null) {
            $payload['attempts_remaining'] = $exception->attemptsRemaining;
        }

        return response()->json($payload, $exception->status);
    }
}
