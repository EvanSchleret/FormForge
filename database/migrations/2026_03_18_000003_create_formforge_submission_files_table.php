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
        $submissionsTable = (string) config('formforge.database.submissions_table', 'formforge_submissions');
        $tableName = (string) config('formforge.database.submission_files_table', 'formforge_submission_files');

        Schema::connection($connection)->create($tableName, function (Blueprint $table) use ($submissionsTable): void {
            $table->id();
            $table->foreignId('form_submission_id')->constrained($submissionsTable)->cascadeOnDelete();
            $table->string('field_key');
            $table->string('disk');
            $table->string('path');
            $table->string('original_name');
            $table->string('stored_name');
            $table->string('mime_type');
            $table->string('extension')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('checksum')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('form_submission_id');
            $table->index(['form_submission_id', 'field_key']);
        });
    }

    public function down(): void
    {
        $connection = config('formforge.database.connection');
        $tableName = (string) config('formforge.database.submission_files_table', 'formforge_submission_files');

        Schema::connection($connection)->dropIfExists($tableName);
    }
};
