<?php

namespace App\Http\Controllers\CallTracker;

use App\Http\Controllers\Concerns\PersistsCallTrackerFilters;
use App\Http\Controllers\Controller;
use App\Models\CallRecording;
use App\Models\TsaShift;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Browser-facing counterpart to the app-root CallRecordingController (which
 * only handles the TSA-side upload via api_token, see its own doc comment)
 * — admin-only listing + playback of recordings already uploaded from each
 * TSA's PC (Phone Link + a system-audio recorder), filterable by date/TSA
 * same as Call Log. Route-gated by role:super_admin,admin (see routes/web.php),
 * not checked again here.
 */
class CallRecordingController extends Controller
{
    use PersistsCallTrackerFilters;

    public function index(Request $request)
    {
        $dateFrom = $this->rememberedFilter($request, 'call-recordings', 'date_from', now('Asia/Manila')->format('Y-m-d'));
        $dateTo   = $this->rememberedFilter($request, 'call-recordings', 'date_to', $dateFrom);
        $from     = Carbon::parse($dateFrom, 'Asia/Manila')->startOfDay();
        $to       = Carbon::parse($dateTo, 'Asia/Manila')->endOfDay();

        $tsaFilterInput = $this->rememberedFilter($request, 'call-recordings', 'tsa');
        $selectedTsa    = $tsaFilterInput ? (int) $tsaFilterInput : null;

        $recordings = CallRecording::with(['tsa', 'lead'])
            ->whereBetween('recorded_at', [$from, $to])
            ->when($selectedTsa, fn ($q) => $q->where('tsa_id', $selectedTsa))
            ->orderByDesc('recorded_at')
            ->get();

        $tsas = TsaShift::where('active', true)->orderBy('sort_order')->get();

        return view('calls.call-recordings', [
            'recordings'  => $recordings,
            'tsas'        => $tsas,
            'selectedTsa' => $selectedTsa,
            'dateFrom'    => $dateFrom,
            'dateTo'      => $dateTo,
        ]);
    }

    /** Range-aware: the disk's own `serve => true` config (see
     *  config/filesystems.php) makes Storage::response() honor the
     *  browser's Range header itself, same as any <audio> scrubber needs. */
    public function stream(CallRecording $recording)
    {
        if (!Storage::disk('call_recordings')->exists($recording->disk_path)) {
            abort(404);
        }

        return Storage::disk('call_recordings')->response($recording->disk_path);
    }
}
