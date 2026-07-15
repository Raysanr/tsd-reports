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
        ];
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
