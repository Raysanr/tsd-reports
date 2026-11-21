<?php

namespace App\Http\Controllers\Archive;

use App\Http\Controllers\Controller;
use App\Support\ArchiveUndo;

class UndoController extends Controller
{
    public function __invoke(string $token)
    {
        $label = ArchiveUndo::apply($token);

        return back()->with($label ? 'success' : 'error', $label
            ? "Undone: {$label}"
            : 'That change can no longer be undone (Undo lasts ' . ArchiveUndo::MINUTES . ' minutes).');
    }
}
