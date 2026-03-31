<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('formforge.database.connection');
        $table = (string) config('formforge.database.categories_table', 'formforge_categories');

        if (! Schema::connection($connection)->hasTable($table)) {
            return;
        }

        if (! Schema::connection($connection)->hasColumn($table, 'is_system')) {
            Schema::connection($connection)->table($table, function (Blueprint $table): void {
                $table->boolean('is_system')->default(false)->after('is_active');
            });
        }

        $defaultName = trim((string) config('formforge.forms.default_category', 'general'));

        if ($defaultName === '') {
            $defaultName = 'general';
        }

        DB::connection($connection)
            ->table($table)
            ->whereRaw('LOWER(name) = ?', [strtolower($defaultName)])
            ->update(['is_system' => true]);

        DB::connection($connection)
            ->table($table)
            ->where('meta', 'like', '%"default":true%')
            ->update(['is_system' => true]);
    }

    public function down(): void
    {
        $connection = config('formforge.database.connection');
        $table = (string) config('formforge.database.categories_table', 'formforge_categories');

        if (! Schema::connection($connection)->hasTable($table) || ! Schema::connection($connection)->hasColumn($table, 'is_system')) {
            return;
        }

        Schema::connection($connection)->table($table, function (Blueprint $table): void {
            $table->dropColumn('is_system');
        });
    }
};
