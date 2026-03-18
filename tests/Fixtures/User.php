<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    public $timestamps = false;

    protected $table = 'users';

    protected $guarded = [];
}
