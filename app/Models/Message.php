<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Direct message between two User accounts — see the create_messages_table
 *  migration's own doc comment for the full feature scope. */
class Message extends Model
{
    protected $fillable = ['sender_id', 'recipient_id', 'body', 'read_at'];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    /** Every message either sent OR received by $user, with the OTHER
     *  party in the pair (their partnerId). Scoped like this (not a bare
     *  ::where('sender_id', $user->id)) so a single query serves both
     *  directions of a 1:1 thread — used by MessageController's own
     *  thread() to fetch a conversation and by its own inbox() to derive
     *  the distinct list of people $user has ever messaged with. */
    public function scopeInvolving($query, int $userId, ?int $partnerId = null)
    {
        return $query->where(function ($q) use ($userId, $partnerId) {
            $q->where(function ($q2) use ($userId, $partnerId) {
                $q2->where('sender_id', $userId);
                if ($partnerId !== null) $q2->where('recipient_id', $partnerId);
            })->orWhere(function ($q2) use ($userId, $partnerId) {
                $q2->where('recipient_id', $userId);
                if ($partnerId !== null) $q2->where('sender_id', $partnerId);
            });
        });
    }
}
