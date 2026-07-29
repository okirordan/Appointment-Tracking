<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Position extends Model
{
    use SoftDeletes;

    protected $fillable = ['organizational_unit_id', 'role_id', 'supervisor_position_id', 'title', 'hierarchy_level', 'workflow_capabilities', 'active'];

    protected $casts = ['workflow_capabilities' => 'array', 'hierarchy_level' => 'integer', 'active' => 'boolean'];

    public function organizationalUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function supervisorPosition(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supervisor_position_id');
    }

    public function subordinatePositions(): HasMany
    {
        return $this->hasMany(self::class, 'supervisor_position_id');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(UserPosition::class);
    }
}
