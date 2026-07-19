<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Support\Antivirus;

use Illuminate\Http\UploadedFile;

interface FileScanner
{
    public function scan(UploadedFile $file): void;
}
