<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Tests\Fixtures;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MarkSubmissionMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response instanceof Response) {
            return response('', 500);
        }

        $response->headers->set('X-FormForge-Test', '1');

        return $response;
    }
}
