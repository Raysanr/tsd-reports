<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Rewritten (2026-09-05): this page used to list `CallRecording` rows (a
 * separate, unused pipeline — a TSA's PC uploading via Phone Link, 0 rows
 * ever written). It now browses each TSA's real Google Drive folder live —
 * see CallRecordingController's own doc comment. Same Http::fake() pattern
 * LeadRecordingStreamRangeTest already uses for Drive-backed recording tests.
 */
class CallRecordingControllerTest extends TestCase
{
    use RefreshDatabase;

    private function fakeDriveConnection(): void
    {
        Setting::set('drive_client_id', 'client-id');
        Setting::set('drive_client_secret', 'client-secret');
        Setting::set('drive_refresh_token', 'refresh-token');
        Setting::set('drive_folder_eyecare', 'root-eyecare');
    }

    public function test_a_tsa_cannot_reach_the_call_recordings_page(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $user  = User::factory()->create(['role' => 'tsa', 'tsa_id' => $gemma->id]);

        $this->actingAs($user)->get(route('calls.call-recordings'))->assertForbidden();
    }

    public function test_when_drive_is_not_connected_it_shows_the_not_connected_state(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('calls.call-recordings'));

        $response->assertOk();
        $this->assertFalse($response->viewData('driveConnected'));
    }

    /** Confirmed live, 2026-09-05: walking every active TSA's Drive folder on
     *  every page load made the page take many seconds — requiring an
     *  explicit TSA pick keeps a bare page load from ever touching Drive. */
    public function test_with_drive_connected_but_no_tsa_picked_it_does_not_search_drive_at_all(): void
    {
        $this->fakeDriveConnection();
        TsaShift::create(['tsa_key' => 'RecTsaNoPick', 'display_name' => 'RecTsaNoPick', 'team' => 'Eyecare Team', 'sort_order' => 1]);

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-token']),
            'https://www.googleapis.com/drive/v3/*' => Http::response(['files' => []]),
        ]);

        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($admin)->get(route('calls.call-recordings'));

        $response->assertOk();
        $this->assertTrue($response->viewData('needsTsa'));
        $this->assertCount(0, $response->viewData('recordings'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'googleapis.com/drive'));
    }

    public function test_an_admin_can_list_recordings_from_drive_filtered_by_date_and_tsa(): void
    {
        $this->fakeDriveConnection();
        $tsa = TsaShift::create(['tsa_key' => 'RecTsa', 'display_name' => 'RecTsa', 'team' => 'Eyecare Team', 'sort_order' => 1]);

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-token']),
            'https://www.googleapis.com/drive/v3/files?q=%27root-eyecare%27*' => Http::response(
                ['files' => [['id' => 'tsa-folder', 'name' => 'RECTSA', 'mimeType' => 'application/vnd.google-apps.folder']]]
            ),
            'https://www.googleapis.com/drive/v3/files?q=%27tsa-folder%27*' => Http::response(['files' => [
                ['id' => 'rec-in-range', 'name' => '09171234567 2026-08-06 10-00-00.m4a', 'mimeType' => 'audio/mp4'],
                ['id' => 'rec-outside-range', 'name' => '09171234567 2026-08-01 10-00-00.m4a', 'mimeType' => 'audio/mp4'],
            ]]),
        ]);

        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($admin)->get(route('calls.call-recordings', [
            'date_from' => '2026-08-06', 'date_to' => '2026-08-06', 'tsa' => $tsa->id,
        ]));

        $response->assertOk();
        $this->assertTrue($response->viewData('driveConnected'));
        $recordings = $response->viewData('recordings');
        $this->assertCount(1, $recordings);
        $this->assertSame('rec-in-range', $recordings->first()['id']);
        $this->assertSame($tsa->id, $recordings->first()['tsa']->id);
    }

    public function test_streaming_a_recording_that_exists_in_the_tsas_drive_folder_succeeds(): void
    {
        $this->fakeDriveConnection();
        $tsa = TsaShift::create(['tsa_key' => 'RecTsa2', 'display_name' => 'RecTsa2', 'team' => 'Eyecare Team', 'sort_order' => 1]);

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-token']),
            'https://www.googleapis.com/drive/v3/files?q=%27root-eyecare%27*' => Http::response(
                ['files' => [['id' => 'tsa-folder', 'name' => 'RECTSA2', 'mimeType' => 'application/vnd.google-apps.folder']]]
            ),
            'https://www.googleapis.com/drive/v3/files?q=%27tsa-folder%27*' => Http::response(
                ['files' => [['id' => 'rec-1', 'name' => '09171234567 2026-08-06 10-00-00.m4a', 'mimeType' => 'audio/mp4']]]
            ),
            'https://www.googleapis.com/drive/v3/files/rec-1*' => Http::response('fake-audio-bytes', 200, ['Accept-Ranges' => 'bytes']),
        ]);

        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($admin)->get(route('calls.call-recordings.stream', [
            'tsa' => $tsa->id, 'fileId' => 'rec-1', 'month' => '2026-08',
        ]));

        $response->assertOk();
        $this->assertSame('fake-audio-bytes', $response->getContent());
    }

    public function test_streaming_a_fileid_not_in_the_tsas_drive_folder_is_rejected(): void
    {
        $this->fakeDriveConnection();
        $tsa = TsaShift::create(['tsa_key' => 'RecTsa3', 'display_name' => 'RecTsa3', 'team' => 'Eyecare Team', 'sort_order' => 1]);

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-token']),
            'https://www.googleapis.com/drive/v3/files?q=%27root-eyecare%27*' => Http::response(
                ['files' => [['id' => 'tsa-folder', 'name' => 'RECTSA3', 'mimeType' => 'application/vnd.google-apps.folder']]]
            ),
            'https://www.googleapis.com/drive/v3/files?q=%27tsa-folder%27*' => Http::response(['files' => []]),
        ]);

        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($admin)->get(route('calls.call-recordings.stream', [
            'tsa' => $tsa->id, 'fileId' => 'not-a-real-file', 'month' => '2026-08',
        ]));

        $response->assertNotFound();
    }

    public function test_a_tsa_cannot_stream_a_recording_even_with_a_direct_link(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $user  = User::factory()->create(['role' => 'tsa', 'tsa_id' => $gemma->id]);

        $this->actingAs($user)->get(route('calls.call-recordings.stream', [
            'tsa' => $gemma->id, 'fileId' => 'rec-1', 'month' => '2026-08',
        ]))->assertForbidden();
    }
}
