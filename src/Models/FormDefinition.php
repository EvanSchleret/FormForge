<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class FormDefinition extends Model
{
    protected $guarded = [];

    protected $casts = [
        'schema' => 'array',
        'is_active' => 'boolean',
        'is_published' => 'boolean',
    ];

    public function getTable(): string
    {
        return (string) config('formforge.database.forms_table', 'formforge_forms');
    }

    public function scopeForKey(Builder $query, string $key): Builder
    {
        return $query->where('key', $key);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    public function scopeInCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }
}
