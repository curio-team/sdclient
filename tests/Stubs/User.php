<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}
