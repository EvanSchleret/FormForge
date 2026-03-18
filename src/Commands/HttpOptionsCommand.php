<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Commands;

use EvanSchleret\FormForge\Http\HttpOptionsResolver;
use Illuminate\Console\Command;
use Illuminate\Routing\Router;

class HttpOptionsCommand extends Command
{
    protected $signature = 'formforge:http:options';

    protected $description = 'Display available FormForge HTTP auth modes, guards, and middleware aliases';

    public function handle(HttpOptionsResolver $resolver, Router $router): int
    {
        $this->info('Auth modes');

        $authRows = array_map(static fn (string $mode): array => [$mode], $resolver->availableAuthModes());
        $this->table(['mode'], $authRows);

        $this->info('Guards');

        $guards = $resolver->availableGuards();

        if ($guards === []) {
            $this->line('No guards found.');
        } else {
            $this->table(['guard'], array_map(static fn (string $guard): array => [$guard], $guards));
        }

        $this->info('Middleware aliases');

        $aliases = $router->getMiddleware();
        ksort($aliases);

        if ($aliases === []) {
            $this->line('No middleware aliases registered.');
        } else {
            $rows = [];

            foreach ($aliases as $alias => $class) {
                $rows[] = [(string) $alias, (string) $class];
            }

            $this->table(['alias', 'class'], $rows);
        }

        $this->info('Middleware groups');

        $groups = $router->getMiddlewareGroups();
        ksort($groups);

        if ($groups === []) {
            $this->line('No middleware groups registered.');
        } else {
            $rows = [];

            foreach ($groups as $name => $stack) {
                $rows[] = [(string) $name, implode(', ', array_map('strval', (array) $stack))];
            }

            $this->table(['group', 'stack'], $rows);
        }

        return self::SUCCESS;
    }
}
