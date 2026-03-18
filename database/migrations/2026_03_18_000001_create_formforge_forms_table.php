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
        $table = (string) config('formforge.database.forms_table', 'formforge_forms');

        Schema::connection($connection)->create($table, function (Blueprint $table): void {
            $table->id();
            $table->string('key');
            $table->string('version');
            $table->string('title');
            $table->json('schema');
            $table->string('schema_hash', 64);
            $table->boolean('is_active')->default(false);
            $table->timestamps();

            $table->unique(['key', 'version']);
            $table->index(['key', 'is_active']);
        });
    }

    public function down(): void
    {
        $connection = config('formforge.database.connection');
        $table = (string) config('formforge.database.forms_table', 'formforge_forms');

        Schema::connection($connection)->dropIfExists($table);
    }
};
