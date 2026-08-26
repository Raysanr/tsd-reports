<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** Every valid role, most to least privileged. */
    public const ROLES = ['super_admin', 'admin', 'normal', 'guest'];

    public const ROLE_LABELS = [
        'super_admin' => 'Super Admin',
        'admin'       => 'Admin',
        'normal'      => 'Normal User',
        'guest'       => 'Guest',
        // Not in ROLES/assignableRoles() — deliberately never created via
        // User Management's general "Add User" form, which has no tsa_id
        // field to link. Only ever created via TsaManagementController::
        // linkLogin() (explicit request, 2026-08-26), which sets tsa_id in
        // the same write. Kept here only so this role still gets a proper
        // label instead of a raw "tsa" string if one of these accounts ever
        // shows up in User Management's own account list.
        'tsa'         => 'TSA',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'google_id',
        'avatar',
        'role',
        'is_active',
        // Ported from call-tracker (merged into one app 2026-08-12) — which
        // TSA row this user is, for Call Tracker's per-TSA pages. Null for
        // every user who isn't a TSA (most admins/normal reporting users).
        'tsa_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    /** "Online" is derived from a recent last_seen_at (see TrackLastSeen
     *  middleware) rather than a manually toggled flag — 5 minutes matches
     *  the sidebar's own 30s notification poll comfortably while still
     *  reading as "actually here right now", not "logged in at some point
     *  today". */
    public function isOnline(): bool
    {
        return $this->last_seen_at !== null && $this->last_seen_at->gt(now()->subMinutes(5));
    }

    /** Ported from call-tracker (merged into one app 2026-08-12). */
    public function tsa(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(TsaShift::class, 'tsa_id');
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /** Super Admin or Admin — the two roles with CONFIG-page access. */
    public function isAtLeastAdmin(): bool
    {
        return in_array($this->role, ['super_admin', 'admin'], true);
    }

    /**
     * Whether $this (the acting user) is allowed to create/edit/deactivate
     * $target's account, per the design spec's permission matrix: Super Admin
     * manages everyone but themselves; Admin manages Normal/Guest only; nobody
     * manages their own row through this page (self-service isn't in scope —
     * see the spec's "Out of scope" section).
     */
    public function canManage(User $target): bool
    {
        if ($this->is($target)) {
            return false;
        }

        if ($this->isSuperAdmin()) {
            return true;
        }

        if ($this->isAdmin()) {
            return !$target->isAtLeastAdmin();
        }

        return false;
    }

    /** Roles $this is allowed to assign when creating/editing another user. */
    public function assignableRoles(): array
    {
        if ($this->isSuperAdmin()) {
            return self::ROLES;
        }

        if ($this->isAdmin()) {
            return ['normal', 'guest'];
        }

        return [];
    }

    /** Used to guard against deactivating/demoting the last active Super Admin. */
    public static function activeSuperAdminCount(): int
    {
        return static::where('role', 'super_admin')->where('is_active', true)->count();
    }
}
