<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    /** Always the main database, even inside Partners Data Management
     *  (App\Support\DataSet), which switches the default connection. */
    public function getConnectionName()
    {
        return \App\Support\DataSet::mainConnection();
    }

    const UPDATED_AT = null; // append-only, never edited

    protected $fillable = ['user_id', 'actor_name', 'actor_email', 'action', 'subject_type', 'subject_id', 'description'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
