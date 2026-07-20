<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TagConflictReview extends Model
{
    protected $fillable = ['order_id', 'reviewed_by'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** firstOrCreate so clicking "Mark reviewed" twice (e.g. a slow double-click,
     *  or two admins on the same conflict) is a no-op, not a duplicate-key error —
     *  the unique constraint on order_id means only the first review ever sticks. */
    public static function markReviewed(int $orderId, ?int $userId): void
    {
        static::firstOrCreate(['order_id' => $orderId], ['reviewed_by' => $userId]);
    }
}
