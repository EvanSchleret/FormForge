<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Models;

use EvanSchleret\FormForge\Support\ModelClassResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubmissionFile extends Model
{
    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function getTable(): string
    {
        return (string) config('formforge.database.submission_files_table', 'formforge_submission_files');
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(ModelClassResolver::formSubmission(), 'form_submission_id');
    }
}
