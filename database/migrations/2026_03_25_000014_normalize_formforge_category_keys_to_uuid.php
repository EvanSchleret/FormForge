<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('formforge.database.connection');
        $formsTable = (string) config('formforge.database.forms_table', 'formforge_forms');
        $categoriesTable = (string) config('formforge.database.categories_table', 'formforge_categories');

        if (! Schema::connection($connection)->hasTable($categoriesTable)) {
            return;
        }

        $hasFormsTable = Schema::connection($connection)->hasTable($formsTable);
        $hasCategoryColumn = $hasFormsTable && Schema::connection($connection)->hasColumn($formsTable, 'category');
        $hasCategoryFk = $hasFormsTable && Schema::connection($connection)->hasColumn($formsTable, 'form_category_id');

        DB::connection($connection)
            ->table($categoriesTable)
            ->select(['id', 'key'])
            ->orderBy('id')
            ->chunk(500, function ($rows) use ($connection, $categoriesTable, $formsTable, $hasCategoryColumn, $hasCategoryFk): void {
                foreach ($rows as $row) {
                    $id = is_numeric($row->id ?? null) ? (int) $row->id : 0;
                    $key = is_string($row->key ?? null) ? trim((string) $row->key) : '';

                    if ($id <= 0 || Str::isUuid($key)) {
                        continue;
                    }

                    $newKey = $this->uniqueUuid($connection, $categoriesTable);

                    DB::connection($connection)
                        ->table($categoriesTable)
                        ->where('id', $id)
                        ->update([
                            'key' => $newKey,
                            'updated_at' => now(),
                        ]);

                    if ($hasCategoryColumn && $hasCategoryFk) {
                        DB::connection($connection)
                            ->table($formsTable)
                            ->where('form_category_id', $id)
                            ->update(['category' => $newKey]);
                    }
                }
            });
    }

    public function down(): void
    {
    }

    private function uniqueUuid(?string $connection, string $table): string
    {
        do {
            $candidate = (string) Str::uuid();
            $exists = DB::connection($connection)->table($table)->where('key', $candidate)->exists();
        } while ($exists);

        return $candidate;
    }
};
