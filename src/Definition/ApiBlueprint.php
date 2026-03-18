<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Definition;

use EvanSchleret\FormForge\Exceptions\InvalidFieldDefinitionException;

class ApiBlueprint
{
    private array $config = [];

    public function __construct(array $config = [])
    {
        $this->config = $this->normalizeConfig($config);
    }

    public static function fromArray(array $config): self
    {
        return new self($config);
    }

    public function schemaPublic(bool $public = true): self
    {
        $this->config['schema']['public'] = $public;
        $this->config['schema']['auth'] = $public ? 'public' : 'required';

        return $this;
    }

    public function schemaAuth(string $mode, ?string $guard = null): self
    {
        $this->config['schema']['auth'] = $this->normalizeAuthMode($mode);
        $this->config['schema']['guard'] = $this->normalizeGuard($guard);

        return $this;
    }

    public function schemaMiddleware(array|string $middleware): self
    {
        $this->config['schema']['middleware'] = $this->normalizeMiddleware($middleware);

        return $this;
    }

    public function addSchemaMiddleware(array|string $middleware): self
    {
        $this->config['schema']['middleware'] = $this->mergeMiddleware(
            $this->config['schema']['middleware'] ?? [],
            $this->normalizeMiddleware($middleware),
        );

        return $this;
    }

    public function submissionAuth(string $mode, ?string $guard = null): self
    {
        $this->config['submission']['auth'] = $this->normalizeAuthMode($mode);
        $this->config['submission']['guard'] = $this->normalizeGuard($guard);

        return $this;
    }

    public function submissionMiddleware(array|string $middleware): self
    {
        $this->config['submission']['middleware'] = $this->normalizeMiddleware($middleware);

        return $this;
    }

    public function addSubmissionMiddleware(array|string $middleware): self
    {
        $this->config['submission']['middleware'] = $this->mergeMiddleware(
            $this->config['submission']['middleware'] ?? [],
            $this->normalizeMiddleware($middleware),
        );

        return $this;
    }

    public function uploadAuth(string $mode, ?string $guard = null): self
    {
        $this->config['upload']['auth'] = $this->normalizeAuthMode($mode);
        $this->config['upload']['guard'] = $this->normalizeGuard($guard);

        return $this;
    }

    public function uploadMiddleware(array|string $middleware): self
    {
        $this->config['upload']['middleware'] = $this->normalizeMiddleware($middleware);

        return $this;
    }

    public function addUploadMiddleware(array|string $middleware): self
    {
        $this->config['upload']['middleware'] = $this->mergeMiddleware(
            $this->config['upload']['middleware'] ?? [],
            $this->normalizeMiddleware($middleware),
        );

        return $this;
    }

    public function toArray(): array
    {
        return $this->config;
    }

    private function normalizeConfig(array $config): array
    {
        $normalized = [];

        foreach (['schema', 'submission', 'upload'] as $endpoint) {
            $endpointConfig = $config[$endpoint] ?? [];

            if (! is_array($endpointConfig)) {
                continue;
            }

            $entry = [];

            if (array_key_exists('public', $endpointConfig)) {
                $entry['public'] = (bool) $endpointConfig['public'];
            }

            if (isset($endpointConfig['auth'])) {
                $entry['auth'] = $this->normalizeAuthMode((string) $endpointConfig['auth']);
            }

            if (array_key_exists('guard', $endpointConfig)) {
                $entry['guard'] = $this->normalizeGuard(is_string($endpointConfig['guard']) ? $endpointConfig['guard'] : null);
            }

            if (isset($endpointConfig['middleware'])) {
                $entry['middleware'] = $this->normalizeMiddleware($endpointConfig['middleware']);
            }

            if ($entry !== []) {
                $normalized[$endpoint] = $entry;
            }
        }

        return $normalized;
    }

    private function normalizeAuthMode(string $mode): string
    {
        $mode = trim($mode);

        if (! in_array($mode, ['public', 'optional', 'required'], true)) {
            throw new InvalidFieldDefinitionException("Unsupported auth mode [{$mode}].");
        }

        return $mode;
    }

    private function normalizeGuard(?string $guard): ?string
    {
        if ($guard === null) {
            return null;
        }

        $guard = trim($guard);

        return $guard === '' ? null : $guard;
    }

    private function normalizeMiddleware(array|string $middleware): array
    {
        $values = is_array($middleware) ? $middleware : [$middleware];
        $normalized = [];

        foreach ($values as $value) {
            $candidate = trim((string) $value);

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
