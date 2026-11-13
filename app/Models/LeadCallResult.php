<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One call-result tag added to a lead's order in Pancake, with the exact
 *  time and the TSA who added it (2026-11-13). See App\Support\LeadCallResults. */
class LeadCallResult extends Model
{
    public $timestamps = false;

    protected $fillable = ['lead_id', 'result', 'tsa_id', 'occurred_at'];

    protected $casts = ['occurred_at' => 'datetime'];
}
