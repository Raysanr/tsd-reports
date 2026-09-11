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
        $result = $this->callWithTokenRetry(
            $pageId,
            fn (string $token) => Http::timeout(15)->get(self::PAGE_API_BASE . "/pages/{$pageId}/conversations/{$conversationId}/messages", [
                'page_access_token' => $token,
            ]),
            'getMessages',
            ['page_id' => $pageId, 'conversation_id' => $conversationId]
        );

        return [
            'success'  => $result['success'],
            'messages' => $result['body']['messages'] ?? [],
            'error'    => $result['error'],
        ];
    }

    /**
     * Sends a plain-text reply into an existing inbox conversation (the
     * "reply_inbox" action of Pancake's send-message endpoint — the other
     * three actions it supports, reply_comment/private_replies/WhatsApp
     * templates, aren't something this app's TSAs need). Facebook's normal
     * 24-hour messaging window applies same as any Messenger business
     * reply — Pancake enforces this server-side and reports it back as a
     * plain error message here, not a distinct error code, so it's
     * surfaced to the TSA as-is rather than guessed at.
     *
     * Returns ['success' => bool, 'error' => ?string].
     */
    public function sendMessage(string $pageId, string $conversationId, string $message): array
    {
        $result = $this->callWithTokenRetry(
            $pageId,
            fn (string $token) => Http::timeout(15)->post(self::PAGE_API_BASE . "/pages/{$pageId}/conversations/{$conversationId}/messages", [
                'page_access_token' => $token,
                'action'            => 'reply_inbox',
                'message'           => $message,
            ]),
            'sendMessage',
            ['page_id' => $pageId, 'conversation_id' => $conversationId]
        );

        return ['success' => $result['success'], 'error' => $result['error']];
    }

    /**
     * Shared request/retry plumbing for both read and send: issues $makeRequest
     * with a page token, and on Pancake's documented stale-token signal
     * (HTTP 200 body {"success":false,"error_code":105,...} for a cached
     * page_access_token it has since rotated server-side — confirmed live,
     * response()->successful() alone can't see this since the HTTP status
     * is 200) retries once with a freshly generated token before giving up.
     *
     * @param callable(string): \Illuminate\Http\Client\Response $makeRequest
     * @return array{success: bool, body: array, error: ?string}
     */
    private function callWithTokenRetry(string $pageId, callable $makeRequest, string $logLabel, array $logContext): array
    {
        $token = $this->getPageAccessToken($pageId);
        if (!$token) {
            return ['success' => false, 'body' => [], 'error' => 'No Pancake access token configured — add one in Settings.'];
        }

        $result = $this->issueRequest($makeRequest, $token, $logLabel, $logContext);

        if ($result['staleToken']) {
            $fresh = $this->refreshPageAccessToken($pageId);
            if ($fresh) {
                $result = $this->issueRequest($makeRequest, $fresh, $logLabel, $logContext);
            }
        }

        return ['success' => $result['success'], 'body' => $result['body'], 'error' => $result['error']];
    }

    /**
     * @param callable(string): \Illuminate\Http\Client\Response $makeRequest
     * @return array{success: bool, body: array, error: ?string, staleToken: bool}
     */
    private function issueRequest(callable $makeRequest, string $token, string $logLabel, array $logContext): array
    {
        try {
            $response = $makeRequest($token);

            if (!$response->successful()) {
                Log::warning("PancakeConversationApi: {$logLabel} failed", [...$logContext, 'status' => $response->status()]);
                return ['success' => false, 'body' => [], 'error' => "Pancake returned HTTP {$response->status()}.", 'staleToken' => false];
            }

            $body = $response->json() ?? [];

            if (($body['success'] ?? true) === false) {
                $staleToken = ($body['error_code'] ?? null) === 105;
                Log::warning("PancakeConversationApi: {$logLabel} returned success=false", [...$logContext, 'body' => $body]);
                return ['success' => false, 'body' => [], 'error' => $body['message'] ?? 'Pancake rejected this request.', 'staleToken' => $staleToken];
            }

            return ['success' => true, 'body' => $body, 'error' => null, 'staleToken' => false];
        } catch (\Throwable $e) {
            Log::warning("PancakeConversationApi: {$logLabel} threw", [...$logContext, 'message' => $e->getMessage()]);
            return ['success' => false, 'body' => [], 'error' => $e->getMessage(), 'staleToken' => false];
        }
    }
}
