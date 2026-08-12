<?php

namespace App\Support;

use App\Models\PancakePageToken;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Ported from call-tracker (merged into one app 2026-08-12), unmodified.
 *
 * Talks to Pancake's REAL conversations/messages API — confirmed against
 * the actual OpenAPI spec published at developer.pancake.biz. This is a
 * completely different host/auth scheme from the pos.pages.fm orders API
 * used elsewhere in this app:
 *
 *   - Base URL: https://pages.fm/api/public_api/v1 (NOT pos.pages.fm)
 *   - Auth: a page_access_token query param (NOT api_key) — obtained by
 *     exchanging the personal pancake_access_token (a "UserAccessToken",
 *     valid ~90 days) via POST /pages/{page_id}/generate_page_access_token.
 *     The resulting page_access_token does not expire, so it's cached in
 *     pancake_page_tokens rather than re-generated on every call.
 *
 * Message HISTORY is genuinely only available here — Pancake's POS orders
 * API has no concept of a chat thread. TAGS are a different story: this
 * class used to also read/write a page's Messenger-conversation-scoped tag
 * catalog, but that turned out to be the WRONG resource for anything meant
 * to be visible in Pancake POS's own "Add tag" UI or to reporting's own
 * sync — both of those read the real POS ORDER tag catalog instead (see
 * PancakeOrderTagApi). Confirmed live: a real page's conversation tag
 * catalog and its POS order tag catalog share none of the same tag names.
 */
class PancakeConversationApi
{
    private const USER_TOKEN_BASE = 'https://pages.fm/api/v1';
    private const PAGE_API_BASE   = 'https://pages.fm/api/public_api/v1';

    /**
     * Returns a working page_access_token for $pageId, generating and
     * caching one from the personal access token if none is cached yet.
     * Null if there's no personal token configured or generation fails —
     * every caller below treats that as "feature unavailable", not fatal.
     */
    public function getPageAccessToken(string $pageId): ?string
    {
        $cached = PancakePageToken::where('page_id', $pageId)->first();
        if ($cached) {
            return $cached->page_access_token;
        }

        $userToken = Setting::get('pancake_access_token', '');
        if (empty($userToken)) {
            return null;
        }

        try {
            // Per spec: page_id and access_token are BOTH query parameters
            // here, not a JSON body (this endpoint has no requestBody at
            // all) — confirmed live: sending them as a body made Pancake
            // reply 200 with {"success":false,"message":"Invalid access_token"}
            // even with a genuinely valid token, because it never saw either
            // param at all.
            $response = Http::timeout(10)
                ->withOptions(['query' => ['page_id' => $pageId, 'access_token' => $userToken]])
                ->post(self::USER_TOKEN_BASE . "/pages/{$pageId}/generate_page_access_token");

            if (!$response->successful()) {
                Log::warning('PancakeConversationApi: generate_page_access_token failed', [
                    'page_id' => $pageId, 'status' => $response->status(), 'body' => $response->body(),
                ]);
                return null;
            }

            $body = $response->json();
            // Response shape isn't documented beyond "200: Token generated
            // successfully" — check the field names actually seen in
            // practice first, then fall back defensively.
            $token = $body['page_access_token']
                ?? $body['data']['page_access_token']
                ?? $body['access_token']
                ?? null;

            if (!$token) {
                Log::warning('PancakeConversationApi: no token field found in generate_page_access_token response', ['page_id' => $pageId, 'body' => $body]);
                return null;
            }

            PancakePageToken::updateOrCreate(['page_id' => $pageId], ['page_access_token' => $token]);

            return $token;
        } catch (\Throwable $e) {
            Log::warning('PancakeConversationApi: generate_page_access_token threw', ['page_id' => $pageId, 'message' => $e->getMessage()]);
            return null;
        }
    }

    /** Forces a fresh page_access_token even if one is cached — for when
     *  the cached one has been manually revoked/rotated in Pancake. */
    public function refreshPageAccessToken(string $pageId): ?string
    {
        PancakePageToken::where('page_id', $pageId)->delete();
        return $this->getPageAccessToken($pageId);
    }

    /**
     * Returns ['success' => bool, 'messages' => [...], 'error' => ?string].
     * $messages is Pancake's raw message objects (newest first) — the
     * caller/view decides how to render them.
     */
    public function getMessages(string $pageId, string $conversationId): array
    {
        $token = $this->getPageAccessToken($pageId);
        if (!$token) {
            return ['success' => false, 'messages' => [], 'error' => 'No Pancake access token configured — add one in Settings.'];
        }

        try {
            $response = Http::timeout(15)->get(self::PAGE_API_BASE . "/pages/{$pageId}/conversations/{$conversationId}/messages", [
                'page_access_token' => $token,
            ]);

            if (!$response->successful()) {
                Log::warning('PancakeConversationApi: getMessages failed', ['page_id' => $pageId, 'conversation_id' => $conversationId, 'status' => $response->status()]);
                return ['success' => false, 'messages' => [], 'error' => "Pancake returned HTTP {$response->status()}."];
            }

            $body = $response->json();

            return ['success' => true, 'messages' => $body['messages'] ?? [], 'error' => null];
        } catch (\Throwable $e) {
            Log::warning('PancakeConversationApi: getMessages threw', ['page_id' => $pageId, 'message' => $e->getMessage()]);
            return ['success' => false, 'messages' => [], 'error' => $e->getMessage()];
        }
    }
}
