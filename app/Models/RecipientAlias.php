<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use InvalidArgumentException;

class RecipientAlias extends Model
{
    use SoftDeletes;

    /** @var array<string, class-string<Model>> */
    public const TARGET_TYPES = [
        'officer' => User::class,
        'position' => Position::class,
        'department' => Department::class,
        'directorate' => Division::class,
        'unit' => OrganizationalUnit::class,
    ];

    protected $fillable = [
        'alias',
        'normalized_alias',
        'target_type',
        'target_id',
        'active',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $casts = ['active' => 'boolean'];

    protected static function booted(): void
    {
        static::saving(function (self $alias): void {
            $alias->alias = trim($alias->alias);
            $alias->normalized_alias = self::normalize($alias->alias);
        });
    }

    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id')->withTrashed();
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id')->withTrashed();
    }

    public static function normalize(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', Str::lower(Str::ascii(trim($value)))) ?? '';
    }

    /** @return class-string<Model> */
    public static function targetClass(string $key): string
    {
        return self::TARGET_TYPES[$key] ?? throw new InvalidArgumentException('Unsupported recipient alias target type.');
    }

    public static function targetKey(string $class): string
    {
        return array_search($class, self::TARGET_TYPES, true) ?: 'officer';
    }
}
