<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Ownership;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class OwnershipReference
{
    public function __construct(
        public readonly string $type,
        public readonly string $id,
    ) {
    }

    public static function from(Model|array|self $owner): self
    {
        if ($owner instanceof self) {
            return $owner;
        }

        if ($owner instanceof Model) {
            return self::fromArrayPayload([
                'type' => $owner->getMorphClass(),
                'id' => $owner->getKey(),
            ]);
        }

        return self::fromArrayPayload($owner);
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

    private static function fromArrayPayload(array $owner): self
    {
        $type = trim((string) ($owner['type'] ?? $owner['owner_type'] ?? ''));
        $id = trim((string) ($owner['id'] ?? $owner['owner_id'] ?? ''));

        if ($type === '' || $id === '') {
            throw new InvalidArgumentException('Unable to resolve ownership reference.');
        }

        return new self($type, $id);
    }
}
