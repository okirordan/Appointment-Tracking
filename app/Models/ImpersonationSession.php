<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImpersonationSession extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['started_at' => 'datetime', 'expires_at' => 'datetime', 'ended_at' => 'datetime'];
}
