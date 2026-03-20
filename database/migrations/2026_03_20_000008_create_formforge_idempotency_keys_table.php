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
        $table = (string) config('formforge.database.idempotency_keys_table', 'formforge_idempotency_keys');

        Schema::connection($connection)->create($table, function (Blueprint $table): void {
            $table->id();
            $table->string('idempotency_key', 120);
            $table->string('endpoint', 40);
            $table->string('method', 16);
            $table->string('resource_key')->nullable();
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('status_code')->default(200);
            $table->json('response_body')->nullable();
            $table->string('response_revision_id', 40)->nullable();
            $table->unsignedInteger('response_version_number')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['idempotency_key', 'endpoint', 'method'], 'formforge_idempotency_unique');
            $table->index(['resource_key'], 'formforge_idempotency_resource_key_index');
            $table->index(['expires_at'], 'formforge_idempotency_expires_at_index');
        });
    }

    public function down(): void
    {
        $connection = config('formforge.database.connection');
        $table = (string) config('formforge.database.idempotency_keys_table', 'formforge_idempotency_keys');

        Schema::connection($connection)->dropIfExists($table);
    }
};
