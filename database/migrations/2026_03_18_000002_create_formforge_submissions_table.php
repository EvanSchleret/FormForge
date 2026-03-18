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
        $table = (string) config('formforge.database.submissions_table', 'formforge_submissions');

        Schema::connection($connection)->create($table, function (Blueprint $table): void {
            $table->id();
            $table->string('form_key');
            $table->string('form_version');
            $table->json('payload');
            $table->string('submitted_by_type')->nullable();
            $table->string('submitted_by_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['form_key', 'form_version']);
            $table->index(['submitted_by_type', 'submitted_by_id']);
        });
    }

    public function down(): void
    {
        $connection = config('formforge.database.connection');
        $table = (string) config('formforge.database.submissions_table', 'formforge_submissions');

        Schema::connection($connection)->dropIfExists($table);
    }
};
