<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Support\Antivirus;

interface ScannerStatusProvider
{
    public function status(): array;
}
