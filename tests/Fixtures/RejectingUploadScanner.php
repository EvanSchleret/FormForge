<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Tests\Fixtures;

use EvanSchleret\FormForge\Exceptions\FormForgeException;
use EvanSchleret\FormForge\Support\Antivirus\FileScanner;
use Illuminate\Http\UploadedFile;

final class RejectingUploadScanner implements FileScanner
{
    public function scan(UploadedFile $file): void
    {
        throw new FormForgeException('Upload rejected by the test scanner.');
    }
}
