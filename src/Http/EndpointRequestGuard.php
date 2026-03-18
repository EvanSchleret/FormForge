<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Http;

use EvanSchleret\FormForge\Exceptions\FormForgeException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Pipeline\Pipeline;

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
            return;
        }

        if ($authMode === 'optional') {
            if ($guard !== null) {
                auth($guard)->user();
            } else {
                auth()->user();
            }

            return;
        }

        $authGuard = $this->activeGuard($guard);

        if (! $authGuard->check()) {
            throw new AuthenticationException('Unauthenticated.', $guard === null ? [] : [$guard]);
        }
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
}
