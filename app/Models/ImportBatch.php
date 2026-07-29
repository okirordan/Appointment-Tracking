<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportBatch extends Model
{
    protected $fillable = ['initiated_by_user_id', 'source_system', 'entity_type', 'status', 'original_filename', 'storage_key', 'mime_type', 'size_bytes', 'checksum', 'mapping_json', 'total_rows', 'valid_rows', 'created_rows', 'updated_rows', 'skipped_rows', 'failed_rows', 'confirmed_at', 'completed_at'];

    protected $casts = ['mapping_json' => 'array', 'confirmed_at' => 'datetime', 'completed_at' => 'datetime'];

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class);
    }
}
