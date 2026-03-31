<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Ownership\Contracts;

use EvanSchleret\FormForge\Ownership\OwnershipReference;
use Illuminate\Http\Request;

interface AuthorizesOwnership
{
    public function authorize(Request $request, OwnershipReference $ownership, string $endpoint, ?string $action = null): bool;
}
