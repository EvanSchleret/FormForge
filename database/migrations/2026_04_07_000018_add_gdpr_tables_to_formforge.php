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
        $policiesTable = (string) config('formforge.database.privacy_policies_table', 'formforge_privacy_policies');
        $overridesTable = (string) config('formforge.database.submission_privacy_overrides_table', 'formforge_submission_privacy_overrides');

        if (! Schema::connection($connection)->hasColumn($submissionsTable, 'anonymized_at')) {
            Schema::connection($connection)->table($submissionsTable, function (Blueprint $table): void {
                $table->timestamp('anonymized_at')->nullable()->after('meta');
                $table->index('anonymized_at', 'formforge_submissions_anonymized_at_index');
            });
        }

        Schema::connection($connection)->create($policiesTable, function (Blueprint $table): void {
            $table->id();
            $table->string('scope');
            $table->string('form_key')->nullable();
            $table->string('action')->default('none');
            $table->unsignedInteger('after_days')->nullable();
            $table->json('anonymize_fields')->nullable();
            $table->boolean('delete_files')->default(false);
            $table->boolean('redact_submitter')->default(true);
            $table->boolean('redact_network')->default(true);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['scope', 'enabled'], 'formforge_privacy_policies_scope_enabled_index');
            $table->unique(['scope', 'form_key'], 'formforge_privacy_policies_scope_form_unique');
        });

        Schema::connection($connection)->create($overridesTable, function (Blueprint $table) use ($submissionsTable): void {
            $table->id();
            $table->foreignId('form_submission_id');
            $table->foreign('form_submission_id', 'formforge_sub_priv_overrides_submission_fk')
                ->references('id')
                ->on($submissionsTable)
                ->cascadeOnDelete();
            $table->string('action')->default('anonymize');
            $table->timestamp('execute_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->json('anonymize_fields')->nullable();
            $table->boolean('delete_files')->nullable();
            $table->boolean('redact_submitter')->nullable();
            $table->boolean('redact_network')->nullable();
            $table->string('reason')->nullable();
            $table->string('requested_by_type')->nullable();
            $table->string('requested_by_id')->nullable();
            $table->timestamps();

            $table->index(['form_submission_id', 'processed_at'], 'formforge_privacy_overrides_submission_processed_index');
            $table->index(['execute_at', 'processed_at'], 'formforge_privacy_overrides_execute_processed_index');
        });
    }

    public function down(): void
    {
        $connection = config('formforge.database.connection');
        $submissionsTable = (string) config('formforge.database.submissions_table', 'formforge_submissions');
        $policiesTable = (string) config('formforge.database.privacy_policies_table', 'formforge_privacy_policies');
        $overridesTable = (string) config('formforge.database.submission_privacy_overrides_table', 'formforge_submission_privacy_overrides');

        Schema::connection($connection)->dropIfExists($overridesTable);
        Schema::connection($connection)->dropIfExists($policiesTable);

        if (Schema::connection($connection)->hasColumn($submissionsTable, 'anonymized_at')) {
            Schema::connection($connection)->table($submissionsTable, function (Blueprint $table): void {
                $table->dropIndex('formforge_submissions_anonymized_at_index');
                $table->dropColumn('anonymized_at');
            });
        }
    }
};
