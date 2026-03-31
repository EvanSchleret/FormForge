<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Models;

use EvanSchleret\FormForge\Support\ModelClassResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class FormCategory extends Model
{
    protected $guarded = [];

    protected $casts = [
        'meta' => 'array',
        'is_active' => 'boolean',
        'is_system' => 'boolean',
    ];

    public function getTable(): string
    {
        return (string) config('formforge.database.categories_table', 'formforge_categories');
    }

    public function forms(): HasMany
    {
        return $this->hasMany(ModelClassResolver::formDefinition(), 'form_category_id');
    }

    public function owner(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'owner_type', 'owner_id');
    }

    public function scopeByKey(Builder $query, string $key): Builder
    {
        return $query->where('key', $key);
    }
}
