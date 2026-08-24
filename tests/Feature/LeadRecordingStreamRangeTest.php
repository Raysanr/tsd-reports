<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Setting;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Explicit request (2026-08-24): a real production recording ("Aug 23,
 * 9:25 AM") showed working player controls but stayed stuck at 0:00/0:00
 * forever — real phone-recorded M4A/MP4 files are almost never "fast-start"
 * optimized (their moov metadata atom sits at the end of the file, after
 * the audio data), so a browser's <audio> element can only find it by
 * seeking via Range requests. LeadController::streamRecording() used to
 * always return a single-shot 200 with no Accept-Ranges/Range support at
 * all — this covers the fix (GoogleDriveClient::downloadFileRanged()
 * forwarding the browser's own Range header straight through to Drive,
 * which supports Range requests same as any static file host).
 */
class LeadRecordingStreamRangeTest extends TestCase
{
    use RefreshDatabase;

    private function setUpLeadWithRecording(): Lead
    {
        Setting::set('drive_client_id', 'client-id');
        Setting::set('drive_client_secret', 'client-secret');
        Setting::set('drive_refresh_token', 'refresh-token');
        Setting::set('drive_folder_eyecare', 'root-eyecare');

        $tsa = TsaShift::create(['tsa_key' => 'RecTestTsa', 'display_name' => 'RecTestTsa', 'team' => 'Eyecare Team', 'sort_order' => 1]);

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-token']),
            // No month folder present — falls back to a flat team-root lookup.
            'https://www.googleapis.com/drive/v3/files?q=%27root-eyecare%27*' => Http::response(
                ['files' => [['id' => 'julie-root', 'name' => 'RECTESTTSA', 'mimeType' => 'application/vnd.google-apps.folder']]]
            ),
            'https://www.googleapis.com/drive/v3/files?q=%27julie-root%27*' => Http::response(
                ['files' => [['id' => 'rec-1', 'name' => '09171234567 2026-08-23 09-25-00.m4a', 'mimeType' => 'audio/mp4']]]
            ),
            // The actual media download — branches on whether the request carried a Range header.
            'https://www.googleapis.com/drive/v3/files/rec-1*' => Http::sequence()
                ->push('full-file-bytes-000000000000', 200, ['Accept-Ranges' => 'bytes'])
                ->push('0000', 206, ['Content-Range' => 'bytes 0-3/28', 'Content-Length' => '4']),
        ]);

        return Lead::create([
            'pancake_order_id' => 'REC-TEST-1',
            'customer_name'    => 'Recording Test',
            'phone_number'     => '09171234567',
            'tsa_id'           => $tsa->id,
            'status'           => 'called',
            'called_at'        => now(),
        ]);
    }

    public function test_a_range_request_gets_relayed_as_a_206_with_content_range(): void
    {
        $lead = $this->setUpLeadWithRecording();
        $admin = User::factory()->create(['role' => 'admin']);

        // First call with no Range (matches the sequence's first fake response).
        $this->actingAs($admin)->get(route('calls.leads.recordings.stream', [$lead, 'rec-1']))
            ->assertOk()
            ->assertHeader('Accept-Ranges', 'bytes');

        // Second call WITH a Range header (matches the sequence's second, 206 response).
        $response = $this->actingAs($admin)
            ->withHeaders(['Range' => 'bytes=0-3'])
            ->get(route('calls.leads.recordings.stream', [$lead, 'rec-1']));

        $response->assertStatus(206);
        $response->assertHeader('Content-Range', 'bytes 0-3/28');
        $response->assertHeader('Accept-Ranges', 'bytes');
    }

    public function test_a_plain_request_with_no_range_header_still_gets_a_full_200_with_accept_ranges(): void
    {
        $lead = $this->setUpLeadWithRecording();
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('calls.leads.recordings.stream', [$lead, 'rec-1']));

        $response->assertOk();
        $response->assertHeader('Accept-Ranges', 'bytes');
        $this->assertSame('full-file-bytes-000000000000', $response->getContent());
    }
}
