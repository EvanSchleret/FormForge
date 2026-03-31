<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('formforge.database.connection');
        $table = (string) config('formforge.database.categories_table', 'formforge_categories');

        if (Schema::connection($connection)->hasTable($table)) {
            return;
        }

        Schema::connection($connection)->create($table, function (Blueprint $table): void {
            $table->id();
            $table->uuid('key');
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('meta')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->string('owner_type')->nullable();
            $table->string('owner_id')->nullable();
            $table->timestamps();

            $table->unique('key', 'formforge_categories_key_unique');
            $table->index('is_active', 'formforge_categories_is_active_index');
            $table->index(['owner_type', 'owner_id'], 'formforge_categories_owner_index');
        });
    }

    public function down(): void
    {
        $connection = config('formforge.database.connection');
        $table = (string) config('formforge.database.categories_table', 'formforge_categories');

        Schema::connection($connection)->dropIfExists($table);
    }
};
