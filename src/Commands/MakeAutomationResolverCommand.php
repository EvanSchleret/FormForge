<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class MakeAutomationResolverCommand extends Command
{
    protected $signature = 'formforge:make:automation-resolver
        {name : Resolver class name (supports sub-namespaces)}
        {--path= : Target directory for generated class}
        {--namespace= : PHP namespace for generated class}
        {--force : Overwrite existing file}';

    protected $description = 'Generate a FormForge submission automation resolver scaffold';

    public function handle(Filesystem $files): int
    {
        $name = $this->normalizeName((string) $this->argument('name'));
        $namespace = $this->normalizeNamespace((string) ($this->option('namespace') ?? 'App\\FormForge\\AutomationResolvers'));
        $basePath = $this->normalizePath($this->option('path') ?? app_path('FormForge/AutomationResolvers'));

        if ($name === '') {
            $this->error('Resolver name cannot be empty.');

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

        $segments = explode('\\', $name);
        $className = array_pop($segments);
        $relativePath = $segments === [] ? '' : implode(DIRECTORY_SEPARATOR, $segments) . DIRECTORY_SEPARATOR;
        $targetDirectory = rtrim($basePath . DIRECTORY_SEPARATOR . $relativePath, DIRECTORY_SEPARATOR);
        $targetPath = $targetDirectory . DIRECTORY_SEPARATOR . $className . '.php';

        if ($files->exists($targetPath) && ! (bool) $this->option('force')) {
            $this->error("File already exists: {$targetPath}");
            $this->line('Use --force to overwrite.');

            return self::FAILURE;
        }

        $files->ensureDirectoryExists($targetDirectory);
        $effectiveNamespace = $namespace . ($segments === [] ? '' : '\\' . implode('\\', $segments));
        $content = $this->buildClassContent($effectiveNamespace, $className);
        $files->put($targetPath, $content);

        $this->info("Automation resolver created: {$targetPath}");
        $this->newLine();
        $this->line('Registration snippet:');
        $this->line("Form::automationForResolver(\\" . $effectiveNamespace . '\\' . $className . "::class)->sync()->handler(\\App\\FormForge\\Automations\\YourAutomation::class, 'your_automation_key')");

        return self::SUCCESS;
    }

    private function buildClassContent(string $namespace, string $className): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use EvanSchleret\FormForge\Automations\Contracts\SubmissionAutomationResolver;
use EvanSchleret\FormForge\Models\FormSubmission;

class {$className} implements SubmissionAutomationResolver
{
    public function matches(FormSubmission \$submission): bool
    {
        return false;
    }
}

PHP;
    }

    private function normalizeName(string $name): string
    {
        $name = trim(str_replace('/', '\\', $name), '\\');

        return preg_replace('/\\\\+/', '\\', $name) ?? '';
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

