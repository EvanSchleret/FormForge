<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Commands;

use EvanSchleret\FormForge\Http\HttpOptionsResolver;
use EvanSchleret\FormForge\Http\ScopedRouteManager;
use Illuminate\Console\Command;

class HttpRoutesCommand extends Command
{
    protected $signature = 'formforge:http:routes';

    protected $description = 'Display FormForge HTTP endpoints and configured middleware/auth defaults';

    public function handle(HttpOptionsResolver $resolver, ScopedRouteManager $scopedRoutes): int
    {
        $prefix = trim((string) config('formforge.http.prefix', 'api/formforge/v1'), '/');
        $globalMiddleware = config('formforge.http.middleware', ['api']);
        $enabledEndpoints = config('formforge.http.endpoints', []);

        if (! is_array($globalMiddleware)) {
            $globalMiddleware = ['api'];
        }

        $isEnabled = static function (string $endpoint) use ($enabledEndpoints): bool {
            if (! is_array($enabledEndpoints) || ! array_key_exists($endpoint, $enabledEndpoints)) {
                return true;
            }

            return (bool) $enabledEndpoints[$endpoint];
        };

        $schema = $resolver->resolve('schema');
        $submission = $resolver->resolve('submission');
        $upload = $resolver->resolve('upload');
        $management = $resolver->resolve('management');
        $resolve = $resolver->resolve('resolve');
        $draft = $resolver->resolve('draft');

        $rows = [];

        if ($isEnabled('schema')) {
            $rows[] = ['GET', '/' . $prefix . '/forms/{key}', 'schema', $schema['auth'], $schema['guard'] ?? '-', implode(', ', $schema['middleware'] ?? [])];
            $rows[] = ['GET', '/' . $prefix . '/forms/{key}/versions', 'schema', $schema['auth'], $schema['guard'] ?? '-', implode(', ', $schema['middleware'] ?? [])];
            $rows[] = ['GET', '/' . $prefix . '/forms/{key}/versions/{version}', 'schema', $schema['auth'], $schema['guard'] ?? '-', implode(', ', $schema['middleware'] ?? [])];
        }

        if ($isEnabled('submission')) {
            $rows[] = ['POST', '/' . $prefix . '/forms/{key}/submit', 'submission', $submission['auth'], $submission['guard'] ?? '-', implode(', ', $submission['middleware'] ?? [])];
            $rows[] = ['POST', '/' . $prefix . '/forms/{key}/versions/{version}/submit', 'submission', $submission['auth'], $submission['guard'] ?? '-', implode(', ', $submission['middleware'] ?? [])];
        }

        if ($isEnabled('upload')) {
            $rows[] = ['POST', '/' . $prefix . '/forms/{key}/uploads/stage', 'upload', $upload['auth'], $upload['guard'] ?? '-', implode(', ', $upload['middleware'] ?? [])];
            $rows[] = ['POST', '/' . $prefix . '/forms/{key}/versions/{version}/uploads/stage', 'upload', $upload['auth'], $upload['guard'] ?? '-', implode(', ', $upload['middleware'] ?? [])];
        }

        if ($isEnabled('resolve')) {
            $rows[] = ['POST', '/' . $prefix . '/forms/{key}/resolve', 'resolve', $resolve['auth'], $resolve['guard'] ?? '-', implode(', ', $resolve['middleware'] ?? [])];
            $rows[] = ['POST', '/' . $prefix . '/forms/{key}/versions/{version}/resolve', 'resolve', $resolve['auth'], $resolve['guard'] ?? '-', implode(', ', $resolve['middleware'] ?? [])];
        }

        if ($isEnabled('draft')) {
            $rows[] = ['POST', '/' . $prefix . '/forms/{key}/drafts', 'draft', $draft['auth'], $draft['guard'] ?? '-', implode(', ', $draft['middleware'] ?? [])];
            $rows[] = ['GET', '/' . $prefix . '/forms/{key}/drafts/current', 'draft', $draft['auth'], $draft['guard'] ?? '-', implode(', ', $draft['middleware'] ?? [])];
            $rows[] = ['DELETE', '/' . $prefix . '/forms/{key}/drafts/current', 'draft', $draft['auth'], $draft['guard'] ?? '-', implode(', ', $draft['middleware'] ?? [])];
        }

        if ($isEnabled('management')) {
            $rows[] = ['GET', '/' . $prefix . '/forms', 'management(index)', $management['auth'], $management['guard'] ?? '-', implode(', ', $management['middleware'] ?? [])];
            $rows[] = ['GET', '/' . $prefix . '/categories', 'management(categories)', $management['auth'], $management['guard'] ?? '-', implode(', ', $management['middleware'] ?? [])];
            $rows[] = ['GET', '/' . $prefix . '/categories/{categoryKey}', 'management(category)', $management['auth'], $management['guard'] ?? '-', implode(', ', $management['middleware'] ?? [])];
            $rows[] = ['POST', '/' . $prefix . '/categories', 'management(category_create)', $management['auth'], $management['guard'] ?? '-', implode(', ', $management['middleware'] ?? [])];
            $rows[] = ['PATCH', '/' . $prefix . '/categories/{categoryKey}', 'management(category_update)', $management['auth'], $management['guard'] ?? '-', implode(', ', $management['middleware'] ?? [])];
            $rows[] = ['DELETE', '/' . $prefix . '/categories/{categoryKey}', 'management(category_delete)', $management['auth'], $management['guard'] ?? '-', implode(', ', $management['middleware'] ?? [])];
            $rows[] = ['POST', '/' . $prefix . '/forms', 'management(create)', $management['auth'], $management['guard'] ?? '-', implode(', ', $management['middleware'] ?? [])];
            $rows[] = ['PATCH', '/' . $prefix . '/forms/{key}', 'management(update)', $management['auth'], $management['guard'] ?? '-', implode(', ', $management['middleware'] ?? [])];
            $rows[] = ['POST', '/' . $prefix . '/forms/{key}/publish', 'management(publish)', $management['auth'], $management['guard'] ?? '-', implode(', ', $management['middleware'] ?? [])];
            $rows[] = ['POST', '/' . $prefix . '/forms/{key}/unpublish', 'management(unpublish)', $management['auth'], $management['guard'] ?? '-', implode(', ', $management['middleware'] ?? [])];
            $rows[] = ['DELETE', '/' . $prefix . '/forms/{key}', 'management(delete)', $management['auth'], $management['guard'] ?? '-', implode(', ', $management['middleware'] ?? [])];
            $rows[] = ['GET', '/' . $prefix . '/forms/{key}/revisions', 'management(revisions)', $management['auth'], $management['guard'] ?? '-', implode(', ', $management['middleware'] ?? [])];
            $rows[] = ['GET', '/' . $prefix . '/forms/{key}/diff/{fromVersion}/{toVersion}', 'management(diff)', $management['auth'], $management['guard'] ?? '-', implode(', ', $management['middleware'] ?? [])];
            $rows[] = ['GET', '/' . $prefix . '/forms/{key}/responses', 'management(responses)', $management['auth'], $management['guard'] ?? '-', implode(', ', $management['middleware'] ?? [])];
            $rows[] = ['GET', '/' . $prefix . '/forms/{key}/responses/export', 'management(responses_export)', $management['auth'], $management['guard'] ?? '-', implode(', ', $management['middleware'] ?? [])];
            $rows[] = ['PUT', '/' . $prefix . '/forms/{key}/gdpr-policy', 'management(gdpr_policy)', $management['auth'], $management['guard'] ?? '-', implode(', ', $management['middleware'] ?? [])];
            $rows[] = ['GET', '/' . $prefix . '/forms/{key}/responses/{submissionUuid}', 'management(response)', $management['auth'], $management['guard'] ?? '-', implode(', ', $management['middleware'] ?? [])];
            $rows[] = ['DELETE', '/' . $prefix . '/forms/{key}/responses/{submissionUuid}', 'management(response_delete)', $management['auth'], $management['guard'] ?? '-', implode(', ', $management['middleware'] ?? [])];
            $rows[] = ['POST', '/' . $prefix . '/forms/{key}/responses/{submissionUuid}/gdpr/anonymize', 'management(response_gdpr_anonymize)', $management['auth'], $management['guard'] ?? '-', implode(', ', $management['middleware'] ?? [])];
            $rows[] = ['POST', '/' . $prefix . '/forms/{key}/responses/{submissionUuid}/gdpr/delete', 'management(response_gdpr_delete)', $management['auth'], $management['guard'] ?? '-', implode(', ', $management['middleware'] ?? [])];
            $rows[] = ['POST', '/' . $prefix . '/gdpr/run', 'management(gdpr_run)', $management['auth'], $management['guard'] ?? '-', implode(', ', $management['middleware'] ?? [])];
        }

        $this->info('Global route middleware');
        $this->line(implode(', ', $globalMiddleware));
        $this->newLine();

        $this->table(['method', 'path', 'endpoint', 'auth', 'guard', 'dynamic middleware'], $rows);

        $configuredScopedRoutes = $scopedRoutes->all();

        if ($configuredScopedRoutes !== []) {
            $this->newLine();
            $this->info('Scoped route groups');

            $scopeRows = [];

            foreach ($configuredScopedRoutes as $scope) {
                if (! is_array($scope) || ! (bool) ($scope['enabled'] ?? true)) {
                    continue;
                }

                $scopeRows[] = [
                    $scope['name'] ?? '-',
                    '/' . trim($prefix . '/' . trim((string) ($scope['prefix'] ?? ''), '/'), '/'),
                    implode(', ', is_array($scope['middleware'] ?? null) ? $scope['middleware'] : []),
                    (string) (($scope['authorization']['mode'] ?? 'none')),
                ];
            }

            if ($scopeRows !== []) {
                $this->table(['name', 'prefix', 'middleware', 'authorization'], $scopeRows);
            }
        }

        return self::SUCCESS;
    }
}
