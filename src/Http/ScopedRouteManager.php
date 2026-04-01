<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Http;

use EvanSchleret\FormForge\Exceptions\FormForgeException;
use Illuminate\Database\Eloquent\Model;

class ScopedRouteManager
{
    public function all(): array
    {
        $configured = config('formforge.http.scoped_routes', []);

        if (! is_array($configured)) {
            return [];
        }

        $resolved = [];

        foreach ($configured as $index => $scope) {
            if (! is_array($scope)) {
                continue;
            }

            $name = $this->stringValue($scope['name'] ?? null);

            if ($name === null) {
                $name = 'scope_' . $index;
            }

            $prefix = $this->stringValue($scope['prefix'] ?? null);

            if ($prefix === null) {
                throw new FormForgeException("Invalid scoped route [{$name}]: prefix is required.");
            }

            $enabled = array_key_exists('enabled', $scope) ? (bool) $scope['enabled'] : true;
            $owner = $this->normalizeOwner($scope['owner'] ?? [], $name);
            $authorization = $this->normalizeAuthorization($scope['authorization'] ?? [], $name);

            $resolved[$name] = [
                'name' => $name,
                'enabled' => $enabled,
                'prefix' => trim($prefix, '/'),
                'middleware' => $this->normalizeMiddleware($scope['middleware'] ?? []),
                'endpoints' => $this->normalizeEndpoints($scope['endpoints'] ?? []),
                'owner' => $owner,
                'authorization' => $authorization,
            ];
        }

        return $resolved;
    }

    public function find(string $name): ?array
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        $scopes = $this->all();

        return $scopes[$name] ?? null;
    }

    public function endpointEnabled(array $scope, string $endpoint): bool
    {
        $endpoints = $scope['endpoints'] ?? [];

        if (! is_array($endpoints) || ! array_key_exists($endpoint, $endpoints)) {
            return true;
        }

        return (bool) $endpoints[$endpoint];
    }

    private function normalizeOwner(mixed $owner, string $scopeName): array
    {
        if (! is_array($owner)) {
            $owner = [];
        }

        $routeParam = $this->stringValue($owner['route_param'] ?? null);

        if ($routeParam === null) {
            throw new FormForgeException("Invalid scoped route [{$scopeName}]: owner.route_param is required.");
        }

        $model = $this->stringValue($owner['model'] ?? null);

        if ($model !== null && (! class_exists($model) || ! is_subclass_of($model, Model::class))) {
            throw new FormForgeException("Invalid scoped route [{$scopeName}]: owner.model must be an Eloquent model class.");
        }

        return [
            'route_param' => $routeParam,
            'model' => $model,
            'route_key' => $this->stringValue($owner['route_key'] ?? null),
            'type' => $this->stringValue($owner['type'] ?? null),
            'required' => array_key_exists('required', $owner) ? (bool) $owner['required'] : true,
        ];
    }

    private function normalizeAuthorization(mixed $authorization, string $scopeName): array
    {
        if (! is_array($authorization)) {
            $authorization = [];
        }

        $mode = $this->stringValue($authorization['mode'] ?? null) ?? 'none';

        if (! in_array($mode, ['none', 'gate', 'policy'], true)) {
            throw new FormForgeException("Invalid scoped route [{$scopeName}]: authorization.mode must be none, gate, or policy.");
        }

        $policy = $this->stringValue($authorization['policy'] ?? null);

        if ($mode === 'policy' && $policy === null) {
            throw new FormForgeException("Invalid scoped route [{$scopeName}]: authorization.policy is required for policy mode.");
        }

        return [
            'mode' => $mode,
            'policy' => $policy,
            'abilities' => $this->normalizeAbilities($authorization['abilities'] ?? []),
        ];
    }

    private function normalizeAbilities(mixed $abilities): array
    {
        if (! is_array($abilities)) {
            return [];
        }

        $normalized = [];

        foreach ($abilities as $key => $ability) {
            $name = $this->stringValue($key);
            $value = $this->stringValue($ability);

            if ($name === null || $value === null) {
                continue;
            }

            $normalized[$name] = $value;
        }

        return $normalized;
    }

    private function normalizeEndpoints(mixed $endpoints): array
    {
        if (! is_array($endpoints)) {
            return [];
        }

        $normalized = [];

        foreach ($endpoints as $endpoint => $enabled) {
            $name = $this->stringValue($endpoint);

            if ($name === null) {
                continue;
            }

            $normalized[$name] = (bool) $enabled;
        }

        return $normalized;
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

        foreach ($middleware as $entry) {
            $value = $this->stringValue($entry);

            if ($value === null) {
                continue;
            }

            $normalized[] = $value;
        }

        return array_values(array_unique($normalized));
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}

