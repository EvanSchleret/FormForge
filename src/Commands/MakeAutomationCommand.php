<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class MakeAutomationCommand extends Command
{
    protected $signature = 'formforge:make:automation
        {name : Automation class name (supports sub-namespaces)}
        {--form= : Form key to include in the registration snippet}
        {--sync : Generate sync registration snippet}
        {--queue= : Queue name for queued registration snippet}
        {--connection= : Queue connection for queued registration snippet}
        {--path= : Target directory for generated class}
        {--namespace= : PHP namespace for generated class}
        {--force : Overwrite existing file}';

    protected $description = 'Generate a FormForge submission automation handler scaffold';

    public function handle(Filesystem $files): int
    {
        $name = $this->normalizeName((string) $this->argument('name'));
        $namespace = $this->normalizeNamespace((string) ($this->option('namespace') ?? 'App\\FormForge\\Automations'));
        $basePath = $this->normalizePath($this->option('path') ?? app_path('FormForge/Automations'));

        if ($name === '') {
            $this->error('Automation name cannot be empty.');

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

        $this->info("Automation created: {$targetPath}");
        $this->newLine();
        $this->line('Registration snippet:');
        $this->line($this->buildRegistrationSnippet($effectiveNamespace, $className));

        return self::SUCCESS;
    }

    private function buildClassContent(string $namespace, string $className): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use EvanSchleret\FormForge\Automations\Contracts\SubmissionAutomation;
use EvanSchleret\FormForge\Models\FormSubmission;

class {$className} implements SubmissionAutomation
{
    public function handle(FormSubmission \$submission): void
    {
        \$payload = is_array(\$submission->payload) ? \$submission->payload : [];
    }
}

PHP;
    }

    private function buildRegistrationSnippet(string $namespace, string $className): string
    {
        $formKey = $this->normalizeOptionalString((string) ($this->option('form') ?? '')) ?? 'your-form-key';
        $automationKey = Str::snake($className);
        $handlerClass = '\\' . $namespace . '\\' . $className . '::class';

        if ((bool) $this->option('sync')) {
            return "Form::automation('{$formKey}')->sync()->handler({$handlerClass}, '{$automationKey}')";
        }

        $queue = $this->normalizeOptionalString((string) ($this->option('queue') ?? ''));
        $connection = $this->normalizeOptionalString((string) ($this->option('connection') ?? ''));

        if ($queue !== null && $connection !== null) {
            return "Form::automation('{$formKey}')->queue('{$queue}', '{$connection}')->handler({$handlerClass}, '{$automationKey}')";
        }

        if ($queue !== null) {
            return "Form::automation('{$formKey}')->queue('{$queue}')->handler({$handlerClass}, '{$automationKey}')";
        }

        return "Form::automation('{$formKey}')->handler({$handlerClass}, '{$automationKey}')";
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

    private function normalizeOptionalString(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
