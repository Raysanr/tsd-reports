<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        // ?q= — the topbar global search's Audit Log results link here with the
        // exact term already applied server-side, since the existing client-side
        // data-table-filter only filters rows on the CURRENT page of 30 and would
        // miss a match sitting on page 2+. The filter input's value is pre-filled
        // from this same $query so it visibly reflects what's already applied,
        // even though at that point it's just further narrowing an already-
        // filtered page (harmless — typing something different simply replaces it).
        $query = trim((string) $request->query('q', ''));

        $logs = ActivityLog::with('user')
            ->when($query !== '', fn ($q) => $q->where('description', 'like', "%{$query}%"))
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        return view('audit-log', compact('logs', 'query'));
    }
}
