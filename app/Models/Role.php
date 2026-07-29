<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    protected $fillable = [
        'name',
        'display_name',
        'guard_name',
        'description',
        'hierarchy_level',
        'is_active',
        'is_system',
    ];

    protected $casts = [
        'hierarchy_level' => 'integer',
        'is_active' => 'boolean',
        'is_system' => 'boolean',
    ];

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    public function label(): string
    {
        return $this->display_name ?: str($this->name)->replace(['-', '_'], ' ')->title()->toString();
    }
}
