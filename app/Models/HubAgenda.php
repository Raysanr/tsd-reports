<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One agenda on the Hub calendar — see HubCalendarController. */
class HubAgenda extends Model
{
    public const COLORS = ['blue', 'purple', 'amber', 'rose', 'teal', 'slate'];

    protected $fillable = ['date', 'title', 'time', 'note', 'color', 'created_by'];

    protected $casts = ['date' => 'date'];

    public function toCalendarArray(): array
    {
        return [
            'id'    => $this->id,
            'date'  => $this->date->toDateString(),
            'title' => $this->title,
            'time'  => $this->time,
            'note'  => $this->note,
            'color' => $this->color,
        ];
    }
}
