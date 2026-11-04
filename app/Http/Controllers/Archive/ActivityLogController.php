<?php

namespace App\Http\Controllers\Archive;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;

class ActivityLogController extends Controller
{
    public function index()
    {
        return view('archive.activity-log', [
            'logs' => ActivityLog::where('action', 'like', 'archive.%')->latest('created_at')->paginate(40),
        ]);
    }
}
