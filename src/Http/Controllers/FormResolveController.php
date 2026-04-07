<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Http\Controllers;

use EvanSchleret\FormForge\FormInstance;
use EvanSchleret\FormForge\FormManager;
use EvanSchleret\FormForge\Support\FormSchemaLayout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class FormResolveController
{
    public function resolveLatest(Request $request, FormManager $forms): JsonResponse
    {
        $key = $this->routeRequired($request, 'key');
        $version = $request->input('version');

        if (! is_string($version) || trim($version) === '') {
            $version = null;
        }

        return $this->resolve($request, $forms, $key, $version);
    }

    public function resolveVersion(Request $request, FormManager $forms): JsonResponse
    {
        $key = $this->routeRequired($request, 'key');
        $version = $this->routeRequired($request, 'version');

        return $this->resolve($request, $forms, $key, $version);
    }

    private function resolve(Request $request, FormManager $forms, string $key, ?string $version): JsonResponse
    {
        if (! $this->isResolveEnabled()) {
            throw new NotFoundHttpException('Form schema not found.');
        }

        $form = $request->attributes->get('formforge.form');

        if (! $form instanceof FormInstance || $form->key() !== $key || ($version !== null && $form->version() !== $version)) {
            $form = $forms->get($key, $version);
        }

        $payload = $request->input('payload');

        if (! is_array($payload)) {
            $payload = [];
        }

        $debug = $request->boolean('debug');
        $resolved = FormSchemaLayout::resolve($form->toArray(), $payload, $debug);

        return response()->json([
            'data' => [
                'schema' => $resolved,
            ],
        ]);
    }

    private function isResolveEnabled(): bool
    {
        $environments = config('formforge.http.resolve.enabled_environments', ['local', 'testing']);

        if (! is_array($environments) || $environments === []) {
            return false;
        }

        $environments = array_values(array_filter(array_map(static fn (mixed $value): string => trim((string) $value), $environments), static fn (string $value): bool => $value !== ''));

        if ($environments === []) {
            return false;
        }

        return app()->environment($environments);
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
}
