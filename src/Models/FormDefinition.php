<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Models;

use EvanSchleret\FormForge\Support\ModelClassResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FormDefinition extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'schema' => 'array',
        'meta' => 'array',
        'is_active' => 'boolean',
        'is_published' => 'boolean',
        'published_at' => 'datetime',
        'version_number' => 'integer',
        'deleted_at' => 'datetime',
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

    public function categoryModel(): BelongsTo
    {
        return $this->belongsTo(ModelClassResolver::formCategory(), 'form_category_id');
    }

    public function owner(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'owner_type', 'owner_id');
    }
}
