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

        if (! Schema::connection($connection)->hasColumn($table, 'category')) {
            Schema::connection($connection)->table($table, function (Blueprint $table): void {
                $table->string('category')->default('general')->after('title');
            });
        }

        if (! Schema::connection($connection)->hasColumn($table, 'is_published')) {
            Schema::connection($connection)->table($table, function (Blueprint $table): void {
                $table->boolean('is_published')->default(true)->after('is_active');
            });
        }

        try {
            Schema::connection($connection)->table($table, function (Blueprint $table): void {
                $table->index(['category'], 'formforge_forms_category_index');
            });
        } catch (\Throwable) {
        }

        try {
            Schema::connection($connection)->table($table, function (Blueprint $table): void {
                $table->index(['key', 'is_published'], 'formforge_forms_key_is_published_index');
            });
        } catch (\Throwable) {
        }
    }

    public function down(): void
    {
        $connection = config('formforge.database.connection');
        $table = (string) config('formforge.database.forms_table', 'formforge_forms');

        Schema::connection($connection)->table($table, function (Blueprint $table): void {
            try {
                $table->dropIndex('formforge_forms_category_index');
            } catch (\Throwable) {
            }

            try {
                $table->dropIndex('formforge_forms_key_is_published_index');
            } catch (\Throwable) {
            }
        });

        if (Schema::connection($connection)->hasColumn($table, 'category')) {
            Schema::connection($connection)->table($table, function (Blueprint $table): void {
                $table->dropColumn('category');
            });
        }

        if (Schema::connection($connection)->hasColumn($table, 'is_published')) {
            Schema::connection($connection)->table($table, function (Blueprint $table): void {
                $table->dropColumn('is_published');
            });
        }
    }
};
