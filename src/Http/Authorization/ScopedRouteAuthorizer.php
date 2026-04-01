<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Http\Authorization;

use EvanSchleret\FormForge\Exceptions\FormForgeException;
use EvanSchleret\FormForge\Ownership\OwnershipReference;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ScopedRouteAuthorizer
{
    public function authorize(Request $request, string $endpoint, ?string $action, ?OwnershipReference $owner): void
    {
        $scope = $request->attributes->get('formforge.http.scope');
        $scopeName = $request->attributes->get('formforge.http.scope.name');

        if (! is_array($scope) || ! is_string($scopeName) || trim($scopeName) === '') {
            return;
        }

        $authorization = $scope['authorization'] ?? [];

        if (! is_array($authorization)) {
            return;
        }

        $mode = trim((string) ($authorization['mode'] ?? 'none'));

        if ($mode === '' || $mode === 'none') {
            return;
        }

        $ownerModel = $request->attributes->get('formforge.http.scope.owner.model');

        if (! $ownerModel instanceof Model) {
            $ownerModel = null;
        }

        $context = new FormForgeAuthorizationContext(
            request: $request,
            scopeName: $scopeName,
            scope: $scope,
            endpoint: $endpoint,
            action: $action,
            owner: $owner,
            ownerModel: $ownerModel,
        );

        if ($mode === 'gate') {
            $this->authorizeWithGate($request, $authorization, $endpoint, $action, $context);

            return;
        }

        if ($mode === 'policy') {
            $this->authorizeWithPolicy($request, $authorization, $endpoint, $action, $context);

            return;
        }

        throw new FormForgeException("Unsupported scoped authorization mode [{$mode}].");
    }

    private function authorizeWithGate(
        Request $request,
        array $authorization,
        string $endpoint,
        ?string $action,
        FormForgeAuthorizationContext $context,
    ): void {
        $ability = $this->resolveGateAbility($authorization, $endpoint, $action);

        if ($ability === null) {
            throw new AuthorizationException('Forbidden.');
        }

        $user = $request->user();

        if ($user === null) {
            throw new AuthenticationException('Unauthenticated.');
        }

        Gate::forUser($user)->authorize($ability, [$context]);
    }

    private function authorizeWithPolicy(
        Request $request,
        array $authorization,
        string $endpoint,
        ?string $action,
        FormForgeAuthorizationContext $context,
    ): void {
        $policyClass = trim((string) ($authorization['policy'] ?? ''));

        if ($policyClass === '' || ! class_exists($policyClass)) {
            throw new FormForgeException("Invalid scoped authorization policy class [{$policyClass}].");
        }

        $policy = app()->make($policyClass);

        if (! $policy instanceof BaseFormForgePolicy) {
            throw new FormForgeException("Scoped authorization policy [{$policyClass}] must extend [" . BaseFormForgePolicy::class . '].');
        }

        $method = AuthorizationActionMap::methodFor($endpoint, $action);

        if ($method === null) {
            throw new AuthorizationException('Forbidden.');
        }

        $user = $request->user();
        $before = $policy->before($user, $context);

        if (is_bool($before)) {
            if (! $before) {
                throw new AuthorizationException('Forbidden.');
            }

            return;
        }

        $allowed = (bool) $policy->{$method}($user, $context);

        if (! $allowed) {
            throw new AuthorizationException('Forbidden.');
        }
    }

    private function resolveGateAbility(array $authorization, string $endpoint, ?string $action): ?string
    {
        $abilities = $authorization['abilities'] ?? [];

        if (! is_array($abilities)) {
            return null;
        }

        $action = is_string($action) ? trim($action) : '';
        $candidates = [];

        if ($action !== '') {
            $candidates[] = $endpoint . '.' . $action;
            $candidates[] = $action;
        }

        $candidates[] = $endpoint;

        foreach ($candidates as $candidate) {
            $ability = $abilities[$candidate] ?? null;

            if (! is_string($ability)) {
                continue;
            }

            $ability = trim($ability);

            if ($ability !== '') {
                return $ability;
            }
        }

        return null;
    }
}

