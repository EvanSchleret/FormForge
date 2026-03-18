<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Http;

use EvanSchleret\FormForge\Exceptions\FormForgeException;

class HttpOptionsResolver
{
    public function resolve(string $endpoint, ?array $formSchema = null): array
    {
        if (! in_array($endpoint, ['schema', 'submission', 'upload'], true)) {
            throw new FormForgeException("Unsupported HTTP endpoint type [{$endpoint}].");
        }

        $defaults = [
            'auth' => 'public',
            'guard' => null,
            'middleware' => [],
        ];

        $http = (array) config('formforge.http', []);
        $endpointConfig = $http[$endpoint] ?? [];

        if (! is_array($endpointConfig)) {
            $endpointConfig = [];
        }

        if (array_key_exists('public', $endpointConfig)) {
            $endpointConfig['auth'] = (bool) $endpointConfig['public'] ? 'public' : 'required';
        }

        $resolved = array_merge($defaults, $endpointConfig);

        if ($formSchema !== null) {
            $override = $this->extractFormOverride($formSchema, $endpoint);
            $resolved = $this->mergeEndpointConfig($resolved, $override);
        }

        $resolved['auth'] = $this->normalizeAuthMode((string) ($resolved['auth'] ?? 'public'));
        $resolved['guard'] = $this->normalizeGuard($resolved['guard'] ?? null);
        $resolved['middleware'] = $this->normalizeMiddleware($resolved['middleware'] ?? []);

        return $resolved;
    }

    public function availableAuthModes(): array
    {
        return ['public', 'optional', 'required'];
    }

    public function availableGuards(): array
    {
        $guards = config('auth.guards', []);

        return is_array($guards) ? array_keys($guards) : [];
    }

    private function extractFormOverride(array $formSchema, string $endpoint): array
    {
        $api = $formSchema['api'] ?? [];

        if (! is_array($api)) {
            return [];
        }

        $override = $api[$endpoint] ?? [];

        if (! is_array($override)) {
            return [];
        }

        if (array_key_exists('public', $override)) {
            $override['auth'] = (bool) $override['public'] ? 'public' : 'required';
        }

        return $override;
    }

    private function mergeEndpointConfig(array $base, array $override): array
    {
        $merged = $base;

        foreach ($override as $key => $value) {
            if ($key === 'middleware') {
                $merged['middleware'] = $this->mergeMiddleware(
                    $this->normalizeMiddleware($merged['middleware'] ?? []),
                    $this->normalizeMiddleware($value),
                );

                continue;
            }

            $merged[$key] = $value;
        }

        return $merged;
    }

    private function normalizeAuthMode(string $mode): string
    {
        $mode = trim($mode);

        if (! in_array($mode, $this->availableAuthModes(), true)) {
            throw new FormForgeException("Unsupported auth mode [{$mode}].");
        }

        return $mode;
    }

    private function normalizeGuard(mixed $guard): ?string
    {
        if (! is_string($guard)) {
            return null;
        }

        $guard = trim($guard);

        return $guard === '' ? null : $guard;
    }

    private function normalizeMiddleware(mixed $middleware): array
    {
        if (is_string($middleware)) {
            $middleware = [$middleware];
        }

        if (! is_array($middleware)) {
            return [];
        }

        $normalized = [];

        foreach ($middleware as $item) {
            $candidate = trim((string) $item);

            if ($candidate !== '') {
                $normalized[] = $candidate;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function mergeMiddleware(array $left, array $right): array
    {
        $merged = [...$left];

        foreach ($right as $item) {
            if (! in_array($item, $merged, true)) {
                $merged[] = $item;
            }
        }

        return array_values($merged);
    }
}
