<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge;

use EvanSchleret\FormForge\Management\FormMutationService;
use EvanSchleret\FormForge\Models\FormDefinition;
use EvanSchleret\FormForge\Ownership\OwnershipReference;
use EvanSchleret\FormForge\Persistence\FormDefinitionRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

class ScopedFormManager
{
    public function __construct(
        private readonly FormDefinitionRepository $repository,
        private readonly FormMutationService $mutations,
        private readonly OwnershipReference $owner,
    ) {
    }

    public function owner(): OwnershipReference
    {
        return $this->owner;
    }

    public function find(string $key, string $version, bool $includeDeleted = false): ?FormDefinition
    {
        return $this->repository->find($key, $version, $includeDeleted, $this->owner);
    }

    public function latestActive(string $key, bool $includeDeleted = false): ?FormDefinition
    {
        return $this->repository->latestActive($key, $includeDeleted, $this->owner);
    }

    public function versions(string $key, bool $includeDeleted = false): array
    {
        return $this->repository->versions($key, $includeDeleted, $this->owner);
    }

    public function keyExists(string $key, bool $includeDeleted = true): bool
    {
        return $this->repository->keyExists($key, $includeDeleted, $this->owner);
    }

    public function paginateActive(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        return $this->mutations->paginateActive($perPage, $filters, $this->owner);
    }

    public function queryActive(array $filters = []): Builder
    {
        return $this->mutations->queryActive($filters, $this->owner);
    }

    public function create(array $input, ?Model $actor = null): FormDefinition
    {
        return $this->mutations->create($input, $actor, $this->owner);
    }

    public function patch(string $key, array $input, ?Model $actor = null): FormDefinition
    {
        return $this->mutations->patch($key, $input, $actor, $this->owner);
    }

    public function publish(string $key, ?Model $actor = null): FormDefinition
    {
        return $this->mutations->publish($key, $actor, $this->owner);
    }

    public function unpublish(string $key, ?Model $actor = null): FormDefinition
    {
        return $this->mutations->unpublish($key, $actor, $this->owner);
    }

    public function softDelete(string $key, ?Model $actor = null): int
    {
        return $this->mutations->softDelete($key, $actor, $this->owner);
    }

    public function revisions(string $key, bool $includeDeleted = true): array
    {
        return $this->mutations->revisions($key, $includeDeleted, $this->owner);
    }

    public function diff(string $key, int $fromVersion, int $toVersion, bool $includeDeleted = true): array
    {
        return $this->mutations->diff($key, $fromVersion, $toVersion, $includeDeleted, $this->owner);
    }
}
