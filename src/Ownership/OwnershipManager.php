<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Ownership;

use EvanSchleret\FormForge\Exceptions\FormForgeException;
use EvanSchleret\FormForge\Ownership\Contracts\AuthorizesOwnership;
use EvanSchleret\FormForge\Ownership\Contracts\ResolvesOwnership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class OwnershipManager
{
    public function enabled(): bool
    {
        return (bool) config('formforge.ownership.enabled', false);
    }

    public function required(): bool
    {
        return (bool) config('formforge.ownership.required', false);
    }

    public function requiredForEndpoint(string $endpoint): bool
    {
        if (! $this->enabled() || ! $this->isEndpointEnabled($endpoint)) {
            return false;
        }

        if ($this->required()) {
            return true;
        }

        return in_array($endpoint, $this->failClosedEndpoints(), true);
    }

    public function isEndpointEnabled(string $endpoint): bool
    {
        $endpoints = config('formforge.ownership.endpoints', ['management']);

        if (! is_array($endpoints)) {
            return $endpoint === 'management';
        }

        foreach ($endpoints as $entry) {
            if (is_string($entry) && trim($entry) === $endpoint) {
                return true;
            }
        }

        return false;
    }

    public function resolve(Request $request, string $endpoint, ?string $action = null): ?OwnershipReference
    {
        $preResolved = $request->attributes->get('formforge.ownership.reference');

        if ($preResolved instanceof OwnershipReference) {
            return $preResolved;
        }

        if (! $this->enabled() || ! $this->isEndpointEnabled($endpoint)) {
            return null;
        }

        $resolver = $this->resolver();
        $raw = $resolver->resolve($request, $endpoint, $action);

        return $this->normalize($raw);
    }

    public function assertRequestAuthorized(Request $request, string $endpoint, ?string $action, ?OwnershipReference $ownership): void
    {
        if (! $this->enabled() || ! $this->isEndpointEnabled($endpoint)) {
            return;
        }

        if ($this->requiredForEndpoint($endpoint) && ! $ownership instanceof OwnershipReference) {
            throw new AuthorizationException('FormForge ownership context is required.');
        }

        if (! $ownership instanceof OwnershipReference) {
            return;
        }

        $authorizer = $this->authorizer();

        if (! $authorizer->authorize($request, $ownership, $endpoint, $action)) {
            throw new AuthorizationException('Forbidden.');
        }
    }

    public function fromModel(Model $model): ?OwnershipReference
    {
        $type = trim((string) ($model->getAttribute('owner_type') ?? ''));
        $id = trim((string) ($model->getAttribute('owner_id') ?? ''));

        if ($type === '' || $id === '') {
            return null;
        }

        return new OwnershipReference($type, $id);
    }

    public function matchesModel(Model $model, ?OwnershipReference $ownership): bool
    {
        if (! $ownership instanceof OwnershipReference) {
            return true;
        }

        $current = $this->fromModel($model);

        if (! $current instanceof OwnershipReference) {
            return false;
        }

        return $current->equals($ownership);
    }

    public function assignToModel(Model $model, ?OwnershipReference $ownership): void
    {
        if (! $ownership instanceof OwnershipReference) {
            return;
        }

        $model->setAttribute('owner_type', $ownership->type);
        $model->setAttribute('owner_id', $ownership->id);
    }

    public function applyScope(Builder $query, ?OwnershipReference $ownership): Builder
    {
        if (! $ownership instanceof OwnershipReference) {
            return $query;
        }

        return $query
            ->where('owner_type', $ownership->type)
            ->where('owner_id', $ownership->id);
    }

    private function normalize(mixed $raw): ?OwnershipReference
    {
        if ($raw === null) {
            return null;
        }

        if ($raw instanceof OwnershipReference) {
            return $raw;
        }

        if ($raw instanceof Model) {
            $type = trim((string) $raw->getMorphClass());
            $id = trim((string) $raw->getKey());

            return $type === '' || $id === '' ? null : new OwnershipReference($type, $id);
        }

        if (is_array($raw)) {
            $type = trim((string) ($raw['type'] ?? $raw['owner_type'] ?? ''));
            $id = trim((string) ($raw['id'] ?? $raw['owner_id'] ?? ''));

            return $type === '' || $id === '' ? null : new OwnershipReference($type, $id);
        }

        throw new FormForgeException('Ownership resolver must return null, Model, OwnershipReference, or array(type,id).');
    }

    private function failClosedEndpoints(): array
    {
        $configured = config('formforge.ownership.fail_closed_endpoints', ['management']);

        if (! is_array($configured)) {
            return ['management'];
        }

        $endpoints = [];

        foreach ($configured as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $value = trim($entry);

            if ($value === '') {
                continue;
            }

            $endpoints[] = $value;
        }

        return array_values(array_unique($endpoints));
    }

    private function resolver(): ResolvesOwnership
    {
        $configured = config('formforge.ownership.resolver', NullOwnershipResolver::class);

        if (! is_string($configured) || trim($configured) === '') {
            return new NullOwnershipResolver();
        }

        $instance = app()->make($configured);

        if (! $instance instanceof ResolvesOwnership) {
            throw new FormForgeException('Configured ownership resolver must implement [' . ResolvesOwnership::class . '].');
        }

        return $instance;
    }

    private function authorizer(): AuthorizesOwnership
    {
        $configured = config('formforge.ownership.authorizer', AllowOwnershipAuthorizer::class);

        if (! is_string($configured) || trim($configured) === '') {
            return new AllowOwnershipAuthorizer();
        }

        $instance = app()->make($configured);

        if (! $instance instanceof AuthorizesOwnership) {
            throw new FormForgeException('Configured ownership authorizer must implement [' . AuthorizesOwnership::class . '].');
        }

        return $instance;
    }
}
