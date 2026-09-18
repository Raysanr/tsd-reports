@extends('layouts.app')
@section('title', 'Messages — ' . $partner->name)
@section('subtitle', 'Conversation with ' . $partner->name)

{{-- Plain-page fallback for a direct /messages/{user} link (no-JS, a
     bookmarked/shared link) — same "the real UX is the topbar panel"
     reasoning as inbox.blade.php. Auto-opens the panel straight into this
     specific thread via the inline script below rather than duplicating
     a second full chat UI here. --}}
@section('content')
<div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm p-10 max-w-xl mx-auto text-center">
    <p class="text-sm font-mono text-slate-400">Opening your conversation with {{ $partner->name }}…</p>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.dispatchEvent(new CustomEvent('messages:open-thread', { detail: { id: {{ $partner->id }}, name: @json($partner->name) } }));
});
</script>
@endsection
