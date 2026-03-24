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
        $tableName = (string) config('formforge.database.automation_runs_table', 'formforge_submission_automation_runs');
        $submissionsTable = (string) config('formforge.database.submissions_table', 'formforge_submissions');

        Schema::connection($connection)->create($tableName, function (Blueprint $table) use ($submissionsTable): void {
            $table->id();
            $table->unsignedBigInteger('form_submission_id');
            $table->string('form_key');
            $table->string('automation_key');
            $table->string('handler_class');
            $table->string('status', 32)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['form_submission_id', 'automation_key'], 'formforge_submission_automation_unique');
            $table->index(['form_key', 'status'], 'formforge_submission_automation_status_idx');
            $table->foreign('form_submission_id', 'formforge_submission_automation_submission_fk')
                ->references('id')
                ->on($submissionsTable)
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        $connection = config('formforge.database.connection');
        $tableName = (string) config('formforge.database.automation_runs_table', 'formforge_submission_automation_runs');

        Schema::connection($connection)->dropIfExists($tableName);
    }
};
