<?php

namespace Tests\Unit;

use App\Support\SyncHealth;
use PHPUnit\Framework\TestCase;

class SyncHealthRedactSecretsTest extends TestCase
{
    public function test_redacts_api_key_from_a_query_string(): void
    {
        $message = 'cURL error 6: could not resolve host for https://pos.pages.fm/api/v1/shops/1/orders?api_key=some-secret-value&page_size=100';

        $redacted = SyncHealth::redactSecrets($message);

        $this->assertStringNotContainsString('some-secret-value', $redacted);
        $this->assertStringContainsString('api_key=REDACTED', $redacted);
    }

    /**
     * Explicit request: the same helper is now also applied to Google Drive
     * sync errors (SyncCallRecordings, SettingsController) — client_secret/
     * refresh_token/access_token are body-based there today, not query-string,
     * but redacting these patterns too is cheap insurance against a future
     * call site (or a Guzzle version) that ends up putting one in a URL.
     */
    public function test_redacts_google_oauth_secret_patterns_too(): void
    {
        $message = 'Request failed for https://oauth2.googleapis.com/token?client_secret=GOCSPX-abc123&refresh_token=1//xyz789&access_token=ya29.def456';

        $redacted = SyncHealth::redactSecrets($message);

        $this->assertStringNotContainsString('GOCSPX-abc123', $redacted);
        $this->assertStringNotContainsString('1//xyz789', $redacted);
        $this->assertStringNotContainsString('ya29.def456', $redacted);
        $this->assertStringContainsString('client_secret=REDACTED', $redacted);
        $this->assertStringContainsString('refresh_token=REDACTED', $redacted);
        $this->assertStringContainsString('access_token=REDACTED', $redacted);
    }

    public function test_is_case_insensitive(): void
    {
        $message = 'failed for https://example.com/x?API_KEY=secret-value';

        $redacted = SyncHealth::redactSecrets($message);

        $this->assertStringNotContainsString('secret-value', $redacted);
    }

    public function test_leaves_a_message_with_no_secret_pattern_unchanged(): void
    {
        $message = 'Connection timed out after 30 seconds.';

        $this->assertSame($message, SyncHealth::redactSecrets($message));
    }

    public function test_null_passes_through_as_null(): void
    {
        $this->assertNull(SyncHealth::redactSecrets(null));
    }
}
