<?php

namespace App\Models;

use App\Enums\Role;
use App\Models\Role as PermissionRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, SoftDeletes, TwoFactorAuthenticatable;

    protected $fillable = [
        'username',
        'password',
        'full_name',
        'title',
        'email',
        'role',
        'department_id',
        'division_id',
        'supervisor_user_id',
        'employee_number',
        'external_id',
        'active',
        'locked',
        'force_password_change',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => Role::class,
            'active' => 'boolean',
            'locked' => 'boolean',
            'force_password_change' => 'boolean',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'password_reset_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Legacy accounts still receive their built-in role automatically.
        // Administrators may subsequently replace it with any configurable role.
        static::created(function (self $user) {
            if ($user->roles()->doesntExist()) {
                PermissionRole::findOrCreate($user->role->value, 'web');
                $user->syncRoles([$user->role->value]);
            }
        });
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supervisor_user_id')->withTrashed();
    }

    public function subordinates(): HasMany
    {
        return $this->hasMany(self::class, 'supervisor_user_id');
    }

    public function positionAssignments(): HasMany
    {
        return $this->hasMany(UserPosition::class);
    }

    public function secretaryOfficeAttachments(): HasMany
    {
        return $this->hasMany(SecretaryOfficeAttachment::class, 'secretary_user_id');
    }

    public function currentSecretaryAttachment(): HasOne
    {
        return $this->hasOne(SecretaryOfficeAttachment::class, 'secretary_user_id')
            ->where('active', true)
            ->where('starts_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->latestOfMany();
    }

    public function supportedBySecretaries(): HasMany
    {
        return $this->hasMany(SecretaryOfficeAttachment::class, 'supervisor_user_id');
    }

    public function currentPositionAssignment(): HasOne
    {
        return $this->hasOne(UserPosition::class)
            ->where('active', true)
            ->where('is_primary', true)
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->latestOfMany();
    }

    public function profileChanges(): HasMany
    {
        return $this->hasMany(UserProfileChange::class)->latest('created_at');
    }

    public function positionChanges(): HasMany
    {
        return $this->hasMany(UserPositionChange::class)->latest('changed_at');
    }

    public function lifecycleEvents(): HasMany
    {
        return $this->hasMany(UserLifecycleEvent::class)->latest('created_at');
    }

    public function permissionRole(): ?PermissionRole
    {
        $role = $this->relationLoaded('roles') ? $this->roles->first() : $this->roles()->first();

        return $role instanceof PermissionRole ? $role : null;
    }

    public function roleLabel(): string
    {
        $permissionRole = $this->permissionRole();
        if ($permissionRole === null || blank($permissionRole->display_name)) {
            return Role::tryFrom($permissionRole?->name ?? $this->role->value)?->label() ?? $permissionRole?->label() ?? $this->role->label();
        }

        return $permissionRole->label();
    }

    public function roleName(): string
    {
        return $this->permissionRole()?->name ?? $this->role->value;
    }

    public function isRoleActive(): bool
    {
        return $this->permissionRole()?->is_active ?? true;
    }

    /** In-app notifications (custom table, not Laravel's notifiable). */
    public function appNotifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function assignedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assigned_to_user_id');
    }

    public function initials(): string
    {
        return Str::of($this->full_name)
            ->explode(' ')
            ->filter()
            ->map(fn (string $word) => Str::upper(Str::substr($word, 0, 1)))
            ->take(2)
            ->implode('');
    }

    public function firstName(): string
    {
        return Str::before($this->full_name, ' ');
    }
}
