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
        $formsTable = (string) config('formforge.database.forms_table', 'formforge_forms');
        $categoriesTable = (string) config('formforge.database.categories_table', 'formforge_categories');

        if (Schema::connection($connection)->hasTable($formsTable)) {
            if (! Schema::connection($connection)->hasColumn($formsTable, 'owner_type')) {
                Schema::connection($connection)->table($formsTable, function (Blueprint $table): void {
                    $table->string('owner_type')->nullable()->after('form_category_id');
                });
            }

            if (! Schema::connection($connection)->hasColumn($formsTable, 'owner_id')) {
                Schema::connection($connection)->table($formsTable, function (Blueprint $table): void {
                    $table->string('owner_id')->nullable()->after('owner_type');
                });
            }

            if (
                Schema::connection($connection)->hasColumn($formsTable, 'owner_type')
                && Schema::connection($connection)->hasColumn($formsTable, 'owner_id')
            ) {
                try {
                    Schema::connection($connection)->table($formsTable, function (Blueprint $table): void {
                        $table->index(['owner_type', 'owner_id'], 'formforge_forms_owner_index');
                    });
                } catch (\Throwable) {
                }
            }
        }

        if (Schema::connection($connection)->hasTable($categoriesTable)) {
            if (! Schema::connection($connection)->hasColumn($categoriesTable, 'owner_type')) {
                Schema::connection($connection)->table($categoriesTable, function (Blueprint $table): void {
                    $table->string('owner_type')->nullable()->after('is_system');
                });
            }

            if (! Schema::connection($connection)->hasColumn($categoriesTable, 'owner_id')) {
                Schema::connection($connection)->table($categoriesTable, function (Blueprint $table): void {
                    $table->string('owner_id')->nullable()->after('owner_type');
                });
            }

            if (
                Schema::connection($connection)->hasColumn($categoriesTable, 'owner_type')
                && Schema::connection($connection)->hasColumn($categoriesTable, 'owner_id')
            ) {
                try {
                    Schema::connection($connection)->table($categoriesTable, function (Blueprint $table): void {
                        $table->index(['owner_type', 'owner_id'], 'formforge_categories_owner_index');
                    });
                } catch (\Throwable) {
                }
            }
        }
    }

    public function down(): void
    {
        $connection = config('formforge.database.connection');
        $formsTable = (string) config('formforge.database.forms_table', 'formforge_forms');
        $categoriesTable = (string) config('formforge.database.categories_table', 'formforge_categories');

        if (Schema::connection($connection)->hasTable($formsTable)) {
            try {
                Schema::connection($connection)->table($formsTable, function (Blueprint $table): void {
                    $table->dropIndex('formforge_forms_owner_index');
                });
            } catch (\Throwable) {
            }

            if (Schema::connection($connection)->hasColumn($formsTable, 'owner_id')) {
                Schema::connection($connection)->table($formsTable, function (Blueprint $table): void {
                    $table->dropColumn('owner_id');
                });
            }

            if (Schema::connection($connection)->hasColumn($formsTable, 'owner_type')) {
                Schema::connection($connection)->table($formsTable, function (Blueprint $table): void {
                    $table->dropColumn('owner_type');
                });
            }
        }

        if (Schema::connection($connection)->hasTable($categoriesTable)) {
            try {
                Schema::connection($connection)->table($categoriesTable, function (Blueprint $table): void {
                    $table->dropIndex('formforge_categories_owner_index');
                });
            } catch (\Throwable) {
            }

            if (Schema::connection($connection)->hasColumn($categoriesTable, 'owner_id')) {
                Schema::connection($connection)->table($categoriesTable, function (Blueprint $table): void {
                    $table->dropColumn('owner_id');
                });
            }

            if (Schema::connection($connection)->hasColumn($categoriesTable, 'owner_type')) {
                Schema::connection($connection)->table($categoriesTable, function (Blueprint $table): void {
                    $table->dropColumn('owner_type');
                });
            }
        }
    }
};
