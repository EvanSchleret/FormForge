<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Tests;

use EvanSchleret\FormForge\FormForgeServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            FormForgeServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('formforge.database.connection', 'testing');
        $app['config']->set('filesystems.default', 'local');
        $app['config']->set('filesystems.disks.local', [
            'driver' => 'local',
            'root' => storage_path('app'),
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh', ['--database' => 'testing'])->run();

        Schema::connection('testing')->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
    }
}
