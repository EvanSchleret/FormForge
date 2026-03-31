<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Ownership\Contracts;

use Illuminate\Http\Request;

interface ResolvesOwnership
{
    public function resolve(Request $request, string $endpoint, ?string $action = null): mixed;
}
