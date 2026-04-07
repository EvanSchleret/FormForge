<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Models;

use EvanSchleret\FormForge\Support\ModelClassResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SubmissionPrivacyOverride extends Model
{
    protected $guarded = [];

    protected $casts = [
        'execute_at' => 'datetime',
        'processed_at' => 'datetime',
        'anonymize_fields' => 'array',
        'delete_files' => 'boolean',
        'redact_submitter' => 'boolean',
        'redact_network' => 'boolean',
    ];

    public function getTable(): string
    {
        return (string) config('formforge.database.submission_privacy_overrides_table', 'formforge_submission_privacy_overrides');
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(ModelClassResolver::formSubmission(), 'form_submission_id');
    }

    public function requestedBy(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'requested_by_type', 'requested_by_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('processed_at');
    }
}

