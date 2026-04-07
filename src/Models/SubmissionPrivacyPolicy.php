<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SubmissionPrivacyPolicy extends Model
{
    protected $guarded = [];

    protected $casts = [
        'after_days' => 'integer',
        'anonymize_fields' => 'array',
        'delete_files' => 'boolean',
        'redact_submitter' => 'boolean',
        'redact_network' => 'boolean',
        'enabled' => 'boolean',
    ];

    public function getTable(): string
    {
        return (string) config('formforge.database.privacy_policies_table', 'formforge_privacy_policies');
    }

    public function scopeGlobal(Builder $query): Builder
    {
        return $query->where('scope', 'global');
    }

    public function scopeForForm(Builder $query, string $formKey): Builder
    {
        return $query
            ->where('scope', 'form')
            ->where('form_key', $formKey);
    }
}

