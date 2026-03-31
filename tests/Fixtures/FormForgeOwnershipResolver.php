<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Tests\Fixtures;

use EvanSchleret\FormForge\Ownership\Contracts\ResolvesOwnership;
use Illuminate\Http\Request;

class FormForgeOwnershipResolver implements ResolvesOwnership
{
    public function resolve(Request $request, string $endpoint, ?string $action = null): mixed
    {
        $type = trim((string) $request->header('X-FormForge-Owner-Type', ''));
        $id = trim((string) $request->header('X-FormForge-Owner-Id', ''));

        if ($type === '' || $id === '') {
            return null;
        }

        return [
            'type' => $type,
            'id' => $id,
        ];
    }
}
