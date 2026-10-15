<?php

namespace App\Http\Controllers\CallTracker;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadSyncRun;
use App\Models\LeadSyncSkip;

/** Ported from call-tracker (merged into one app 2026-08-12) — reads the
 *  renamed LeadSyncRun (that app's own SyncRun; tsd-reports' own SyncRun
 *  model, a different Order-sync concept, is untouched). */
class SyncHealthController extends Controller
{
    public function index()
    {
        $runs = LeadSyncRun::orderByDesc('ran_at')->paginate(30);

        // "Not distributed today" (explicit report, 2026-10-15: orders like
        // #1381321 with no assignee never reached Call Tracker) — every
        // today's Pancake order that isn't a working lead, with the reason:
        // skipped by the sync (LeadSyncSkip), or pulled in but not
        // assignable (item not in Product Management / no TSA online).
        $from = now('Asia/Manila')->startOfDay();
        $to = now('Asia/Manila')->endOfDay();
        $notDistributed = LeadSyncSkip::whereBetween('order_created_at', [$from, $to])->get()
            ->map(fn (LeadSyncSkip $s) => [
                'order' => $s->pancake_order_id, 'created' => $s->order_created_at, 'customer' => $s->customer_name,
                'phone' => $s->phone_number, 'item' => $s->item_name, 'reason' => $s->reasonLabel(), 'detail' => $s->detail,
            ])
            ->concat(Lead::whereBetween('pancake_created_at', [$from, $to])
                ->where(fn ($q) => $q->whereNull('product_id')->orWhere('status', 'unassigned'))
                ->with('product')->get()
                ->map(fn (Lead $l) => [
                    'order' => $l->pancake_order_id, 'created' => $l->pancake_created_at, 'customer' => $l->customer_name,
                    'phone' => $l->phone_number, 'item' => $l->product?->display_name,
                    'reason' => $l->product_id === null ? 'Item not in Product Management (lead hidden)' : 'Waiting — no TSA online for this product',
                    'detail' => null,
                ]))
            ->sortByDesc('created')->values();

        return view('calls.sync-health', ['runs' => $runs, 'notDistributed' => $notDistributed]);
    }
}
