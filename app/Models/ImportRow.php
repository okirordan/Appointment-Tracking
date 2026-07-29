<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportRow extends Model
{
    protected $fillable = ['import_batch_id', 'row_number', 'status', 'normalized_json', 'issues_json', 'matched_type', 'matched_id'];

    protected $casts = ['normalized_json' => 'array', 'issues_json' => 'array'];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }
}
