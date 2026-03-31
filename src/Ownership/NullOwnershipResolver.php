<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Ownership;

use EvanSchleret\FormForge\Ownership\Contracts\ResolvesOwnership;
use Illuminate\Http\Request;

class NullOwnershipResolver implements ResolvesOwnership
{
    public function resolve(Request $request, string $endpoint, ?string $action = null): mixed
    {
        return null;
    }
}
