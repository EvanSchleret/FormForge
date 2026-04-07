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
        $table = (string) config('formforge.database.categories_table', 'formforge_categories');

        if (! Schema::connection($connection)->hasTable($table)) {
            return;
        }

        if (! Schema::connection($connection)->hasColumn($table, 'slug')) {
            Schema::connection($connection)->table($table, function (Blueprint $table): void {
                $table->string('slug')->nullable()->after('key');
            });
        }

        $used = [];

        DB::connection($connection)
            ->table($table)
            ->select(['id', 'name', 'slug'])
            ->orderBy('id')
            ->chunk(500, function ($rows) use ($connection, $table, &$used): void {
                foreach ($rows as $row) {
                    $id = is_numeric($row->id ?? null) ? (int) $row->id : null;

                    if ($id === null || $id <= 0) {
                        continue;
                    }

                    $rawSlug = is_string($row->slug ?? null) ? trim((string) $row->slug) : '';
                    $baseSlug = $this->normalizeSlug($rawSlug);

                    if ($baseSlug === null) {
                        $name = is_string($row->name ?? null) ? (string) $row->name : '';
                        $baseSlug = $this->normalizeSlug($name) ?? 'category';
                    }

                    $slug = $this->uniqueSlug($baseSlug, $used, $connection, $table, $id);

                    if ($rawSlug !== $slug) {
                        DB::connection($connection)
                            ->table($table)
                            ->where('id', $id)
                            ->update([
                                'slug' => $slug,
                                'updated_at' => now(),
                            ]);
                    }

                    $used[strtolower($slug)] = true;
                }
            });

        try {
            Schema::connection($connection)->table($table, function (Blueprint $table): void {
                $table->index('slug', 'formforge_categories_slug_index');
            });
        } catch (\Throwable) {
        }
    }

    public function down(): void
    {
        $connection = config('formforge.database.connection');
        $table = (string) config('formforge.database.categories_table', 'formforge_categories');

        if (! Schema::connection($connection)->hasTable($table)) {
            return;
        }

        try {
            Schema::connection($connection)->table($table, function (Blueprint $table): void {
                $table->dropIndex('formforge_categories_slug_index');
            });
        } catch (\Throwable) {
        }

        if (! Schema::connection($connection)->hasColumn($table, 'slug')) {
            return;
        }

        Schema::connection($connection)->table($table, function (Blueprint $table): void {
            $table->dropColumn('slug');
        });
    }

    private function normalizeSlug(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $slug = trim((string) Str::slug($value), '-');

        return $slug === '' ? null : $slug;
    }

    private function uniqueSlug(
        string $baseSlug,
        array $used,
        mixed $connection,
        string $table,
        int $ignoreId,
    ): string {
        $candidate = $baseSlug;
        $counter = 2;

        while ($this->slugExists($candidate, $used, $connection, $table, $ignoreId)) {
            $candidate = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $candidate;
    }

    private function slugExists(
        string $candidate,
        array $used,
        mixed $connection,
        string $table,
        int $ignoreId,
    ): bool {
        $index = strtolower($candidate);

        if (isset($used[$index])) {
            return true;
        }

        return DB::connection($connection)
            ->table($table)
            ->where('slug', $candidate)
            ->where('id', '!=', $ignoreId)
            ->exists();
    }
};
