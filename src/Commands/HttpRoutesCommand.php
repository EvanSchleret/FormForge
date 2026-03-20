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
        $management = $resolver->resolve('management');
        $resolve = $resolver->resolve('resolve');
        $draft = $resolver->resolve('draft');

        $rows = [
            ['GET', '/' . $prefix . '/forms/{key}', 'schema', $schema['auth'], $schema['guard'] ?? '-', implode(', ', $schema['middleware'] ?? [])],
            ['GET', '/' . $prefix . '/forms/{key}/versions', 'schema', $schema['auth'], $schema['guard'] ?? '-', implode(', ', $schema['middleware'] ?? [])],
            ['GET', '/' . $prefix . '/forms/{key}/versions/{version}', 'schema', $schema['auth'], $schema['guard'] ?? '-', implode(', ', $schema['middleware'] ?? [])],
            ['POST', '/' . $prefix . '/forms/{key}/submit', 'submission', $submission['auth'], $submission['guard'] ?? '-', implode(', ', $submission['middleware'] ?? [])],
            ['POST', '/' . $prefix . '/forms/{key}/versions/{version}/submit', 'submission', $submission['auth'], $submission['guard'] ?? '-', implode(', ', $submission['middleware'] ?? [])],
            ['POST', '/' . $prefix . '/forms/{key}/uploads/stage', 'upload', $upload['auth'], $upload['guard'] ?? '-', implode(', ', $upload['middleware'] ?? [])],
            ['POST', '/' . $prefix . '/forms/{key}/versions/{version}/uploads/stage', 'upload', $upload['auth'], $upload['guard'] ?? '-', implode(', ', $upload['middleware'] ?? [])],
            ['POST', '/' . $prefix . '/forms/{key}/resolve', 'resolve', $resolve['auth'], $resolve['guard'] ?? '-', implode(', ', $resolve['middleware'] ?? [])],
            ['POST', '/' . $prefix . '/forms/{key}/versions/{version}/resolve', 'resolve', $resolve['auth'], $resolve['guard'] ?? '-', implode(', ', $resolve['middleware'] ?? [])],
            ['POST', '/' . $prefix . '/forms/{key}/drafts', 'draft', $draft['auth'], $draft['guard'] ?? '-', implode(', ', $draft['middleware'] ?? [])],
            ['GET', '/' . $prefix . '/forms/{key}/drafts/current', 'draft', $draft['auth'], $draft['guard'] ?? '-', implode(', ', $draft['middleware'] ?? [])],
            ['DELETE', '/' . $prefix . '/forms/{key}/drafts/current', 'draft', $draft['auth'], $draft['guard'] ?? '-', implode(', ', $draft['middleware'] ?? [])],
            ['POST', '/' . $prefix . '/forms', 'management(create)', $management['auth'], $management['guard'] ?? '-', implode(', ', $management['middleware'] ?? [])],
            ['PATCH', '/' . $prefix . '/forms/{key}', 'management(update)', $management['auth'], $management['guard'] ?? '-', implode(', ', $management['middleware'] ?? [])],
            ['POST', '/' . $prefix . '/forms/{key}/publish', 'management(publish)', $management['auth'], $management['guard'] ?? '-', implode(', ', $management['middleware'] ?? [])],
            ['POST', '/' . $prefix . '/forms/{key}/unpublish', 'management(unpublish)', $management['auth'], $management['guard'] ?? '-', implode(', ', $management['middleware'] ?? [])],
            ['DELETE', '/' . $prefix . '/forms/{key}', 'management(delete)', $management['auth'], $management['guard'] ?? '-', implode(', ', $management['middleware'] ?? [])],
            ['GET', '/' . $prefix . '/forms/{key}/revisions', 'management(revisions)', $management['auth'], $management['guard'] ?? '-', implode(', ', $management['middleware'] ?? [])],
            ['GET', '/' . $prefix . '/forms/{key}/diff/{fromVersion}/{toVersion}', 'management(diff)', $management['auth'], $management['guard'] ?? '-', implode(', ', $management['middleware'] ?? [])],
        ];

        $this->info('Global route middleware');
        $this->line(implode(', ', $globalMiddleware));
        $this->newLine();

        $this->table(['method', 'path', 'endpoint', 'auth', 'guard', 'dynamic middleware'], $rows);

        return self::SUCCESS;
    }
}
