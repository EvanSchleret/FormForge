<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Commands;

use EvanSchleret\FormForge\FormManager;
use Illuminate\Console\Command;

class SyncCommand extends Command
{
    protected $signature = 'formforge:sync';

    protected $description = 'Persist runtime FormForge definitions as immutable database snapshots';

    public function handle(FormManager $forms): int
    {
        $summary = $forms->sync();

        $this->info('FormForge sync completed.');
        $this->line('Created: ' . (string) ($summary['created'] ?? 0));
        $this->line('Unchanged: ' . (string) ($summary['unchanged'] ?? 0));

        return self::SUCCESS;
    }
}
