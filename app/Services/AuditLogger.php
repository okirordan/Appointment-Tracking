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
        string $severity = 'info',
    ): void {
        $request = request();
        $redactor = app(LogRedactor::class);
        $support = $request?->hasSession() ? app(ImpersonationService::class)->current($request) : null;
        if ($support) {
            $metadata['support_id'] = $support->id;
            $metadata['super_admin_id'] = $support->actor_user_id;
            $metadata['super_admin_name'] = User::find($support->actor_user_id)?->full_name;
            $metadata['impersonated_user_id'] = $support->target_user_id;
        }

        AuditLog::create([
            'actor_user_id' => $actor?->id,
            'actor_name_snapshot' => mb_substr($redactor->text($actor?->full_name ?? $actorName ?? 'System'), 0, 255),
            'category' => $category,
            'action' => mb_substr($redactor->text($action), 0, 255),
            'target_type' => $targetType,
            'target_id' => $targetId,
            'metadata_json' => $metadata === [] ? null : $redactor->clean($metadata),
            'ip_address' => $request?->ip(),
            'user_agent' => $request === null ? null : substr((string) $request->userAgent(), 0, 500),
            'outcome' => $outcome,
            'severity' => $severity,
            'created_at' => now(),
        ]);
    }
}
