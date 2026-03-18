<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Http\Controllers;

use EvanSchleret\FormForge\FormInstance;
use EvanSchleret\FormForge\FormManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class FormSchemaController
{
    public function latest(Request $request, FormManager $forms, string $key): JsonResponse
    {
        $form = $this->requestForm($request);

        if (! $form instanceof FormInstance || $form->key() !== $key) {
            $form = $forms->get($key);
        }

        $this->assertSchemaAccessible($form);

        return response()->json([
            'data' => $form->toArray(),
        ]);
    }

    public function versions(FormManager $forms, string $key): JsonResponse
    {
        return response()->json([
            'data' => [
                'key' => $key,
                'versions' => $forms->versions($key),
            ],
        ]);
    }

    public function show(Request $request, FormManager $forms, string $key, string $version): JsonResponse
    {
        $form = $this->requestForm($request);

        if (! $form instanceof FormInstance || $form->key() !== $key || $form->version() !== $version) {
            $form = $forms->get($key, $version);
        }

        $this->assertSchemaAccessible($form);

        return response()->json([
            'data' => $form->toArray(),
        ]);
    }

    private function requestForm(Request $request): mixed
    {
        return $request->attributes->get('formforge.form');
    }

    private function assertSchemaAccessible(FormInstance $form): void
    {
        $requiresPublished = (bool) config('formforge.http.schema.require_published', false);

        if (! $requiresPublished || $form->isPublished()) {
            return;
        }

        throw new NotFoundHttpException('Form schema not found.');
    }
}
