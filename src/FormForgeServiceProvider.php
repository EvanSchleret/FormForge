<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge;

use EvanSchleret\FormForge\Automations\AutomationRegistry;
use EvanSchleret\FormForge\Automations\SubmissionAutomationDispatcher;
use EvanSchleret\FormForge\Commands\DescribeCommand;
use EvanSchleret\FormForge\Commands\DraftsCleanupCommand;
use EvanSchleret\FormForge\Commands\HttpOptionsCommand;
use EvanSchleret\FormForge\Commands\HttpResolveCommand;
use EvanSchleret\FormForge\Commands\HttpRoutesCommand;
use EvanSchleret\FormForge\Commands\InstallCommand;
use EvanSchleret\FormForge\Commands\InstallMergeCommand;
use EvanSchleret\FormForge\Commands\ListCommand;
use EvanSchleret\FormForge\Commands\MakeAutomationCommand;
use EvanSchleret\FormForge\Commands\MakeHttpControllerCommand;
use EvanSchleret\FormForge\Commands\MakePolicyCommand;
use EvanSchleret\FormForge\Commands\SyncCommand;
use EvanSchleret\FormForge\Commands\UploadsCleanupCommand;
use EvanSchleret\FormForge\Http\Authorization\ScopedRouteAuthorizer;
use EvanSchleret\FormForge\Http\EndpointRequestGuard;
use EvanSchleret\FormForge\Http\HttpOptionsResolver;
use EvanSchleret\FormForge\Http\Middleware\ApplyEndpointOptions;
use EvanSchleret\FormForge\Http\Middleware\ApplyRouteScope;
use EvanSchleret\FormForge\Http\ScopedRouteManager;
use EvanSchleret\FormForge\Http\Resources\SubmissionHttpResource;
use EvanSchleret\FormForge\Management\FormCategoryService;
use EvanSchleret\FormForge\Management\FormMutationService;
use EvanSchleret\FormForge\Management\IdempotencyService;
use EvanSchleret\FormForge\Ownership\OwnershipManager;
use EvanSchleret\FormForge\Persistence\FormDefinitionRepository;
use EvanSchleret\FormForge\Registry\FormRegistry;
use EvanSchleret\FormForge\Submissions\SubmissionService;
use EvanSchleret\FormForge\Submissions\SubmissionReadService;
use EvanSchleret\FormForge\Submissions\SubmissionValidator;
use EvanSchleret\FormForge\Submissions\DraftStateService;
use EvanSchleret\FormForge\Submissions\StagedUploadService;
use EvanSchleret\FormForge\Submissions\UploadManager;
use Illuminate\Support\ServiceProvider;

class FormForgeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__) . '/config/formforge.php', 'formforge');

        $this->app->singleton(FormRegistry::class, static fn (): FormRegistry => new FormRegistry());
        $this->app->singleton(AutomationRegistry::class, static fn (): AutomationRegistry => new AutomationRegistry());
        $this->app->singleton(FormDefinitionRepository::class, static fn (): FormDefinitionRepository => new FormDefinitionRepository());
        $this->app->singleton(OwnershipManager::class, static fn (): OwnershipManager => new OwnershipManager());
        $this->app->singleton(FormCategoryService::class, fn (): FormCategoryService => new FormCategoryService(
            ownership: $this->app->make(OwnershipManager::class),
        ));
        $this->app->singleton(FormMutationService::class, fn (): FormMutationService => new FormMutationService(
            repository: $this->app->make(FormDefinitionRepository::class),
            categories: $this->app->make(FormCategoryService::class),
            ownership: $this->app->make(OwnershipManager::class),
        ));
        $this->app->singleton(IdempotencyService::class, static fn (): IdempotencyService => new IdempotencyService());
        $this->app->singleton(SubmissionValidator::class, static fn (): SubmissionValidator => new SubmissionValidator());
        $this->app->singleton(SubmissionReadService::class, static fn (): SubmissionReadService => new SubmissionReadService());
        $this->app->singleton(DraftStateService::class, static fn (): DraftStateService => new DraftStateService());
        $this->app->singleton(StagedUploadService::class, static fn (): StagedUploadService => new StagedUploadService());
        $this->app->singleton(
            UploadManager::class,
            fn (): UploadManager => new UploadManager(
                stagedUploads: $this->app->make(StagedUploadService::class),
            ),
        );
        $this->app->singleton(HttpOptionsResolver::class, static fn (): HttpOptionsResolver => new HttpOptionsResolver());
        $this->app->singleton(ScopedRouteManager::class, static fn (): ScopedRouteManager => new ScopedRouteManager());
        $this->app->singleton(ScopedRouteAuthorizer::class, static fn (): ScopedRouteAuthorizer => new ScopedRouteAuthorizer());
        $this->app->singleton(SubmissionHttpResource::class, static fn (): SubmissionHttpResource => new SubmissionHttpResource());
        $this->app->singleton(
            EndpointRequestGuard::class,
            fn (): EndpointRequestGuard => new EndpointRequestGuard(
                router: $this->app->make('router'),
                pipeline: $this->app->make(\Illuminate\Pipeline\Pipeline::class),
            ),
        );

        $this->app->singleton(
            SubmissionAutomationDispatcher::class,
            fn (): SubmissionAutomationDispatcher => new SubmissionAutomationDispatcher(
                registry: $this->app->make(AutomationRegistry::class),
                bus: $this->app->make(\Illuminate\Contracts\Bus\Dispatcher::class),
            ),
        );

        $this->app->singleton(
            SubmissionService::class,
            fn (): SubmissionService => new SubmissionService(
                validator: $this->app->make(SubmissionValidator::class),
                uploadManager: $this->app->make(UploadManager::class),
                automations: $this->app->make(SubmissionAutomationDispatcher::class),
            ),
        );

        $this->app->singleton(
            FormManager::class,
            fn (): FormManager => new FormManager(
                registry: $this->app->make(FormRegistry::class),
                repository: $this->app->make(FormDefinitionRepository::class),
                mutations: $this->app->make(FormMutationService::class),
                submissionService: $this->app->make(SubmissionService::class),
                automationRegistry: $this->app->make(AutomationRegistry::class),
                categories: $this->app->make(FormCategoryService::class),
            ),
        );
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__) . '/database/migrations');

        $this->publishes([
            dirname(__DIR__) . '/config/formforge.php' => config_path('formforge.php'),
        ], 'formforge-config');

        $this->publishes([
            dirname(__DIR__) . '/database/migrations' => database_path('migrations'),
        ], 'formforge-migrations');

        $router = $this->app->make('router');
        $router->aliasMiddleware('formforge.endpoint', ApplyEndpointOptions::class);
        $router->aliasMiddleware('formforge.scope', ApplyRouteScope::class);

        if ((bool) config('formforge.http.enabled', true)) {
            $this->loadRoutesFrom(dirname(__DIR__) . '/routes/formforge.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                InstallMergeCommand::class,
                MakeAutomationCommand::class,
                MakeHttpControllerCommand::class,
                MakePolicyCommand::class,
                ListCommand::class,
                DescribeCommand::class,
                SyncCommand::class,
                HttpOptionsCommand::class,
                HttpResolveCommand::class,
                HttpRoutesCommand::class,
                UploadsCleanupCommand::class,
                DraftsCleanupCommand::class,
            ]);
        }
    }
}
