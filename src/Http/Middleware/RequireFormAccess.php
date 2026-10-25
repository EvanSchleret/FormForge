<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Http\Middleware;

use Closure;
use EvanSchleret\FormForge\Access\FormAccessChallengeService;
use EvanSchleret\FormForge\FormInstance;
use EvanSchleret\FormForge\Ownership\OwnershipReference;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class RequireFormAccess
{
    public function __construct(
        private readonly FormAccessChallengeService $access,
    ) {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $form = $request->attributes->get('formforge.form');

        if (! $form instanceof FormInstance) {
            throw new NotFoundHttpException();
        }

        $owner = $request->attributes->get('formforge.ownership.reference');
        $owner = $owner instanceof OwnershipReference ? $owner : null;
        $scopeName = $request->attributes->get('formforge.http.scope.name');
        $scopeName = is_string($scopeName) && trim($scopeName) !== '' ? trim($scopeName) : null;
        $errorCode = $this->access->authorizationError(
            $request->header('X-FormForge-Access-Token'),
            $form,
            $owner,
            $scopeName,
        );

        if ($errorCode !== null) {
            return response()->json([
                'message' => 'A valid access challenge authorization is required for this form.',
                'code' => $errorCode,
            ], $errorCode === 'access_required' ? 401 : 403);
        }

        return $next($request);
    }
}
