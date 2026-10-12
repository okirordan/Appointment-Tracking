<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    public static function value(string $key, ?string $default = null): ?string
    {
        // Cache misses need a non-null wrapper: Laravel treats a cached null
        // as absent and would query the settings table on every request.
        $stored = cache()->rememberForever("setting:{$key}",
            fn () => ['value' => static::find($key)?->getAttribute('value')]);

        // Existing deployments may still hold scalar values from the old format.
        return (is_array($stored) ? ($stored['value'] ?? null) : $stored) ?? $default;
    }

    public static function put(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        cache()->forget("setting:{$key}");
    }
}
