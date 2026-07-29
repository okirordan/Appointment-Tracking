<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MailAttachment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'mail_record_id', 'original_filename', 'storage_key', 'mime_type',
        'size_bytes', 'checksum', 'uploaded_by_user_id', 'uploaded_at',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'uploaded_at' => 'datetime',
    ];

    public function mailRecord(): BelongsTo
    {
        return $this->belongsTo(MailRecord::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function previewKind(): string
    {
        $extension = strtolower(pathinfo($this->original_filename, PATHINFO_EXTENSION));

        return match (true) {
            $this->mime_type === 'application/pdf' || $extension === 'pdf' => 'pdf',
            str_starts_with($this->mime_type, 'image/') => 'image',
            str_starts_with($this->mime_type, 'video/') => 'video',
            in_array($extension, ['docx', 'xlsx', 'pptx'], true) => 'document',
            default => 'none',
        };
    }
}
