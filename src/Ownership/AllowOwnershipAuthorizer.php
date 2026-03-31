<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Ownership;

use EvanSchleret\FormForge\Ownership\Contracts\AuthorizesOwnership;
use Illuminate\Http\Request;

class AllowOwnershipAuthorizer implements AuthorizesOwnership
{
    public function authorize(Request $request, OwnershipReference $ownership, string $endpoint, ?string $action = null): bool
    {
        return true;
    }
}
