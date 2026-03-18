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
        $table = (string) config('formforge.database.staged_uploads_table', 'formforge_staged_uploads');

        Schema::connection($connection)->create($table, function (Blueprint $table): void {
            $table->id();
            $table->string('token', 80)->unique();
            $table->string('form_key');
            $table->string('form_version');
            $table->string('field_key');
            $table->string('field_name');
            $table->string('disk');
            $table->string('path');
            $table->string('original_name');
            $table->string('stored_name');
            $table->string('mime_type');
            $table->string('extension')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('checksum')->nullable();
            $table->json('metadata')->nullable();
            $table->string('uploaded_by_type')->nullable();
            $table->string('uploaded_by_id')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['form_key', 'form_version']);
            $table->index(['field_key']);
            $table->index(['uploaded_by_type', 'uploaded_by_id']);
            $table->index(['expires_at', 'consumed_at']);
        });
    }

    public function down(): void
    {
        $connection = config('formforge.database.connection');
        $table = (string) config('formforge.database.staged_uploads_table', 'formforge_staged_uploads');

        Schema::connection($connection)->dropIfExists($table);
    }
};
