<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalMailSource extends Model
{
    protected $fillable = ['name', 'normalized_name', 'kind', 'created_by_user_id'];

    public static function displayName(string $name): string
    {
        return preg_replace('/\s+/u', ' ', trim($name)) ?? trim($name);
    }

    public static function normalize(string $name): string
    {
        return mb_strtolower(self::displayName($name));
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id')->withTrashed();
    }
}
