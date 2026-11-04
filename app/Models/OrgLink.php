<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An extra ("also reports to") Org Chart line — see the org_links migration. */
class OrgLink extends Model
{
    protected $fillable = ['type', 'kind', 'parent_id', 'child_id'];

    protected $casts = ['parent_id' => 'integer', 'child_id' => 'integer'];
}
