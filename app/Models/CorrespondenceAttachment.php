<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CorrespondenceAttachment extends Model
{
    protected $fillable = [
        'correspondence_id', 'correspondence_update_id', 'correspondence_forward_id',
        'version_group', 'version_number', 'supersedes_attachment_id', 'status',
        'original_filename', 'storage_key', 'mime_type', 'size_bytes', 'checksum',
        'uploaded_by_user_id', 'uploaded_at', 'removed_by_user_id', 'removed_at', 'removal_reason',
    ];

    protected $casts = [
        'version_number' => 'integer', 'size_bytes' => 'integer',
        'uploaded_at' => 'datetime', 'removed_at' => 'datetime',
    ];

    public function correspondence(): BelongsTo
    {
        return $this->belongsTo(Correspondence::class);
    }

    public function threadUpdate(): BelongsTo
    {
        return $this->belongsTo(CorrespondenceUpdate::class, 'correspondence_update_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id')->withTrashed();
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
