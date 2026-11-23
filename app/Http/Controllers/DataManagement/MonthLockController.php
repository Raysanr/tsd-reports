<?php

namespace App\Http\Controllers\DataManagement;

use App\Http\Controllers\Controller;
use App\Support\ActivityLogger;
use App\Support\DmMonthLock;
use Illuminate\Http\Request;

/** Month locks page (2026-11-23): admins lock a closed month so no Data
 *  Management page can change it, or unlock it again. */
class MonthLockController extends Controller
{
    public function index()
    {
        $locks = DmMonthLock::all()->keyBy('month');
        $users = \App\Models\User::whereIn('id', $locks->pluck('locked_by')->filter())->pluck('name', 'id');
        // the last 12 finished months, newest first, plus any older locked ones
        $months = collect(range(1, 12))->map(fn ($i) => now()->startOfMonth()->subMonthsNoOverflow($i)->format('Y-m'))
            ->merge($locks->keys())->unique()->sortDesc()->values();

        return view('data.month-locks', compact('locks', 'users', 'months'));
    }

    public function store(Request $request)
    {
        $month = $request->validate(['month' => ['required', 'date_format:Y-m']])['month'];
        abort_if($month >= now()->format('Y-m'), 422, 'Only a finished month can be locked.');
        DmMonthLock::lock($month, $request->user()->id);
        ActivityLogger::log('data.month_locked', null, 'Locked ' . DmMonthLock::label($month) . ' in Data Management (' . DmMonthLock::dataset() . ')');

        return back()->with('success', DmMonthLock::label($month) . ' is locked.');
    }

    public function destroy(Request $request)
    {
        $month = $request->validate(['month' => ['required', 'date_format:Y-m']])['month'];
        DmMonthLock::unlock($month);
        ActivityLogger::log('data.month_unlocked', null, 'Unlocked ' . DmMonthLock::label($month) . ' in Data Management (' . DmMonthLock::dataset() . ')');

        return back()->with('success', DmMonthLock::label($month) . ' is unlocked.');
    }
}
