<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\OrganizationalUnit;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SearchCache
{
    // Rotate the namespace when cache authorization semantics change so
    // pre-deployment search payloads cannot outlive the code update.
    private const VERSION_KEY = 'ats:search:index-version:v2';

    private const AUTHORIZATION_VERSION = 'ps-office-assignment-scope-v4';

    public static function version(): string
    {
        return (string) Cache::rememberForever(
            self::VERSION_KEY,
            fn () => (string) Str::uuid(),
        );
    }

    public static function invalidate(): void
    {
        self::rotateVersion();

        // A separate cache backend can publish an invalidation before a
        // transaction commits. Rotate again after commit so no reader keeps
        // pre-revocation data under the new version.
        if (DB::transactionLevel() > 0) {
            DB::afterCommit(fn () => self::rotateVersion());
        }
    }

    private static function rotateVersion(): void
    {
        Cache::forever(self::VERSION_KEY, (string) Str::uuid());
    }

    public static function resultKey(
        User $user,
        string $term,
        string $type,
        bool $includeSuggestion,
        int $page,
        int $perPage,
    ): string {
        $scope = [
            'version' => self::version(),
            'user' => $user->getKey(),
            'authorization_scope' => self::scopeFingerprint($user),
            'mail_matcher' => (bool) config('ats.search.indexed_mail_documents'),
            'term' => mb_strtolower(trim($term)),
            'type' => $type,
            'suggestion' => $includeSuggestion,
            'page' => $page,
            'per_page' => $perPage,
        ];

        return 'ats:search:result:'.hash('sha256', json_encode($scope, JSON_THROW_ON_ERROR));
    }

    public static function scopeFingerprint(User $user): string
    {
        $scope = [
            'authorization_version' => self::AUTHORIZATION_VERSION,
            'role' => $user->role->value,
            'department' => $user->department_id,
            'division' => $user->division_id,
            'organizational_unit' => $user->organizational_unit_id,
        ];

        if ($user->role === Role::Secretary) {
            $scope['current_attachment'] = $user->currentSecretaryAttachment()->value('id');
            $scope['has_attachment_history'] = $user->secretaryOfficeAttachments()->exists();
            $scope['direct_units'] = OrganizationalUnit::query()
                ->where('active', true)
                ->where('secretary_user_id', $user->id)
                ->orderBy('id')
                ->pluck('id')
                ->all();
        }

        if (in_array($user->role, [Role::Secretary, Role::Officer], true)) {
            $scope['current_position'] = $user->currentPositionAssignment()->value('id');
        }

        return hash('sha256', json_encode($scope, JSON_THROW_ON_ERROR));
    }
}
