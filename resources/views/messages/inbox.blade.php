@extends('layouts.app')
@section('title', 'Messages')
@section('subtitle', 'Direct messages')

{{-- Plain-page fallback for a direct /messages visit (no-JS, a bookmarked
     link, etc.) — the real day-to-day UX is the topbar panel
     (partials/messages-panel.blade.php, included on every page), which
     fetches this same data as JSON instead of a full page load. Kept
     simple: a conversation list only, no inline thread view here — opening
     one from this page still routes through the topbar panel via the
     script below, same behavior either way. --}}
@section('content')
<div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden max-w-xl mx-auto">
    @if($conversations->isEmpty())
    <div class="py-16 flex flex-col items-center justify-center gap-3 text-center px-6">
        <svg class="w-10 h-10 text-slate-200 dark:text-slate-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-6l-4 4v-4z"/>
        </svg>
        <p class="text-sm font-mono text-slate-400">No conversations yet.</p>
        <p class="text-xs font-mono text-slate-300 dark:text-slate-600">Use the message icon in the topbar to start one.</p>
    </div>
    @else
    <div class="divide-y divide-slate-100 dark:divide-slate-700">
        @foreach($conversations as $row)
        <button type="button" onclick="document.getElementById('messagesToggle')?.click()"
                class="w-full flex items-center gap-3 px-4 py-3 text-left hover:bg-slate-50 dark:hover:bg-slate-800 cursor-pointer">
            <span class="w-9 h-9 rounded-full bg-primary/10 text-primary-dark dark:text-yellow-400 flex items-center justify-center text-xs font-bold shrink-0">
                {{ strtoupper(substr($row['user']->name, 0, 1)) }}
            </span>
            <div class="flex-1 min-w-0">
                <div class="flex items-center justify-between gap-2">
                    <p class="text-sm font-semibold text-slate-700 dark:text-slate-200 truncate">{{ $row['user']->name }}</p>
                    <span class="text-[10px] text-slate-400 shrink-0">{{ $row['lastMessage']->created_at->format('M j, g:i A') }}</span>
                </div>
                <div class="flex items-center justify-between gap-2">
                    <p class="text-xs text-slate-400 truncate">{{ \Illuminate\Support\Str::limit($row['lastMessage']->body, 60) }}</p>
                    @if($row['unreadCount'] > 0)
                    <span class="bg-red-500 text-white text-[9px] font-bold rounded-full min-w-[16px] h-[16px] px-1 flex items-center justify-center shrink-0">{{ $row['unreadCount'] > 99 ? '99+' : $row['unreadCount'] }}</span>
                    @endif
                </div>
            </div>
        </button>
        @endforeach
    </div>
    @endif
</div>
@endsection
