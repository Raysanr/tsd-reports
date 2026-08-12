<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Ported from call-tracker (merged into one app 2026-08-12). */
class PancakePageToken extends Model
{
    protected $fillable = ['page_id', 'page_access_token'];
}
