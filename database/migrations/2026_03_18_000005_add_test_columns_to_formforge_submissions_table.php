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

        if (! Schema::connection($connection)->hasColumn($table, 'is_test')) {
            Schema::connection($connection)->table($table, function (Blueprint $table): void {
                $table->boolean('is_test')->default(false)->after('payload');
            });
        }

        if (! Schema::connection($connection)->hasColumn($table, 'meta')) {
            Schema::connection($connection)->table($table, function (Blueprint $table): void {
                $table->json('meta')->nullable()->after('user_agent');
            });
        }

        try {
            Schema::connection($connection)->table($table, function (Blueprint $table): void {
                $table->index(['form_key', 'is_test'], 'formforge_submissions_form_key_is_test_index');
            });
        } catch (\Throwable) {
        }
    }

    public function down(): void
    {
        $connection = config('formforge.database.connection');
        $table = (string) config('formforge.database.submissions_table', 'formforge_submissions');

        try {
            Schema::connection($connection)->table($table, function (Blueprint $table): void {
                $table->dropIndex('formforge_submissions_form_key_is_test_index');
            });
        } catch (\Throwable) {
        }

        if (Schema::connection($connection)->hasColumn($table, 'meta')) {
            Schema::connection($connection)->table($table, function (Blueprint $table): void {
                $table->dropColumn('meta');
            });
        }

        if (Schema::connection($connection)->hasColumn($table, 'is_test')) {
            Schema::connection($connection)->table($table, function (Blueprint $table): void {
                $table->dropColumn('is_test');
            });
        }
    }
};
