<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class FormDraft extends Model
{
    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'meta' => 'array',
        'expires_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return (string) config('formforge.database.drafts_table', 'formforge_drafts');
    }

    public function scopeForOwner(Builder $query, string $ownerType, string $ownerId): Builder
    {
        return $query
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId);
    }

    public function scopeForForm(Builder $query, string $formKey): Builder
    {
        return $query->where('form_key', $formKey);
    }

    public function scopeNotExpired(Builder $query): Builder
    {
        return $query->where(static function (Builder $query): void {
            $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', Carbon::now());
        });
    }
}
