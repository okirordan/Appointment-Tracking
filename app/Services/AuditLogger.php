<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;

class AuditLogger
{
    /**
     * Write an immutable audit entry (PRD §12.19). Categories:
     * login|task|user|department|settings|report|security.
     *
     * @param  array<string, mixed>  $metadata  before/after or contextual values — never secrets
     */
    public function log(
        string $category,
        string $action,
        ?User $actor = null,
        ?string $targetType = null,
        ?int $targetId = null,
        array $metadata = [],
        string $outcome = 'success',
        ?string $actorName = null,
    ): void {
        $request = request();

        AuditLog::create([
            'actor_user_id' => $actor?->id,
            'actor_name_snapshot' => $actor?->full_name ?? $actorName ?? 'System',
            'category' => $category,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'metadata_json' => $metadata === [] ? null : $metadata,
            'ip_address' => $request?->ip(),
            'user_agent' => $request === null ? null : substr((string) $request->userAgent(), 0, 500),
            'outcome' => $outcome,
            'created_at' => now(),
        ]);
    }
}
