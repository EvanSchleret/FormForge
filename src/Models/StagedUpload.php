<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class StagedUpload extends Model
{
    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return (string) config('formforge.database.staged_uploads_table', 'formforge_staged_uploads');
    }

    public function scopeByToken(Builder $query, string $token): Builder
    {
        return $query->where('token', $token);
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query
            ->whereNull('consumed_at')
            ->where(static function (Builder $query): void {
                $query
                    ->whereNull('expires_at')
                    ->orWhere('expires_at', '>', Carbon::now());
            });
    }
}
