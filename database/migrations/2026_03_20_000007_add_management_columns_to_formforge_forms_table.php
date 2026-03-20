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
        $table = (string) config('formforge.database.forms_table', 'formforge_forms');

        if (! Schema::connection($connection)->hasColumn($table, 'form_uuid')) {
            Schema::connection($connection)->table($table, function (Blueprint $table): void {
                $table->string('form_uuid', 36)->nullable()->after('id');
            });
        }

        if (! Schema::connection($connection)->hasColumn($table, 'revision_id')) {
            Schema::connection($connection)->table($table, function (Blueprint $table): void {
                $table->string('revision_id', 40)->nullable()->after('form_uuid');
            });
        }

        if (! Schema::connection($connection)->hasColumn($table, 'version_number')) {
            Schema::connection($connection)->table($table, function (Blueprint $table): void {
                $table->unsignedInteger('version_number')->nullable()->after('version');
            });
        }

        if (! Schema::connection($connection)->hasColumn($table, 'meta')) {
            Schema::connection($connection)->table($table, function (Blueprint $table): void {
                $table->json('meta')->nullable()->after('schema');
            });
        }

        if (! Schema::connection($connection)->hasColumn($table, 'published_at')) {
            Schema::connection($connection)->table($table, function (Blueprint $table): void {
                $table->timestamp('published_at')->nullable()->after('is_published');
            });
        }

        if (! Schema::connection($connection)->hasColumn($table, 'created_by_type')) {
            Schema::connection($connection)->table($table, function (Blueprint $table): void {
                $table->string('created_by_type')->nullable()->after('published_at');
            });
        }

        if (! Schema::connection($connection)->hasColumn($table, 'created_by_id')) {
            Schema::connection($connection)->table($table, function (Blueprint $table): void {
                $table->string('created_by_id')->nullable()->after('created_by_type');
            });
        }

        if (! Schema::connection($connection)->hasColumn($table, 'updated_by_type')) {
            Schema::connection($connection)->table($table, function (Blueprint $table): void {
                $table->string('updated_by_type')->nullable()->after('created_by_id');
            });
        }

        if (! Schema::connection($connection)->hasColumn($table, 'updated_by_id')) {
            Schema::connection($connection)->table($table, function (Blueprint $table): void {
                $table->string('updated_by_id')->nullable()->after('updated_by_type');
            });
        }

        if (! Schema::connection($connection)->hasColumn($table, 'deleted_at')) {
            Schema::connection($connection)->table($table, function (Blueprint $table): void {
                $table->softDeletes()->after('updated_by_id');
            });
        }

        try {
            Schema::connection($connection)->table($table, function (Blueprint $table): void {
                $table->index(['form_uuid'], 'formforge_forms_form_uuid_index');
            });
        } catch (\Throwable) {
        }

        try {
            Schema::connection($connection)->table($table, function (Blueprint $table): void {
                $table->unique(['revision_id'], 'formforge_forms_revision_id_unique');
            });
        } catch (\Throwable) {
        }

        try {
            Schema::connection($connection)->table($table, function (Blueprint $table): void {
                $table->index(['key', 'version_number'], 'formforge_forms_key_version_number_index');
            });
        } catch (\Throwable) {
        }

        try {
            Schema::connection($connection)->table($table, function (Blueprint $table): void {
                $table->index(['deleted_at'], 'formforge_forms_deleted_at_index');
            });
        } catch (\Throwable) {
        }

        $this->backfill($connection, $table);
    }

    public function down(): void
    {
        $connection = config('formforge.database.connection');
        $table = (string) config('formforge.database.forms_table', 'formforge_forms');

        Schema::connection($connection)->table($table, function (Blueprint $table): void {
            try {
                $table->dropIndex('formforge_forms_form_uuid_index');
            } catch (\Throwable) {
            }

            try {
                $table->dropUnique('formforge_forms_revision_id_unique');
            } catch (\Throwable) {
            }

            try {
                $table->dropIndex('formforge_forms_key_version_number_index');
            } catch (\Throwable) {
            }

            try {
                $table->dropIndex('formforge_forms_deleted_at_index');
            } catch (\Throwable) {
            }
        });

        foreach ([
            'deleted_at',
            'updated_by_id',
            'updated_by_type',
            'created_by_id',
            'created_by_type',
            'published_at',
            'meta',
            'version_number',
            'revision_id',
            'form_uuid',
        ] as $column) {
            if (Schema::connection($connection)->hasColumn($table, $column)) {
                Schema::connection($connection)->table($table, function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }

    private function backfill(mixed $connection, string $table): void
    {
        if (
            ! Schema::connection($connection)->hasColumn($table, 'form_uuid')
            || ! Schema::connection($connection)->hasColumn($table, 'revision_id')
            || ! Schema::connection($connection)->hasColumn($table, 'version_number')
        ) {
            return;
        }

        $rows = DB::connection($connection)
            ->table($table)
            ->select(['id', 'key', 'version', 'form_uuid', 'revision_id', 'version_number'])
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $grouped = [];

        foreach ($rows as $row) {
            $key = (string) $row->key;

            if (! array_key_exists($key, $grouped)) {
                $grouped[$key] = [];
            }

            $grouped[$key][] = $row;
        }

        foreach ($grouped as $key => $items) {
            $formUuid = null;

            foreach ($items as $item) {
                $candidate = trim((string) ($item->form_uuid ?? ''));

                if ($candidate !== '') {
                    $formUuid = $candidate;
                    break;
                }
            }

            if ($formUuid === null) {
                $formUuid = (string) Str::uuid();
            }

            usort($items, function (object $left, object $right): int {
                return $this->compareVersions((string) $left->version, (string) $right->version);
            });

            $counter = 1;

            foreach ($items as $item) {
                $updates = [];

                if (trim((string) ($item->form_uuid ?? '')) === '') {
                    $updates['form_uuid'] = $formUuid;
                }

                if (trim((string) ($item->revision_id ?? '')) === '') {
                    $updates['revision_id'] = (string) Str::ulid();
                }

                if ($item->version_number === null) {
                    $updates['version_number'] = $counter;
                }

                if ($updates !== []) {
                    DB::connection($connection)
                        ->table($table)
                        ->where('id', (int) $item->id)
                        ->update($updates);
                }

                $counter++;
            }
        }
    }

    private function compareVersions(string $left, string $right): int
    {
        $leftNumeric = ctype_digit($left);
        $rightNumeric = ctype_digit($right);

        if ($leftNumeric && $rightNumeric) {
            return (int) $left <=> (int) $right;
        }

        $natural = strnatcmp($left, $right);

        if ($natural !== 0) {
            return $natural;
        }

        return strcmp($left, $right);
    }
};
