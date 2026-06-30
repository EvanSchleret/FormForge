<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Support;

use Illuminate\Http\Request;

interface FormPublicLinkResolver
{
    public function resolve(array $context, Request $request): ?string;
}
