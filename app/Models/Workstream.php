<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Workstream extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['type', 'name', 'normalized_name', 'code', 'description', 'department_id', 'external_id', 'active'];

    protected $casts = ['active' => 'boolean'];

    protected static function booted(): void
    {
        static::saving(function (Workstream $workstream) {
            $workstream->name = Str::squish($workstream->name);
            $workstream->normalized_name = self::normalizeName($workstream->name);
            $workstream->code = filled($workstream->code) ? Str::upper(trim((string) $workstream->code)) : null;
        });
    }

    public static function normalizeName(string $name): string
    {
        return Str::lower(Str::squish($name));
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
