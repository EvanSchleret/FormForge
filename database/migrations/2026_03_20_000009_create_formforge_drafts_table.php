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
        $table = (string) config('formforge.database.drafts_table', 'formforge_drafts');

        Schema::connection($connection)->create($table, function (Blueprint $table): void {
            $table->id();
            $table->string('form_key');
            $table->string('form_version')->nullable();
            $table->string('owner_type');
            $table->string('owner_id');
            $table->json('payload');
            $table->json('meta')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['form_key', 'owner_type', 'owner_id'], 'formforge_drafts_form_owner_unique');
            $table->index(['expires_at'], 'formforge_drafts_expires_at_index');
            $table->index(['form_key', 'form_version'], 'formforge_drafts_form_version_index');
        });
    }

    public function down(): void
    {
        $connection = config('formforge.database.connection');
        $table = (string) config('formforge.database.drafts_table', 'formforge_drafts');

        Schema::connection($connection)->dropIfExists($table);
    }
};
