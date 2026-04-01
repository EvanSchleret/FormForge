<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class MakeHttpControllerCommand extends Command
{
    protected $signature = 'formforge:make:http-controller
        {--controller=* : Controller key(s): schema, submission, upload, resolve, draft, management}
        {--all : Generate all HTTP override controllers}
        {--path= : Target directory for generated controllers}
        {--namespace= : PHP namespace for generated controllers}
        {--force : Overwrite existing files}';

    protected $description = 'Generate FormForge HTTP controller override scaffolds';

    public function handle(Filesystem $files): int
    {
        $map = $this->controllerMap();
        $available = array_keys($map);
        $selected = $this->resolveSelection($available);
        $namespace = $this->normalizeNamespace((string) ($this->option('namespace') ?? 'App\\Http\\Controllers\\FormForge'));
        $basePath = $this->normalizePath($this->option('path') ?? app_path('Http/Controllers/FormForge'));

        if ($selected === []) {
            $this->error('You must select at least one controller.');

            return self::FAILURE;
        }

        if ($namespace === '') {
            $this->error('Namespace cannot be empty.');

            return self::FAILURE;
        }

        if ($basePath === '') {
            $this->error('Path cannot be empty.');

            return self::FAILURE;
        }

        $targets = [];

        foreach ($selected as $key) {
            $className = $map[$key]['class'];
            $targetPath = $basePath . DIRECTORY_SEPARATOR . $className . '.php';
            $targets[$key] = $targetPath;

            if ($files->exists($targetPath) && ! (bool) $this->option('force')) {
                $this->error("File already exists: {$targetPath}");
                $this->line('Use --force to overwrite.');

                return self::FAILURE;
            }
        }

        $files->ensureDirectoryExists($basePath);

        foreach ($selected as $key) {
            $className = $map[$key]['class'];
            $parent = $map[$key]['parent'];
            $files->put($targets[$key], $this->buildClassContent($namespace, $className, $parent, $key));
            $this->info("Controller created: {$targets[$key]}");
        }

        $this->newLine();
        $this->line('Config snippet (config/formforge.php):');
        $this->line("'http' => [");
        $this->line("    'controllers' => [");

        foreach ($selected as $key) {
            $className = $map[$key]['class'];
            $this->line("        '{$key}' => \\" . $namespace . '\\' . $className . '::class,');
        }

        $this->line('    ],');
        $this->line('],');

        return self::SUCCESS;
    }

    private function controllerMap(): array
    {
        return [
            'schema' => [
                'class' => 'FormForgeSchemaController',
                'parent' => '\\EvanSchleret\\FormForge\\Http\\Controllers\\FormSchemaController',
            ],
            'submission' => [
                'class' => 'FormForgeSubmissionController',
                'parent' => '\\EvanSchleret\\FormForge\\Http\\Controllers\\FormSubmissionController',
            ],
            'upload' => [
                'class' => 'FormForgeUploadController',
                'parent' => '\\EvanSchleret\\FormForge\\Http\\Controllers\\FormUploadController',
            ],
            'resolve' => [
                'class' => 'FormForgeResolveController',
                'parent' => '\\EvanSchleret\\FormForge\\Http\\Controllers\\FormResolveController',
            ],
            'draft' => [
                'class' => 'FormForgeDraftController',
                'parent' => '\\EvanSchleret\\FormForge\\Http\\Controllers\\FormDraftController',
            ],
            'management' => [
                'class' => 'FormForgeManagementController',
                'parent' => '\\EvanSchleret\\FormForge\\Http\\Controllers\\FormManagementController',
            ],
        ];
    }

    private function resolveSelection(array $available): array
    {
        if ((bool) $this->option('all')) {
            return $available;
        }

        $fromOption = $this->normalizeSelection($this->option('controller'), $available);

        if ($fromOption !== []) {
            return $fromOption;
        }

        $selected = $this->choice(
            'Select controller(s) to scaffold',
            $available,
            null,
            null,
            true,
        );

        return $this->normalizeSelection($selected, $available);
    }

    private function normalizeSelection(mixed $raw, array $available): array
    {
        $candidates = [];

        if (is_string($raw) && trim($raw) !== '') {
            $candidates[] = trim($raw);
        }

        if (is_array($raw)) {
            foreach ($raw as $item) {
                if (! is_string($item)) {
                    continue;
                }

                $item = trim($item);

                if ($item === '') {
                    continue;
                }

                $candidates[] = $item;
            }
        }

        $selected = [];

        foreach ($available as $key) {
            if (! in_array($key, $candidates, true)) {
                continue;
            }

            $selected[] = $key;
        }

        return $selected;
    }

    private function buildClassContent(string $namespace, string $className, string $parent, string $key): string
    {
        if ($key === 'management') {
            return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use EvanSchleret\FormForge\Management\FormMutationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use {$parent};

class {$className} extends FormManagementController
{
    public function index(Request \$request, FormMutationService \$mutations): JsonResponse
    {
        return parent::index(\$request, \$mutations);
    }
}

PHP;
        }

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use {$parent};

class {$className} extends {$this->shortClassName($parent)}
{
}

PHP;
    }

    private function shortClassName(string $class): string
    {
        $segments = explode('\\', trim($class, '\\'));

        return (string) end($segments);
    }

    private function normalizeNamespace(string $namespace): string
    {
        $namespace = trim(str_replace('/', '\\', $namespace), '\\');

        return preg_replace('/\\\\+/', '\\', $namespace) ?? '';
    }

    private function normalizePath(mixed $path): string
    {
        if (! is_string($path)) {
            return '';
        }

        return rtrim(trim($path), DIRECTORY_SEPARATOR);
    }
}
