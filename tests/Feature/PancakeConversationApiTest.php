<?php

namespace Tests\Feature;

use App\Models\PancakePageToken;
use App\Models\Setting;
use App\Support\PancakeConversationApi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Ported from call-tracker (merged into one app 2026-08-12) — unmodified (no Tsa/route references). */
class PancakeConversationApiTest extends TestCase
{
    use RefreshDatabase;

    private PancakeConversationApi $api;

    protected function setUp(): void
    {
        parent::setUp();
        $this->api = new PancakeConversationApi();
        Setting::set('pancake_access_token', 'fake-user-token');
    }

    public function test_get_page_access_token_generates_and_caches_one(): void
    {
        Http::fake([
            'pages.fm/api/v1/pages/*/generate_page_access_token*' => Http::response(['page_access_token' => 'generated-token'], 200),
        ]);

        $token = $this->api->getPageAccessToken('123');

        $this->assertSame('generated-token', $token);
        $this->assertDatabaseHas('pancake_page_tokens', ['page_id' => '123', 'page_access_token' => 'generated-token']);
    }

    public function test_a_second_call_uses_the_cached_token_and_never_calls_pancake_again(): void
    {
        PancakePageToken::create(['page_id' => '123', 'page_access_token' => 'already-cached']);
        Http::fake(); // no stubs — any real call fails the test

        $token = $this->api->getPageAccessToken('123');

        $this->assertSame('already-cached', $token);
        Http::assertNothingSent();
    }

    public function test_returns_null_when_no_personal_access_token_is_configured(): void
    {
        Setting::set('pancake_access_token', '');
        Http::fake();

        $this->assertNull($this->api->getPageAccessToken('123'));
        Http::assertNothingSent();
    }

    public function test_returns_null_when_generation_fails(): void
    {
        Http::fake([
            'pages.fm/api/v1/pages/*/generate_page_access_token*' => Http::response(['message' => 'invalid'], 401),
        ]);

        $this->assertNull($this->api->getPageAccessToken('123'));
        $this->assertDatabaseMissing('pancake_page_tokens', ['page_id' => '123']);
    }

    public function test_get_messages_returns_the_real_message_list(): void
    {
        PancakePageToken::create(['page_id' => '123', 'page_access_token' => 'cached-token']);
        Http::fake([
            'pages.fm/api/public_api/v1/pages/123/conversations/conv-1/messages*' => Http::response([
                'success' => true,
                'messages' => [
                    ['id' => 'm1', 'message' => 'Hello!', 'from' => ['name' => 'Marites Listor']],
                    ['id' => 'm2', 'message' => 'Hi, how can I help?', 'from' => ['name' => 'Gemma De Guzman', 'admin_id' => 'abc']],
                ],
            ], 200),
        ]);

        $result = $this->api->getMessages('123', 'conv-1');

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['messages']);
        $this->assertSame('Hello!', $result['messages'][0]['message']);
    }

    public function test_get_messages_fails_gracefully_without_a_configured_token(): void
    {
        Setting::set('pancake_access_token', '');

        $result = $this->api->getMessages('123', 'conv-1');

        $this->assertFalse($result['success']);
        $this->assertSame([], $result['messages']);
        $this->assertNotNull($result['error']);
    }

    public function test_get_messages_fails_gracefully_on_a_pancake_error(): void
    {
        PancakePageToken::create(['page_id' => '123', 'page_access_token' => 'cached-token']);
        Http::fake([
            'pages.fm/api/public_api/v1/pages/123/conversations/conv-1/messages*' => Http::response(['message' => 'not found'], 404),
        ]);

        $result = $this->api->getMessages('123', 'conv-1');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('404', $result['error']);
    }

    /**
     * Real-world case that used to render as an empty "No messages yet"
     * conversation: Pancake replies HTTP 200 with a JSON success:false body
     * (error_code 105, "access_token renewed please use new access_token")
     * for a cached page_access_token it has since rotated. This must be
     * retried with a freshly generated token, not treated as zero messages.
     */
    public function test_get_messages_retries_with_a_fresh_token_on_a_stale_token_response(): void
    {
        PancakePageToken::create(['page_id' => '123', 'page_access_token' => 'stale-token']);
        Http::fake([
            'pages.fm/api/public_api/v1/pages/123/conversations/conv-1/messages*' => Http::sequence()
                ->push(['success' => false, 'error_code' => 105, 'message' => 'access_token renewed please use new access_token'], 200)
                ->push(['success' => true, 'messages' => [['id' => 'm1', 'message' => 'Hello!']]], 200),
            'pages.fm/api/v1/pages/*/generate_page_access_token*' => Http::response(['page_access_token' => 'fresh-token'], 200),
        ]);

        $result = $this->api->getMessages('123', 'conv-1');

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['messages']);
        $this->assertDatabaseHas('pancake_page_tokens', ['page_id' => '123', 'page_access_token' => 'fresh-token']);
    }

    public function test_get_messages_surfaces_pancakes_own_message_when_retry_also_fails(): void
    {
        PancakePageToken::create(['page_id' => '123', 'page_access_token' => 'stale-token']);
        Http::fake([
            'pages.fm/api/public_api/v1/pages/123/conversations/conv-1/messages*' => Http::response(
                ['success' => false, 'error_code' => 105, 'message' => 'access_token renewed please use new access_token'],
                200
            ),
            'pages.fm/api/v1/pages/*/generate_page_access_token*' => Http::response(['page_access_token' => 'fresh-token'], 200),
        ]);

        $result = $this->api->getMessages('123', 'conv-1');

        $this->assertFalse($result['success']);
        $this->assertSame('access_token renewed please use new access_token', $result['error']);
    }
}
