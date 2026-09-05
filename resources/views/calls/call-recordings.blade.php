@extends('layouts.calls')
@section('title', 'Call Recordings')
@section('subtitle', 'Real recordings from each TSA\'s own Google Drive folder')

@push('topbar-right')
<select onchange="window.location.href=this.value"
        class="text-xs font-semibold font-mono border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-1.5 bg-white dark:bg-slate-900 text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-yellow-500">
    <option value="{{ route('calls.call-recordings', ['tsa' => '', 'date_from' => $dateFrom, 'date_to' => $dateTo]) }}" @selected(!$selectedTsa)>All TSAs</option>
    @foreach($tsas as $tsa)
    <option value="{{ route('calls.call-recordings', ['tsa' => $tsa->id, 'date_from' => $dateFrom, 'date_to' => $dateTo]) }}" @selected($selectedTsa === $tsa->id)>{{ $tsa->display_name }}</option>
    @endforeach
</select>

@include('partials.date-picker', [
    'mode' => 'range', 'id' => 'callRecordingsDrp',
    'dateFrom' => \Illuminate\Support\Carbon::parse($dateFrom), 'dateTo' => \Illuminate\Support\Carbon::parse($dateTo),
    'submit' => 'navigate', 'navigateBase' => route('calls.call-recordings'),
])
@endpush

@section('content')

<div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden">
    @if(!$driveConnected)
    <div class="py-12 flex flex-col items-center justify-center gap-2">
        <svg class="w-9 h-9 text-amber-400" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
        </svg>
        <p class="text-sm font-mono text-slate-400">Google Drive isn't connected yet — set it up in Settings.</p>
    </div>
    @elseif($needsTsa)
    <div class="py-12 flex flex-col items-center justify-center gap-2">
        <svg class="w-9 h-9 text-slate-200 dark:text-slate-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/>
        </svg>
        <p class="text-sm font-mono text-slate-400">Pick a TSA above to load their recordings.</p>
        <p class="text-xs font-mono text-slate-300 dark:text-slate-600">Searching every TSA's Drive folder at once is slow, so this loads one at a time.</p>
    </div>
    @elseif($recordings->isEmpty())
    <div class="py-12 flex flex-col items-center justify-center gap-2">
        <svg class="w-9 h-9 text-slate-200 dark:text-slate-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 006-6v-1.5m-6 7.5a6 6 0 01-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 01-3-3V4.5a3 3 0 116 0v8.25a3 3 0 01-3 3z"/>
        </svg>
        <p class="text-sm font-mono text-slate-400">No recordings for this range yet.</p>
    </div>
    @else
    <div class="overflow-x-auto">
    <table class="w-full text-sm font-mono">
        <thead class="bg-slate-100 dark:bg-slate-700 border-b border-slate-200 dark:border-slate-700">
            <tr>
                <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">When</th>
                <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">TSA</th>
                <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">File</th>
                <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">Play</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
            @foreach($recordings as $recording)
            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800">
                <td class="px-4 py-3 text-slate-500 dark:text-slate-400">{{ $recording['label'] }}</td>
                <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $recording['tsa']->display_name }}</td>
                <td class="px-4 py-3 text-slate-500 dark:text-slate-400">{{ $recording['name'] }}</td>
                <td class="px-4 py-3">
                    @php $streamUrl = route('calls.call-recordings.stream', ['tsa' => $recording['tsa']->id, 'fileId' => $recording['id'], 'month' => $recording['month']]); @endphp
                    <div class="recording-player flex items-center gap-2 w-[300px] max-w-full bg-slate-100 dark:bg-slate-800 rounded-full pl-1 pr-3 py-1">
                        <audio preload="none" src="{{ $streamUrl }}"></audio>

                        <button type="button" class="play-toggle shrink-0 w-7 h-7 rounded-full bg-yellow-500 hover:bg-yellow-400 text-slate-900 flex items-center justify-center transition-colors">
                            <svg class="icon-play w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                            <svg class="icon-pause w-3.5 h-3.5 hidden" fill="currentColor" viewBox="0 0 24 24"><path d="M6 5h4v14H6zm8 0h4v14h-4z"/></svg>
                        </button>

                        <span class="current-time shrink-0 text-[11px] tabular-nums text-slate-500 dark:text-slate-400 w-9">0:00</span>

                        <input type="range" class="seek-bar grow h-1.5 rounded-full appearance-none bg-slate-300 dark:bg-slate-600 accent-yellow-500 cursor-pointer" min="0" max="0" step="0.01" value="0" />

                        <span class="total-time shrink-0 text-[11px] tabular-nums text-slate-400 dark:text-slate-500 w-9">0:00</span>

                        <div class="volume-group shrink-0 flex items-center gap-1.5 group/vol">
                            <input type="range" class="volume-bar w-0 group-hover/vol:w-12 focus:w-12 opacity-0 group-hover/vol:opacity-100 focus:opacity-100 transition-all duration-150 h-1.5 rounded-full appearance-none bg-slate-300 dark:bg-slate-600 accent-yellow-500 cursor-pointer" min="0" max="1" step="0.01" value="1" />
                            <button type="button" class="mute-toggle shrink-0 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
                                <svg class="icon-unmuted w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.114 5.636a9 9 0 010 12.728M16.463 8.288a5.25 5.25 0 010 7.424M6.75 8.25l4.72-4.72a.75.75 0 011.28.53v15.88a.75.75 0 01-1.28.53l-4.72-4.72H4.51c-.88 0-1.6-.72-1.6-1.6v-4.3c0-.88.72-1.6 1.6-1.6h2.24z"/></svg>
                                <svg class="icon-muted w-4 h-4 hidden" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17.25 9.75L19.5 12m0 0l2.25 2.25M19.5 12l2.25-2.25M19.5 12l-2.25 2.25M6.75 8.25l4.72-4.72a.75.75 0 011.28.53v15.88a.75.75 0 01-1.28.53l-4.72-4.72H4.51c-.88 0-1.6-.72-1.6-1.6v-4.3c0-.88.72-1.6 1.6-1.6h2.24z"/></svg>
                            </button>
                        </div>
                    </div>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    </div>
    @endif
