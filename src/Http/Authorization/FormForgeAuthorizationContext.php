<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Http\Authorization;

use EvanSchleret\FormForge\Ownership\OwnershipReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class FormForgeAuthorizationContext
{
    public function __construct(
        private readonly Request $request,
        private readonly string $scopeName,
        private readonly array $scope,
        private readonly string $endpoint,
        private readonly ?string $action,
        private readonly ?OwnershipReference $owner,
        private readonly ?Model $ownerModel,
    ) {
    }

    public function request(): Request
    {
        return $this->request;
    }

    public function scopeName(): string
    {
        return $this->scopeName;
    }

    public function scope(): array
    {
        return $this->scope;
    }

    public function endpoint(): string
    {
        return $this->endpoint;
    }

    public function action(): ?string
    {
        return $this->action;
    }

    public function owner(): ?OwnershipReference
    {
        return $this->owner;
    }

    public function ownerModel(): ?Model
    {
        return $this->ownerModel;
    }

    public function routeParam(): ?string
    {
        $owner = $this->scope['owner'] ?? [];

        if (! is_array($owner)) {
            return null;
        }

        $value = $owner['route_param'] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}

