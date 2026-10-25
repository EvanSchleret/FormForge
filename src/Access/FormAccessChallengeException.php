<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Access;

use RuntimeException;

class FormAccessChallengeException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status,
        public readonly ?int $attemptsRemaining = null,
    ) {
        parent::__construct($message);
    }
}
