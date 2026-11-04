<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LibraryVersion extends Model
{
    const UPDATED_AT = null; // append-only

    protected $fillable = ['library_item_id', 'version', 'title', 'body', 'change_note', 'user_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
