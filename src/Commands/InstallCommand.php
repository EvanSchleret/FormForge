<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Commands;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'formforge:install {--force : Overwrite any existing files}';

    protected $description = 'Publish FormForge configuration and migrations';

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        $this->call('vendor:publish', [
            '--tag' => 'formforge-config',
            '--force' => $force,
        ]);

        $this->call('vendor:publish', [
            '--tag' => 'formforge-migrations',
            '--force' => $force,
        ]);

        $this->info('FormForge configuration and migrations published.');

        return self::SUCCESS;
    }
}
