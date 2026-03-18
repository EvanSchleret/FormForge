<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Commands;

use EvanSchleret\FormForge\Http\HttpOptionsResolver;
use Illuminate\Console\Command;

class HttpRoutesCommand extends Command
{
    protected $signature = 'formforge:http:routes';

    protected $description = 'Display FormForge HTTP endpoints and configured middleware/auth defaults';

    public function handle(HttpOptionsResolver $resolver): int
    {
        $prefix = trim((string) config('formforge.http.prefix', 'api/formforge/v1'), '/');
        $globalMiddleware = config('formforge.http.middleware', ['api']);

        if (! is_array($globalMiddleware)) {
            $globalMiddleware = ['api'];
        }

        $schema = $resolver->resolve('schema');
        $submission = $resolver->resolve('submission');
        $upload = $resolver->resolve('upload');

        $rows = [
            ['GET', '/' . $prefix . '/forms/{key}', 'schema', $schema['auth'], $schema['guard'] ?? '-', implode(', ', $schema['middleware'] ?? [])],
            ['GET', '/' . $prefix . '/forms/{key}/versions', 'schema', $schema['auth'], $schema['guard'] ?? '-', implode(', ', $schema['middleware'] ?? [])],
            ['GET', '/' . $prefix . '/forms/{key}/versions/{version}', 'schema', $schema['auth'], $schema['guard'] ?? '-', implode(', ', $schema['middleware'] ?? [])],
            ['POST', '/' . $prefix . '/forms/{key}/submit', 'submission', $submission['auth'], $submission['guard'] ?? '-', implode(', ', $submission['middleware'] ?? [])],
            ['POST', '/' . $prefix . '/forms/{key}/versions/{version}/submit', 'submission', $submission['auth'], $submission['guard'] ?? '-', implode(', ', $submission['middleware'] ?? [])],
            ['POST', '/' . $prefix . '/forms/{key}/uploads/stage', 'upload', $upload['auth'], $upload['guard'] ?? '-', implode(', ', $upload['middleware'] ?? [])],
            ['POST', '/' . $prefix . '/forms/{key}/versions/{version}/uploads/stage', 'upload', $upload['auth'], $upload['guard'] ?? '-', implode(', ', $upload['middleware'] ?? [])],
        ];

        $this->info('Global route middleware');
        $this->line(implode(', ', $globalMiddleware));
        $this->newLine();

        $this->table(['method', 'path', 'endpoint', 'auth', 'guard', 'dynamic middleware'], $rows);

        return self::SUCCESS;
    }
}
