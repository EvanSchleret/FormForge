<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Tests\Fixtures;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MutateRouteKeyMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $owner = $request->route('user');

        if (is_object($route) && method_exists($route, 'setParameter') && is_scalar($owner)) {
            $route->setParameter('key', (string) $owner);
        }

        return $next($request);
    }
}

