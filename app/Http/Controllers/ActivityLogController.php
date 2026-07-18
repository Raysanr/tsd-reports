<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;

class ActivityLogController extends Controller
{
    public function index()
    {
        $logs = ActivityLog::with('user')->orderByDesc('created_at')->paginate(30)->withQueryString();

        return view('audit-log', compact('logs'));
    }
}
