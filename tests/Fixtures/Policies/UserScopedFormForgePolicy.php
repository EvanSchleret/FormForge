<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Tests\Fixtures\Policies;

use EvanSchleret\FormForge\Http\Authorization\BaseFormForgePolicy;
use EvanSchleret\FormForge\Http\Authorization\FormForgeAuthorizationContext;
use EvanSchleret\FormForge\Tests\Fixtures\User;

class UserScopedFormForgePolicy extends BaseFormForgePolicy
{
    public function before(mixed $user, FormForgeAuthorizationContext $context): ?bool
    {
        $owner = $context->ownerModel();

        if (! $user instanceof User || ! $owner instanceof User) {
            return false;
        }

        return (int) $user->getKey() === (int) $owner->getKey();
    }
}

