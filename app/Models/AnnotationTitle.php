<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AnnotationTitle extends Model
{
    protected $fillable = [
        'shorthand', 'normalized_shorthand', 'full_title', 'normalized_full_title',
        'active', 'created_by_user_id', 'updated_by_user_id',
    ];

    protected $casts = ['active' => 'boolean'];

    protected static function booted(): void
    {
        static::saving(function (self $title): void {
            $title->shorthand = self::displayShorthand($title->shorthand);
            $title->full_title = preg_replace('/\s+/u', ' ', trim($title->full_title)) ?? trim($title->full_title);
            $title->normalized_shorthand = self::normalize($title->shorthand);
            $title->normalized_full_title = self::normalize($title->full_title);
        });
    }

    public static function normalize(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', Str::lower(Str::ascii(trim($value)))) ?? '';
    }

    public static function displayShorthand(string $value): string
    {
        return Str::upper(preg_replace('/\s*([\/&-])\s*/u', '$1', trim($value)) ?? trim($value));
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id')->withTrashed();
    }
}
