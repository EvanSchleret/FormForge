<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class FormSubmission extends Model
{
    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'is_test' => 'boolean',
        'meta' => 'array',
    ];

    public function getTable(): string
    {
        return (string) config('formforge.database.submissions_table', 'formforge_submissions');
    }

    public function files(): HasMany
    {
        return $this->hasMany(SubmissionFile::class, 'form_submission_id');
    }

    public function automationRuns(): HasMany
    {
        return $this->hasMany(SubmissionAutomationRun::class, 'form_submission_id');
    }

    public function submitter(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'submitted_by_type', 'submitted_by_id');
    }

    public function scopeWhereForm(Builder $query, string $formKey): Builder
    {
        return $query->where('form_key', $formKey);
    }

    public function scopeWhereVersion(Builder $query, string $formVersion): Builder
    {
        return $query->where('form_version', $formVersion);
    }

    public function scopeWhereTest(Builder $query, bool $isTest = true): Builder
    {
        return $query->where('is_test', $isTest);
    }
}