</div>

@push('scripts')
<script>
(function () {
    function formatTime(t) {
        if (!t || !isFinite(t)) return '0:00';
        var m = Math.floor(t / 60), s = Math.floor(t % 60);
        return m + ':' + String(s).padStart(2, '0');
    }

    document.querySelectorAll('.recording-player').forEach(function (player) {
        var audio      = player.querySelector('audio');
        var playToggle = player.querySelector('.play-toggle');
        var iconPlay   = player.querySelector('.icon-play');
        var iconPause  = player.querySelector('.icon-pause');
        var seekBar    = player.querySelector('.seek-bar');
        var currentEl  = player.querySelector('.current-time');
        var totalEl    = player.querySelector('.total-time');
        var muteToggle = player.querySelector('.mute-toggle');
        var iconUnmuted = player.querySelector('.icon-unmuted');
        var iconMuted    = player.querySelector('.icon-muted');
        var volumeBar    = player.querySelector('.volume-bar');
        var isSeeking = false;

        function refreshVolumeIcon() {
            var isMuted = audio.muted || audio.volume === 0;
            iconUnmuted.classList.toggle('hidden', isMuted);
            iconMuted.classList.toggle('hidden', !isMuted);
        }

        playToggle.addEventListener('click', function () {
            if (audio.paused) {
                document.querySelectorAll('.recording-player audio').forEach(function (other) {
                    if (other !== audio) other.pause();
                });
                audio.play();
            } else {
                audio.pause();
            }
        });

        audio.addEventListener('play', function () {
            iconPlay.classList.add('hidden');
            iconPause.classList.remove('hidden');
        });
        audio.addEventListener('pause', function () {
            iconPlay.classList.remove('hidden');
            iconPause.classList.add('hidden');
        });
        audio.addEventListener('ended', function () {
            iconPlay.classList.remove('hidden');
            iconPause.classList.add('hidden');
        });

        audio.addEventListener('loadedmetadata', function () {
            seekBar.max = audio.duration || 0;
            totalEl.textContent = formatTime(audio.duration);
        });
        audio.addEventListener('timeupdate', function () {
            if (isSeeking) return;
            seekBar.value = audio.currentTime;
            currentEl.textContent = formatTime(audio.currentTime);
        });

        seekBar.addEventListener('input', function () {
            isSeeking = true;
            currentEl.textContent = formatTime(Number(seekBar.value));
        });
        seekBar.addEventListener('change', function () {
            audio.currentTime = Number(seekBar.value);
            isSeeking = false;
        });

        muteToggle.addEventListener('click', function () {
            audio.muted = !audio.muted;
            refreshVolumeIcon();
        });

        volumeBar.addEventListener('input', function () {
            audio.volume = Number(volumeBar.value);
            audio.muted = audio.volume === 0;
            refreshVolumeIcon();
        });
    });
})();
</script>
@endpush

@endsection
