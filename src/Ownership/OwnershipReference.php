<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Ownership;

class OwnershipReference
{
    public function __construct(
        public readonly string $type,
        public readonly string $id,
    ) {
    }

    public function equals(?self $other): bool
    {
        if (! $other instanceof self) {
            return false;
        }

        return $this->type === $other->type && $this->id === $other->id;
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'id' => $this->id,
        ];
    }
}
