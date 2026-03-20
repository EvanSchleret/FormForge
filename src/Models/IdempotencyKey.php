<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Models;

use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    protected $guarded = [];

    protected $casts = [
        'response_body' => 'array',
        'expires_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return (string) config('formforge.database.idempotency_keys_table', 'formforge_idempotency_keys');
    }
}
