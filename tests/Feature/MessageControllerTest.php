<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Direct messaging between User accounts (explicit request, 2026-09-18:
 * "is it possible that can have a feature that can message the tsa") — see
 * MessageController's own doc comment for the full confirmed scope: two-way,
 * any user can message any other, not role-gated.
 */
class MessageControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_normal_user_can_send_a_message_to_another_user(): void
    {
        $sender    = User::factory()->normal()->create();
        $recipient = User::factory()->normal()->create();

        $response = $this->actingAs($sender)
            ->postJson(route('messages.send', $recipient), ['body' => 'Please call this lead back.']);

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $this->assertDatabaseHas('messages', [
            'sender_id'    => $sender->id,
            'recipient_id' => $recipient->id,
            'body'         => 'Please call this lead back.',
        ]);
    }

    /** Confirmed scope: EITHER side can start a new conversation, not just
     *  admin -> TSA — a normal user can message an admin too. */
    public function test_a_normal_user_can_message_an_admin(): void
    {
        $normal = User::factory()->normal()->create();
        $admin  = User::factory()->admin()->create();

        $response = $this->actingAs($normal)
            ->postJson(route('messages.send', $admin), ['body' => 'Question about a lead.']);

        $response->assertOk();
        $this->assertDatabaseHas('messages', ['sender_id' => $normal->id, 'recipient_id' => $admin->id]);
    }

    public function test_cannot_message_yourself(): void
    {
        $user = User::factory()->normal()->create();

        $this->actingAs($user)
            ->postJson(route('messages.send', $user), ['body' => 'hi'])
            ->assertStatus(422);
    }

    public function test_a_blank_message_is_rejected(): void
    {
        $sender    = User::factory()->normal()->create();
        $recipient = User::factory()->normal()->create();

        $this->actingAs($sender)
            ->postJson(route('messages.send', $recipient), ['body' => ''])
            ->assertStatus(422);
    }

    public function test_a_thread_shows_messages_from_both_directions_oldest_first(): void
    {
        $a = User::factory()->normal()->create();
        $b = User::factory()->normal()->create();

        // created_at set via forceFill()->save() AFTER create() —
        // created_at/updated_at are deliberately NOT in Message's own
        // $fillable (see that model's own doc comment), so a plain
        // create(['created_at' => ...]) or update(['created_at' => ...])
        // silently drops it via mass-assignment protection, then
        // Eloquent's own auto-timestamp behavior stamps now() regardless
        // — forceFill() bypasses that protection for this test's own
        // deterministic ordering setup.
        $m1 = Message::create(['sender_id' => $a->id, 'recipient_id' => $b->id, 'body' => 'First']);
        $m1->forceFill(['created_at' => now()->subMinutes(5)])->save();
        $m2 = Message::create(['sender_id' => $b->id, 'recipient_id' => $a->id, 'body' => 'Second']);
        $m2->forceFill(['created_at' => now()->subMinutes(3)])->save();
        $m3 = Message::create(['sender_id' => $a->id, 'recipient_id' => $b->id, 'body' => 'Third']);
        $m3->forceFill(['created_at' => now()])->save();

        $response = $this->actingAs($a)->getJson(route('messages.thread', $b));

        $response->assertOk();
        $bodies = collect($response->json('messages'))->pluck('body')->all();
        $this->assertSame(['First', 'Second', 'Third'], $bodies);
    }

    /** A thread must never leak a THIRD person's messages just because
     *  they also happen to be in the querying user's inbox. */
    public function test_a_thread_only_shows_messages_between_the_two_people_involved(): void
    {
        $a = User::factory()->normal()->create();
        $b = User::factory()->normal()->create();
        $c = User::factory()->normal()->create();

        Message::create(['sender_id' => $a->id, 'recipient_id' => $b->id, 'body' => 'To B']);
        Message::create(['sender_id' => $a->id, 'recipient_id' => $c->id, 'body' => 'To C']);

        $response = $this->actingAs($a)->getJson(route('messages.thread', $b));

        $response->assertOk();
        $bodies = collect($response->json('messages'))->pluck('body')->all();
        $this->assertSame(['To B'], $bodies);
    }

    public function test_opening_a_thread_marks_unread_messages_as_read(): void
    {
        $sender    = User::factory()->normal()->create();
        $recipient = User::factory()->normal()->create();

        $message = Message::create(['sender_id' => $sender->id, 'recipient_id' => $recipient->id, 'body' => 'Hello']);
        $this->assertNull($message->fresh()->read_at);

        $this->actingAs($recipient)->getJson(route('messages.thread', $sender))->assertOk();

        $this->assertNotNull($message->fresh()->read_at);
    }

    /** Opening the thread must not mark the VIEWER's own sent messages as
     *  read — only messages FROM the other person TO the viewer. */
    public function test_opening_a_thread_does_not_mark_the_viewers_own_sent_messages_as_read(): void
    {
        $a = User::factory()->normal()->create();
        $b = User::factory()->normal()->create();

        $mine = Message::create(['sender_id' => $a->id, 'recipient_id' => $b->id, 'body' => 'From me']);

        $this->actingAs($a)->getJson(route('messages.thread', $b))->assertOk();

        $this->assertNull($mine->fresh()->read_at);
    }

    /**
     * "Seen" indicator (explicit request, 2026-09-18: "how can i identify
     * if the message has seen?") — a message the VIEWER sent shows
     * seenAt once the recipient has opened the thread; null before that.
     * A message the viewer RECEIVED never carries a seenAt at all — that
     * field is only meaningful for the viewer's own sent messages (see
     * MessageController::thread()'s own comment).
     */
    public function test_a_sent_message_shows_seen_at_once_the_recipient_opens_the_thread(): void
    {
        $sender    = User::factory()->normal()->create();
        $recipient = User::factory()->normal()->create();

        Message::create(['sender_id' => $sender->id, 'recipient_id' => $recipient->id, 'body' => 'Please call back.']);

        // Before the recipient ever opens it — not seen yet.
        $before = $this->actingAs($sender)->getJson(route('messages.thread', $recipient));
        $before->assertOk();
        $this->assertNull($before->json('messages.0.seenAt'));

        // Recipient opens the thread — this is the read/seen event.
        $this->actingAs($recipient)->getJson(route('messages.thread', $sender))->assertOk();

        // Sender checks again — now shows as seen, reflecting the read
        // immediately (not a stale null from before that same request's
        // own read_at update, see thread()'s own doc comment).
        $after = $this->actingAs($sender)->getJson(route('messages.thread', $recipient));
        $after->assertOk();
        $this->assertNotNull($after->json('messages.0.seenAt'));
    }

    /** seenAt is never set on a message the VIEWER received — that field
     *  is scoped to the viewer's own sent messages only, the received
     *  side's read state has nothing to show a "seen" label for in the
     *  same sense. */
    public function test_seen_at_is_null_for_a_message_the_viewer_received(): void
    {
        $sender    = User::factory()->normal()->create();
        $recipient = User::factory()->normal()->create();

        Message::create(['sender_id' => $sender->id, 'recipient_id' => $recipient->id, 'body' => 'Hi']);

        // Recipient views their own thread — this message is one THEY
        // received, not one they sent, so it should never carry seenAt
        // even though it's now genuinely read (read_at IS set).
        $response = $this->actingAs($recipient)->getJson(route('messages.thread', $sender));

        $response->assertOk();
        $this->assertNull($response->json('messages.0.seenAt'));
    }

    public function test_unread_count_reflects_only_the_viewers_own_unread_messages(): void
    {
        $a = User::factory()->normal()->create();
        $b = User::factory()->normal()->create();
        $c = User::factory()->normal()->create();

        Message::create(['sender_id' => $b->id, 'recipient_id' => $a->id, 'body' => '1']);
        Message::create(['sender_id' => $c->id, 'recipient_id' => $a->id, 'body' => '2']);
        // Unrelated — b to c, must not count toward a's unread total.
        Message::create(['sender_id' => $b->id, 'recipient_id' => $c->id, 'body' => '3']);

        $response = $this->actingAs($a)->getJson(route('messages.unread-count'));

        $response->assertOk();
        $response->assertJson(['count' => 2]);
    }

    public function test_inbox_lists_one_row_per_conversation_partner_with_the_last_message_and_unread_count(): void
    {
        $me    = User::factory()->normal()->create();
        $alice = User::factory()->normal()->create(['name' => 'Alice']);
        $bob   = User::factory()->normal()->create(['name' => 'Bob']);

        // created_at set via update() after create() — see the thread-order
        // test's own comment for why create() itself can't be trusted to
        // persist an explicit created_at.
        $old = Message::create(['sender_id' => $alice->id, 'recipient_id' => $me->id, 'body' => 'Old']);
        $old->forceFill(['created_at' => now()->subHour()])->save();
        $newest = Message::create(['sender_id' => $alice->id, 'recipient_id' => $me->id, 'body' => 'Newest from Alice']);
        $newest->forceFill(['created_at' => now()])->save();
        $toBob = Message::create(['sender_id' => $me->id, 'recipient_id' => $bob->id, 'body' => 'Hi Bob']);
        $toBob->forceFill(['created_at' => now()->subMinutes(30)])->save();

        $response = $this->actingAs($me)->getJson(route('messages.inbox'));

        $response->assertOk();
        $conversations = collect($response->json('conversations'));
        $this->assertCount(2, $conversations);

        $aliceRow = $conversations->firstWhere('name', 'Alice');
        $this->assertSame('Newest from Alice', $aliceRow['preview']);
        $this->assertSame(2, $aliceRow['unreadCount']); // both Alice messages unread

        $bobRow = $conversations->firstWhere('name', 'Bob');
        $this->assertSame(0, $bobRow['unreadCount']); // sent by me, not unread FOR me
    }

    public function test_search_users_excludes_self_and_inactive_accounts(): void
    {
        $me       = User::factory()->normal()->create(['name' => 'Me Myself']);
        $inactive = User::factory()->normal()->create(['name' => 'Gone Away', 'is_active' => false]);
        $active   = User::factory()->normal()->create(['name' => 'Findable Person']);

        $response = $this->actingAs($me)->getJson(route('messages.users'));

        $response->assertOk();
        $names = collect($response->json('users'))->pluck('name')->all();
        $this->assertNotContains('Me Myself', $names);
        $this->assertNotContains('Gone Away', $names);
        $this->assertContains('Findable Person', $names);
    }

    public function test_search_users_filters_by_query(): void
    {
        $me = User::factory()->normal()->create();
        User::factory()->normal()->create(['name' => 'Marisol Lagarde']);
        User::factory()->normal()->create(['name' => 'Hannah Ascano']);

        $response = $this->actingAs($me)->getJson(route('messages.users', ['q' => 'Marisol']));

        $response->assertOk();
        $names = collect($response->json('users'))->pluck('name')->all();
        $this->assertSame(['Marisol Lagarde'], $names);
    }

    /**
     * Image attachments (explicit request, 2026-09-18: "is it possible that
     * can send a picture too?"). Stored as base64 in the row itself, not on
     * disk — see the add_image_to_messages_table migration's own doc
     * comment for why (no persistent volume on the web service).
     */
    public function test_a_message_can_be_sent_with_an_image_and_no_body_text(): void
    {
        $sender    = User::factory()->normal()->create();
        $recipient = User::factory()->normal()->create();
        $image     = UploadedFile::fake()->image('lead-screenshot.png', 100, 100);

        $response = $this->actingAs($sender)
            ->post(route('messages.send', $recipient), ['image' => $image], ['Accept' => 'application/json']);

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $this->assertNotNull($response->json('message.image'));
        $this->assertStringStartsWith('data:image/', $response->json('message.image'));
        $this->assertNull($response->json('message.body'));

        $stored = Message::first();
        $this->assertNotNull($stored->image);
        $this->assertNotNull($stored->image_mime);
    }

    public function test_a_message_can_include_both_body_text_and_an_image(): void
    {
        $sender    = User::factory()->normal()->create();
        $recipient = User::factory()->normal()->create();
        $image     = UploadedFile::fake()->image('proof.jpg');

        $response = $this->actingAs($sender)->post(
            route('messages.send', $recipient),
            ['body' => 'See attached.', 'image' => $image],
            ['Accept' => 'application/json'],
        );

        $response->assertOk();
        $this->assertSame('See attached.', $response->json('message.body'));
        $this->assertNotNull($response->json('message.image'));
    }

    public function test_a_message_with_neither_body_nor_image_is_rejected(): void
    {
        $sender    = User::factory()->normal()->create();
        $recipient = User::factory()->normal()->create();

        $this->actingAs($sender)
            ->postJson(route('messages.send', $recipient), ['body' => ''])
            ->assertStatus(422);
    }

    public function test_a_non_image_file_is_rejected(): void
    {
        $sender    = User::factory()->normal()->create();
        $recipient = User::factory()->normal()->create();
        $file      = UploadedFile::fake()->create('malware.exe', 100);

        $this->actingAs($sender)
            ->post(route('messages.send', $recipient), ['image' => $file], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_an_oversized_image_is_rejected(): void
    {
        $sender    = User::factory()->normal()->create();
        $recipient = User::factory()->normal()->create();
        // 3072 KB is the cap (max:3072 in the validator) — 4000 KB exceeds it.
        $image = UploadedFile::fake()->image('huge.jpg')->size(4000);

        $this->actingAs($sender)
            ->post(route('messages.send', $recipient), ['image' => $image], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_thread_includes_the_image_data_uri_for_an_image_message(): void
    {
        $sender    = User::factory()->normal()->create();
        $recipient = User::factory()->normal()->create();

        Message::create([
            'sender_id'    => $sender->id,
            'recipient_id' => $recipient->id,
            'image'        => base64_encode('fake-bytes'),
            'image_mime'   => 'image/png',
        ]);

        $response = $this->actingAs($recipient)->getJson(route('messages.thread', $sender));

        $response->assertOk();
        $this->assertStringStartsWith('data:image/png;base64,', $response->json('messages.0.image'));
    }

    /** Inbox preview falls back to a placeholder label for an image-only
     *  last message, since there's no body text to Str::limit(). */
    public function test_inbox_preview_shows_a_placeholder_for_an_image_only_last_message(): void
    {
        $me     = User::factory()->normal()->create();
        $sender = User::factory()->normal()->create(['name' => 'Marisol']);

        Message::create([
            'sender_id'    => $sender->id,
            'recipient_id' => $me->id,
            'image'        => base64_encode('fake-bytes'),
            'image_mime'   => 'image/png',
        ]);

        $response = $this->actingAs($me)->getJson(route('messages.inbox'));

        $response->assertOk();
        $row = collect($response->json('conversations'))->firstWhere('name', 'Marisol');
        $this->assertSame('📷 Photo', $row['preview']);
    }

    /**
     * Explicit request, 2026-09-20: "can you add delete in messages" —
     * confirmed scope: single-message delete (not a whole-thread delete),
     * removed for BOTH sides, sender-only. A hard delete — the row is
     * genuinely gone, not a tombstone still shown as "deleted" to the
     * other person.
     */
    public function test_the_sender_can_delete_their_own_message(): void
    {
        $sender    = User::factory()->normal()->create();
        $recipient = User::factory()->normal()->create();
        $message   = Message::create(['sender_id' => $sender->id, 'recipient_id' => $recipient->id, 'body' => 'oops']);

        $response = $this->actingAs($sender)->deleteJson(route('messages.destroy', $message));

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $this->assertDatabaseMissing('messages', ['id' => $message->id]);
    }

    /** A recipient must never be able to erase something someone else
     *  sent them — matches every real chat app's own convention. */
    public function test_the_recipient_cannot_delete_a_message_sent_to_them(): void
    {
        $sender    = User::factory()->normal()->create();
        $recipient = User::factory()->normal()->create();
        $message   = Message::create(['sender_id' => $sender->id, 'recipient_id' => $recipient->id, 'body' => 'oops']);

        $this->actingAs($recipient)->deleteJson(route('messages.destroy', $message))->assertForbidden();
        $this->assertDatabaseHas('messages', ['id' => $message->id]);
    }

    /** A third party (not sender or recipient) has no business deleting
     *  a conversation they're not even part of. */
    public function test_an_unrelated_user_cannot_delete_someone_elses_message(): void
    {
        $sender     = User::factory()->normal()->create();
        $recipient  = User::factory()->normal()->create();
        $bystander  = User::factory()->normal()->create();
        $message    = Message::create(['sender_id' => $sender->id, 'recipient_id' => $recipient->id, 'body' => 'oops']);

        $this->actingAs($bystander)->deleteJson(route('messages.destroy', $message))->assertForbidden();
        $this->assertDatabaseHas('messages', ['id' => $message->id]);
    }

    /** "For both sides" means it's gone from the RECIPIENT's own view of
     *  the thread too, not just the sender's — confirmed via a fresh
     *  thread() fetch from the recipient's side after the sender deletes. */
    public function test_a_deleted_message_no_longer_appears_in_the_recipients_thread(): void
    {
        $sender    = User::factory()->normal()->create();
        $recipient = User::factory()->normal()->create();
        $message   = Message::create(['sender_id' => $sender->id, 'recipient_id' => $recipient->id, 'body' => 'oops']);
        Message::create(['sender_id' => $sender->id, 'recipient_id' => $recipient->id, 'body' => 'keep this one']);

        $this->actingAs($sender)->deleteJson(route('messages.destroy', $message))->assertOk();

        $response = $this->actingAs($recipient)->getJson(route('messages.thread', $sender));

        $response->assertOk();
        $bodies = collect($response->json('messages'))->pluck('body');
        $this->assertFalse($bodies->contains('oops'));
        $this->assertTrue($bodies->contains('keep this one'));
    }
}
