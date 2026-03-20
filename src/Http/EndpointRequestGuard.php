<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Http;

use EvanSchleret\FormForge\Exceptions\FormForgeException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Gate;

class EndpointRequestGuard
{
    public function __construct(
        private readonly Router $router,
        private readonly Pipeline $pipeline,
    ) {
    }

    public function protect(Request $request, array $options): void
    {
        $authMode = (string) ($options['auth'] ?? 'public');
        $guard = $this->resolveGuard($options['guard'] ?? null);

        if ($guard !== null) {
            auth()->shouldUse($guard);
            $request->setUserResolver(static fn () => auth($guard)->user());
        }

        if ($authMode === 'public') {
            $this->authorize($request, $options, $guard);

            return;
        }

        if ($authMode === 'optional') {
            if ($guard !== null) {
                auth($guard)->user();
            } else {
                auth()->user();
            }

            $this->authorize($request, $options, $guard);

            return;
        }

        $authGuard = $this->activeGuard($guard);

        if (! $authGuard->check()) {
            throw new AuthenticationException('Unauthenticated.', $guard === null ? [] : [$guard]);
        }

        $this->authorize($request, $options, $guard);
    }

    public function runDynamicMiddleware(Request $request, array $middleware, callable $next): mixed
    {
        if ($middleware === []) {
            return $next($request);
        }

        $resolved = $this->router->resolveMiddleware($middleware);

        return $this->pipeline
            ->send($request)
            ->through($resolved)
            ->then($next);
    }

    private function resolveGuard(mixed $guard): ?string
    {
        if (! is_string($guard)) {
            return null;
        }

        $guard = trim($guard);

        if ($guard === '') {
            return null;
        }

        $guards = config('auth.guards', []);

        if (! is_array($guards) || ! array_key_exists($guard, $guards)) {
            throw new FormForgeException("Guard [{$guard}] is not defined in auth.guards.");
        }

        return $guard;
    }

    private function activeGuard(?string $guard): Guard
    {
        return $guard === null ? auth()->guard() : auth()->guard($guard);
    }

    private function authorize(Request $request, array $options, ?string $guard): void
    {
        $ability = $this->resolveAbility($request, $options);

        if ($ability === null) {
            return;
        }

        $user = $guard === null ? auth()->user() : auth($guard)->user();

        if ($user === null) {
            throw new AuthenticationException('Unauthenticated.', $guard === null ? [] : [$guard]);
        }

        $arguments = $request->attributes->get('formforge.authorization.arguments');

        if (is_array($arguments)) {
            Gate::forUser($user)->authorize($ability, $arguments);

            return;
        }

        if ($arguments !== null) {
            Gate::forUser($user)->authorize($ability, [$arguments]);

            return;
        }

        Gate::forUser($user)->authorize($ability);
    }

    private function resolveAbility(Request $request, array $options): ?string
    {
        $base = $this->normalizeAbility($options['ability'] ?? null);
        $abilities = $options['abilities'] ?? [];
        $action = trim((string) $request->attributes->get('formforge.endpoint.action', ''));

        if ($action !== '' && is_array($abilities) && array_key_exists($action, $abilities)) {
            $resolved = $this->normalizeAbility($abilities[$action] ?? null);

            if ($resolved !== null) {
                return $resolved;
            }
        }

        return $base;
    }

    private function normalizeAbility(mixed $ability): ?string
    {
        if (! is_string($ability)) {
            return null;
        }

        $ability = trim($ability);

        return $ability === '' ? null : $ability;
    }
}
