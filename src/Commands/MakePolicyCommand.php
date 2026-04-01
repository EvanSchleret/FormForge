<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Commands;

use EvanSchleret\FormForge\Http\Authorization\AuthorizationActionMap;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class MakePolicyCommand extends Command
{
    protected $signature = 'formforge:make:policy
        {name : Policy class name}
        {--model= : Optional owner model FQCN}
        {--param=owner : Route parameter name used by scoped routes}
        {--path= : Target directory for generated policy}
        {--namespace= : PHP namespace for generated policy}
        {--force : Overwrite existing file}';

    protected $description = 'Generate a FormForge scoped HTTP policy class';

    public function handle(Filesystem $files): int
    {
        $name = trim((string) $this->argument('name'));

        if ($name === '') {
            $this->error('Policy name is required.');

            return self::FAILURE;
        }

        $namespace = $this->normalizeNamespace((string) ($this->option('namespace') ?? 'App\\Policies\\FormForge'));
        $basePath = $this->normalizePath($this->option('path') ?? app_path('Policies/FormForge'));
        $className = $this->classBasename($name);
        $targetPath = $basePath . DIRECTORY_SEPARATOR . $className . '.php';
        $param = trim((string) ($this->option('param') ?? 'owner'));
        $model = $this->normalizeModel($this->option('model'));

        if ($namespace === '' || $basePath === '' || $className === '' || $param === '') {
            $this->error('Invalid command options.');

            return self::FAILURE;
        }

        if ($files->exists($targetPath) && ! (bool) $this->option('force')) {
            $this->error("File already exists: {$targetPath}");
            $this->line('Use --force to overwrite.');

            return self::FAILURE;
        }

        $files->ensureDirectoryExists($basePath);
        $files->put($targetPath, $this->buildClass($namespace, $className, $model, $param));

        $this->info("Policy created: {$targetPath}");
        $this->newLine();
        $this->line('Config snippet (config/formforge.php):');
        $this->line("'http' => [");
        $this->line("    'scoped_routes' => [");
        $this->line('        [');
        $this->line("            'name' => 'owner',");
        $this->line("            'prefix' => 'owners/{{$param}}',");
        $this->line("            'owner' => ['route_param' => '{$param}', 'model' => " . ($model === null ? 'null' : '\\' . $model . '::class') . '],');
        $this->line("            'authorization' => ['mode' => 'policy', 'policy' => \\" . $namespace . '\\' . $className . "::class],");
        $this->line('        ],');
        $this->line('    ],');
        $this->line('],');

        return self::SUCCESS;
    }

    private function buildClass(string $namespace, string $className, ?string $model, string $param): string
    {
        $uses = [
            'EvanSchleret\\FormForge\\Http\\Authorization\\BaseFormForgePolicy',
            'EvanSchleret\\FormForge\\Http\\Authorization\\FormForgeAuthorizationContext',
        ];

        $ownerHelper = '';

        if ($model !== null) {
            $uses[] = $model;
            $shortModel = $this->classBasename($model);

            $ownerHelper = <<<PHP
    protected function owner(FormForgeAuthorizationContext \$context): ?{$shortModel}
    {
        if (\$context->routeParam() !== '{$param}') {
            return null;
        }

        \$owner = \$context->ownerModel();

        return \$owner instanceof {$shortModel} ? \$owner : null;
    }

PHP;
        }

        $methods = '';

        foreach (AuthorizationActionMap::all() as $key => $method) {
            $methods .= <<<PHP
    public function {$method}(mixed \$user, FormForgeAuthorizationContext \$context): bool
    {
        return false;
    }

PHP;
        }

        $importLines = '';

        foreach (array_values(array_unique($uses)) as $use) {
            $importLines .= 'use ' . $use . ";\n";
        }

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

{$importLines}
class {$className} extends BaseFormForgePolicy
{
{$ownerHelper}{$methods}}

PHP;
    }

    private function classBasename(string $name): string
    {
        $name = trim($name, '\\');
        $segments = explode('\\', $name);

        return trim((string) end($segments));
    }

    private function normalizeNamespace(string $namespace): string
    {
        $namespace = trim(str_replace('/', '\\', $namespace), '\\');

        return preg_replace('/\\\\+/', '\\', $namespace) ?? '';
    }

    private function normalizeModel(mixed $model): ?string
    {
        if (! is_string($model)) {
            return null;
        }

        $model = trim($model, '\\ ');

        return $model === '' ? null : $model;
    }

    private function normalizePath(mixed $path): string
    {
        if (! is_string($path)) {
            return '';
        }

        return rtrim(trim($path), DIRECTORY_SEPARATOR);
    }
}
