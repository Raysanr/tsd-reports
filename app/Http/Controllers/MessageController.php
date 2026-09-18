<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Direct messaging between User accounts (explicit request, 2026-09-18:
 * "is it possible that can have a feature that can message the tsa").
 * Confirmed scope: two-way (any user can message any other user, either
 * side can start a NEW conversation, not just reply), TSD Reports side —
 * tied to User accounts, not Call Tracker's separate TsaShift roster —
 * polling-based (same "no page reload needed" convention
 * NotificationController's own sidebar badges already use), topbar icon
 * entry point visible to every signed-in user regardless of role.
 *
 * Not role-gated anywhere in this controller — see routes/web.php's own
 * placement (the top-level ['auth','active','last-seen'] group, same as
 * /search, not the role:super_admin,admin one below it).
 */
class MessageController extends Controller
{
    /** Conversation list (every distinct user $me has exchanged at least
     *  one message with), newest message first — the topbar panel's own
     *  list view fetches this as JSON; a plain browser visit to /messages
     *  (no JS, or a direct link) gets the same data server-rendered as a
     *  real page instead. */
    public function inbox(Request $request)
    {
        $me = Auth::user();

        $conversations = $this->conversationList($me);

        if ($request->wantsJson()) {
            return response()->json([
                'success'       => true,
                'conversations' => $conversations->map(fn ($row) => [
                    'id'          => $row['user']->id,
                    'name'        => $row['user']->name,
                    'preview'     => \Illuminate\Support\Str::limit($row['lastMessage']->body, 60),
                    'label'       => $row['lastMessage']->created_at->format('M j, g:i A'),
                    'unreadCount' => $row['unreadCount'],
                ]),
            ]);
        }

        return view('messages.inbox', [
            'conversations' => $conversations,
        ]);
    }

    /** Distinct list of {user, lastMessage, unreadCount}, one row per
     *  person $me has ever exchanged a message with, most-recent-message
     *  first. Built from Message rows directly rather than a separate
     *  Conversation table — see the create_messages_table migration's own
     *  doc comment for why. */
    private function conversationList(User $me): \Illuminate\Support\Collection
    {
        $messages = Message::involving($me->id)
            ->with(['sender', 'recipient'])
            ->orderByDesc('created_at')
            ->get();

        $byPartner = $messages->groupBy(fn (Message $m) => $m->sender_id === $me->id ? $m->recipient_id : $m->sender_id);

        return $byPartner->map(function ($group) use ($me) {
            $last = $group->first(); // already newest-first from the query above
            $partner = $last->sender_id === $me->id ? $last->recipient : $last->sender;

            return [
                'user'        => $partner,
                'lastMessage' => $last,
                'unreadCount' => $group->where('recipient_id', $me->id)->whereNull('read_at')->count(),
            ];
        })
            ->filter(fn ($row) => $row['user'] !== null) // partner account deleted since
            ->sortByDesc(fn ($row) => $row['lastMessage']->created_at)
            ->values();
    }

    /** Total unread count across every conversation — polled every ~20s by
     *  the topbar badge (calls.js/app.js), same cadence
     *  pollNotificationCounts() already uses for Overdue/Callbacks. */
    public function unreadCount()
    {
        $count = Message::where('recipient_id', Auth::id())->whereNull('read_at')->count();

        return response()->json(['count' => $count]);
    }

    /** Real-name/email search for the "start a new conversation" picker —
     *  every OTHER active user is a valid target (not just TSAs, not just
     *  admins — confirmed scope: anyone can message anyone). Excludes the
     *  viewer themselves and deactivated accounts (nothing useful comes of
     *  messaging a login that can no longer sign in). */
    public function searchUsers(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $me = Auth::user();

        $users = User::where('id', '!=', $me->id)
            ->where('is_active', true)
            ->when($q !== '', fn ($query) => $query->where(fn ($q2) => $q2
                ->where('name', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%")))
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'email']);

        return response()->json(['users' => $users]);
    }

    /** One conversation's own messages with $user, oldest-first (a normal
     *  chat reading order — same convention the Pancake conversation modal
     *  in Call Tracker already settled on, see calls.js's own
     *  openConversationModal() history). Marks every unread message FROM
     *  $user TO the viewer as read the moment this thread is opened — the
     *  same "opening it is acknowledging it" convention a real chat/inbox
     *  uses, not a separate explicit dismiss action. */
    public function thread(Request $request, User $user)
    {
        $me = Auth::user();

        $messages = Message::involving($me->id, $user->id)
            ->orderBy('created_at')
            ->get();

        // Marks read AFTER $messages is already loaded — the in-memory
        // collection below is updated in the SAME loop that flags which
        // ones just got newly marked, so the seenAt this exact response
        // returns reflects "now", not the stale pre-update null a fresh
        // reload would otherwise show one poll cycle later.
        $now = now();
        $justRead = Message::where('sender_id', $user->id)
            ->where('recipient_id', $me->id)
            ->whereNull('read_at')
            ->pluck('id');
        if ($justRead->isNotEmpty()) {
            Message::whereIn('id', $justRead)->update(['read_at' => $now]);
            $messages->whereIn('id', $justRead)->each(fn (Message $m) => $m->read_at = $now);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'success'  => true,
                'messages' => $messages->map(fn (Message $m) => [
                    'id'        => $m->id,
                    'body'      => $m->body,
                    'fromMe'    => $m->sender_id === $me->id,
                    'createdAt' => $m->created_at->toIso8601String(),
                    'label'     => $m->created_at->format('M j, g:i A'),
                    // Only meaningful for a message the VIEWER sent (fromMe
                    // true) — the read_at on a message they RECEIVED is
                    // about their own read state, already handled above,
                    // not something the thread UI shows for those.
                    'seenAt' => $m->sender_id === $me->id && $m->read_at
                        ? $m->read_at->format('M j, g:i A')
                        : null,
                ]),
                'partner' => ['id' => $user->id, 'name' => $user->name],
            ]);
        }

        return view('messages.thread', [
            'partner'  => $user,
            'messages' => $messages,
        ]);
    }

    /** Sends one message to $user. No block/mute/permission check beyond
     *  "must be a real, active account" — confirmed scope: any signed-in
     *  user can message any other, this is small internal team tooling,
     *  not a public-facing product needing abuse controls. */
    public function send(Request $request, User $user)
    {
        $me = Auth::user();

        if ($user->id === $me->id) {
            abort(422, 'Cannot message yourself.');
        }

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $message = Message::create([
            'sender_id'    => $me->id,
            'recipient_id' => $user->id,
            'body'         => trim($data['body']),
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => [
                    'id'        => $message->id,
                    'body'      => $message->body,
                    'fromMe'    => true,
                    'createdAt' => $message->created_at->toIso8601String(),
                    'label'     => $message->created_at->format('M j, g:i A'),
                ],
            ]);
        }

        return back();
    }

    /** Explicit mark-as-read, for a poller that wants to clear the badge
     *  without opening the full thread view (thread() above already marks
     *  read as a side effect of actually opening it — this covers the
     *  "seen the preview in the inbox list" case separately). */
    public function markRead(User $user)
    {
        Message::where('sender_id', $user->id)
            ->where('recipient_id', Auth::id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['success' => true]);
    }
}
