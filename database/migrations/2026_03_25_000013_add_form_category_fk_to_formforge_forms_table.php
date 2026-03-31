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
        $formsTable = (string) config('formforge.database.forms_table', 'formforge_forms');
        $categoriesTable = (string) config('formforge.database.categories_table', 'formforge_categories');

        if (! Schema::connection($connection)->hasTable($formsTable) || ! Schema::connection($connection)->hasTable($categoriesTable)) {
            return;
        }

        if (! Schema::connection($connection)->hasColumn($formsTable, 'form_category_id')) {
            Schema::connection($connection)->table($formsTable, function (Blueprint $table) use ($categoriesTable): void {
                $table->foreignId('form_category_id')->nullable()->after('category')->constrained($categoriesTable)->nullOnDelete();
            });
        }

        $categoriesById = [];
        $categoriesByKey = [];
        $categoriesByName = [];

        $this->bootstrapCategoryCache($connection, $categoriesTable, $categoriesById, $categoriesByKey, $categoriesByName);

        $defaultCategory = $this->resolveCategoryByLegacyValue(
            connection: $connection,
            table: $categoriesTable,
            legacyValue: config('formforge.forms.default_category', 'general'),
            categoriesById: $categoriesById,
            categoriesByKey: $categoriesByKey,
            categoriesByName: $categoriesByName,
            fallback: null,
        );

        if (! Schema::connection($connection)->hasColumn($formsTable, 'category')) {
            DB::connection($connection)->table($formsTable)
                ->whereNull('form_category_id')
                ->update(['form_category_id' => $defaultCategory['id']]);

            return;
        }

        DB::connection($connection)
            ->table($formsTable)
            ->select(['id', 'category', 'form_category_id'])
            ->orderBy('id')
            ->chunk(500, function ($rows) use ($connection, $formsTable, $categoriesTable, $defaultCategory, &$categoriesById, &$categoriesByKey, &$categoriesByName): void {
                foreach ($rows as $row) {
                    $resolved = $this->resolveCategoryForFormRow(
                        connection: $connection,
                        table: $categoriesTable,
                        formCategoryId: $row->form_category_id,
                        legacyValue: is_string($row->category ?? null) ? (string) $row->category : null,
                        categoriesById: $categoriesById,
                        categoriesByKey: $categoriesByKey,
                        categoriesByName: $categoriesByName,
                        fallback: $defaultCategory,
                    );

                    $currentCategory = is_string($row->category ?? null) ? trim((string) $row->category) : null;
                    $currentCategoryId = is_numeric($row->form_category_id) ? (int) $row->form_category_id : null;
                    $updates = [];

                    if ($currentCategory !== $resolved['key']) {
                        $updates['category'] = $resolved['key'];
                    }

                    if ($currentCategoryId !== $resolved['id']) {
                        $updates['form_category_id'] = $resolved['id'];
                    }

                    if ($updates === []) {
                        continue;
                    }

                    DB::connection($connection)
                        ->table($formsTable)
                        ->where('id', $row->id)
                        ->update($updates);
                }
            });
    }

    public function down(): void
    {
        $connection = config('formforge.database.connection');
        $formsTable = (string) config('formforge.database.forms_table', 'formforge_forms');

        if (! Schema::connection($connection)->hasTable($formsTable) || ! Schema::connection($connection)->hasColumn($formsTable, 'form_category_id')) {
            return;
        }

        Schema::connection($connection)->table($formsTable, function (Blueprint $table): void {
            $table->dropConstrainedForeignId('form_category_id');
        });
    }

    private function bootstrapCategoryCache(
        ?string $connection,
        string $table,
        array &$categoriesById,
        array &$categoriesByKey,
        array &$categoriesByName,
    ): void {
        DB::connection($connection)
            ->table($table)
            ->select(['id', 'key', 'name'])
            ->orderBy('id')
            ->chunk(500, function ($rows) use ($connection, $table, &$categoriesById, &$categoriesByKey, &$categoriesByName): void {
                foreach ($rows as $row) {
                    $id = is_numeric($row->id ?? null) ? (int) $row->id : null;

                    if ($id === null || $id <= 0) {
                        continue;
                    }

                    $key = trim((string) ($row->key ?? ''));

                    if (! Str::isUuid($key)) {
                        $key = $this->uniqueUuid($connection, $table);

                        DB::connection($connection)
                            ->table($table)
                            ->where('id', $id)
                            ->update([
                                'key' => $key,
                                'updated_at' => now(),
                            ]);
                    }

                    $name = $this->normalizeCategoryName($row->name ?? null);

                    if ($name === '') {
                        $name = 'General';

                        DB::connection($connection)
                            ->table($table)
                            ->where('id', $id)
                            ->update([
                                'name' => $name,
                                'updated_at' => now(),
                            ]);
                    }

                    $this->storeCategoryInCache($categoriesById, $categoriesByKey, $categoriesByName, [
                        'id' => $id,
                        'key' => $key,
                        'name' => $name,
                    ]);
                }
            });
    }

    private function resolveCategoryForFormRow(
        ?string $connection,
        string $table,
        mixed $formCategoryId,
        mixed $legacyValue,
        array &$categoriesById,
        array &$categoriesByKey,
        array &$categoriesByName,
        array $fallback,
    ): array {
        if (is_numeric($formCategoryId)) {
            $resolvedById = $this->resolveCategoryById($connection, $table, (int) $formCategoryId, $categoriesById, $categoriesByKey, $categoriesByName);

            if ($resolvedById !== null) {
                return $resolvedById;
            }
        }

        return $this->resolveCategoryByLegacyValue(
            connection: $connection,
            table: $table,
            legacyValue: $legacyValue,
            categoriesById: $categoriesById,
            categoriesByKey: $categoriesByKey,
            categoriesByName: $categoriesByName,
            fallback: $fallback,
        );
    }

    private function resolveCategoryById(
        ?string $connection,
        string $table,
        int $id,
        array &$categoriesById,
        array &$categoriesByKey,
        array &$categoriesByName,
    ): ?array {
        if ($id <= 0) {
            return null;
        }

        if (isset($categoriesById[$id])) {
            return $categoriesById[$id];
        }

        $row = DB::connection($connection)
            ->table($table)
            ->where('id', $id)
            ->first(['id', 'key', 'name']);

        if ($row === null) {
            return null;
        }

        $key = trim((string) ($row->key ?? ''));

        if (! Str::isUuid($key)) {
            $key = $this->uniqueUuid($connection, $table);

            DB::connection($connection)
                ->table($table)
                ->where('id', $id)
                ->update([
                    'key' => $key,
                    'updated_at' => now(),
                ]);
        }

        $name = $this->normalizeCategoryName($row->name ?? null);

        if ($name === '') {
            $name = 'General';

            DB::connection($connection)
                ->table($table)
                ->where('id', $id)
                ->update([
                    'name' => $name,
                    'updated_at' => now(),
                ]);
        }

        $category = [
            'id' => $id,
            'key' => $key,
            'name' => $name,
        ];

        $this->storeCategoryInCache($categoriesById, $categoriesByKey, $categoriesByName, $category);

        return $category;
    }

    private function resolveCategoryByLegacyValue(
        ?string $connection,
        string $table,
        mixed $legacyValue,
        array &$categoriesById,
        array &$categoriesByKey,
        array &$categoriesByName,
        ?array $fallback,
    ): array {
        $raw = is_string($legacyValue) ? trim($legacyValue) : '';

        if ($raw !== '' && Str::isUuid($raw)) {
            $fromKey = $this->resolveCategoryByKey($connection, $table, $raw, $categoriesById, $categoriesByKey, $categoriesByName);

            if ($fromKey !== null) {
                return $fromKey;
            }
        }

        $name = $this->normalizeCategoryName($legacyValue);

        if ($name === '') {
            if ($fallback !== null) {
                return $fallback;
            }

            $name = 'General';
        }

        $nameIndex = Str::lower($name);

        if (isset($categoriesByName[$nameIndex])) {
            return $categoriesByName[$nameIndex];
        }

        $created = [
            'id' => (int) DB::connection($connection)->table($table)->insertGetId([
                'key' => $this->uniqueUuid($connection, $table),
                'name' => $name,
                'description' => null,
                'meta' => json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'key' => '',
            'name' => $name,
        ];

        $fresh = DB::connection($connection)
            ->table($table)
            ->where('id', $created['id'])
            ->first(['key']);

        $created['key'] = is_string($fresh?->key ?? null) ? trim((string) $fresh->key) : '';

        if ($created['key'] === '') {
            $created['key'] = $this->uniqueUuid($connection, $table);

            DB::connection($connection)
                ->table($table)
                ->where('id', $created['id'])
                ->update([
                    'key' => $created['key'],
                    'updated_at' => now(),
                ]);
        }

        $this->storeCategoryInCache($categoriesById, $categoriesByKey, $categoriesByName, $created);

        return $created;
    }

    private function resolveCategoryByKey(
        ?string $connection,
        string $table,
        string $key,
        array &$categoriesById,
        array &$categoriesByKey,
        array &$categoriesByName,
    ): ?array {
        $normalizedKey = trim($key);

        if ($normalizedKey === '' || ! Str::isUuid($normalizedKey)) {
            return null;
        }

        if (isset($categoriesByKey[$normalizedKey])) {
            return $categoriesByKey[$normalizedKey];
        }

        $row = DB::connection($connection)
            ->table($table)
            ->where('key', $normalizedKey)
            ->first(['id', 'key', 'name']);

        if ($row === null) {
            return null;
        }

        $id = is_numeric($row->id ?? null) ? (int) $row->id : 0;

        if ($id <= 0) {
            return null;
        }

        $name = $this->normalizeCategoryName($row->name ?? null);

        if ($name === '') {
            $name = 'General';
        }

        $category = [
            'id' => $id,
            'key' => $normalizedKey,
            'name' => $name,
        ];

        $this->storeCategoryInCache($categoriesById, $categoriesByKey, $categoriesByName, $category);

        return $category;
    }

    private function storeCategoryInCache(
        array &$categoriesById,
        array &$categoriesByKey,
        array &$categoriesByName,
        array $category,
    ): void {
        $id = (int) ($category['id'] ?? 0);
        $key = trim((string) ($category['key'] ?? ''));
        $name = $this->normalizeCategoryName($category['name'] ?? null);

        if ($id <= 0 || $key === '' || ! Str::isUuid($key) || $name === '') {
            return;
        }

        $normalized = [
            'id' => $id,
            'key' => $key,
            'name' => $name,
        ];

        $categoriesById[$id] = $normalized;
        $categoriesByKey[$key] = $normalized;
        $categoriesByName[Str::lower($name)] = $normalized;
    }

    private function uniqueUuid(?string $connection, string $table): string
    {
        do {
            $candidate = (string) Str::uuid();
            $exists = DB::connection($connection)->table($table)->where('key', $candidate)->exists();
        } while ($exists);

        return $candidate;
    }

    private function normalizeCategoryName(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $value = str_replace(['-', '_'], ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value);

        if (! is_string($value) || $value === '') {
            return '';
        }

        return Str::headline($value);
    }
};
