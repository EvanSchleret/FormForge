<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Tests\Fixtures;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getKey(),
            'name' => (string) ($this->resource->name ?? ''),
        ];
    }
}
