<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Someone not in User Management tried "Sign in with Google" (2026-11-23).
 *  An admin Accepts (creates their account) or Rejects (blocks them). */
class AccessRequest extends Model
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    protected $fillable = ['email', 'name', 'google_id', 'avatar', 'status', 'attempts', 'last_attempt_at', 'decided_by', 'decided_at'];

    protected $casts = ['last_attempt_at' => 'datetime', 'decided_at' => 'datetime'];

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** Record a sign-in attempt; returns the request (new or existing). */
    public static function recordAttempt(string $email, ?string $name, ?string $googleId, ?string $avatar): self
    {
        $email = mb_strtolower(trim($email));
        $req = self::firstOrNew(['email' => $email]);
        if (! $req->exists) {
            $req->fill(['status' => self::PENDING, 'attempts' => 0]);
        } elseif ($req->status === self::APPROVED) {
            // accepted before, but the account was since deleted: ask again
            $req->fill(['status' => self::PENDING, 'decided_by' => null, 'decided_at' => null]);
        }
        $req->fill([
            'name' => $name ?: $email,
            'google_id' => $googleId,
            'avatar' => $avatar,
            'attempts' => $req->attempts + 1,
            'last_attempt_at' => now(),
        ])->save();

        return $req;
    }
}
