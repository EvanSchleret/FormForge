<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('formforge.database.connection');
        $table = (string) config('formforge.database.submissions_table', 'formforge_submissions');
        $indexName = 'formforge_submissions_uuid_unique';

        if (! Schema::connection($connection)->hasTable($table)) {
            return;
        }

        if (! Schema::connection($connection)->hasColumn($table, 'uuid')) {
            Schema::connection($connection)->table($table, function (Blueprint $blueprint): void {
                $blueprint->uuid('uuid')->nullable()->after('id');
            });
        }

        DB::connection($connection)
            ->table($table)
            ->whereNull('uuid')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($connection, $table): void {
                foreach ($rows as $row) {
                    DB::connection($connection)
                        ->table($table)
                        ->where('id', $row->id)
                        ->update(['uuid' => (string) Str::uuid()]);
                }
            });

        if (! Schema::connection($connection)->hasIndex($table, $indexName)) {
            Schema::connection($connection)->table($table, function (Blueprint $blueprint) use ($indexName): void {
                $blueprint->unique('uuid', $indexName);
            });
        }
    }

    public function down(): void
    {
        $connection = config('formforge.database.connection');
        $table = (string) config('formforge.database.submissions_table', 'formforge_submissions');
        $indexName = 'formforge_submissions_uuid_unique';

        if (! Schema::connection($connection)->hasTable($table)) {
            return;
        }

        if (Schema::connection($connection)->hasIndex($table, $indexName)) {
            Schema::connection($connection)->table($table, function (Blueprint $blueprint) use ($indexName): void {
                $blueprint->dropUnique($indexName);
            });
        }

        if (Schema::connection($connection)->hasColumn($table, 'uuid')) {
            Schema::connection($connection)->table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('uuid');
            });
        }
    }
};

