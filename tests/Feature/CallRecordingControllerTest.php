<?php

namespace Tests\Feature;

use App\Models\CallEvent;
use App\Models\CallRecording;
use App\Models\Lead;
use App\Models\Product;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Ported from call-tracker (merged into one app 2026-08-12): Tsa -> TsaShift, routes -> calls.*.
 * Real call recordings come from a TSA's own PC (Phone Link + a free
 * system-audio recorder — see CallRecordingController's own doc comment for
 * why, since Android itself can't reliably record a call), uploaded here the
 * same way call events are: authenticated by a per-TSA api_token, no
 * browser session involved.
 */
class CallRecordingControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('call_recordings');
    }

    public function test_a_valid_token_uploads_and_stores_a_recording(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $gemma->update(['api_token' => 'secret-token']);

        $file = UploadedFile::fake()->create('call.mp3', 500, 'audio/mpeg');

        $response = $this->post('/api/call-recordings', [
            'api_token'   => 'secret-token',
            'recording'   => $file,
            'recorded_at' => '2026-08-06 10:00:00',
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $recording = CallRecording::first();
        $this->assertNotNull($recording);
        $this->assertSame($gemma->id, $recording->tsa_id);
        Storage::disk('call_recordings')->assertExists($recording->disk_path);
    }

    public function test_an_unknown_token_is_rejected(): void
    {
        $file = UploadedFile::fake()->create('call.mp3', 500, 'audio/mpeg');

        $response = $this->post('/api/call-recordings', [
            'api_token' => 'not-a-real-token',
            'recording' => $file,
        ]);

        $response->assertUnauthorized();
        $this->assertSame(0, CallRecording::count());
    }

    public function test_an_invalid_file_type_is_rejected(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $gemma->update(['api_token' => 'secret-token']);

        $file = UploadedFile::fake()->create('call.exe', 500, 'application/x-msdownload');

        $response = $this->postJson('/api/call-recordings', [
            'api_token' => 'secret-token',
            'recording' => $file,
        ]);

        $response->assertUnprocessable();
        $this->assertSame(0, CallRecording::count());
    }

    public function test_a_phone_number_matches_the_recording_to_that_tsas_own_lead(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $gemma->update(['api_token' => 'secret-token']);
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create([
            'pancake_order_id' => '1', 'customer_name' => 'Juan', 'phone_number' => '09171234567',
            'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned',
        ]);

        $file = UploadedFile::fake()->create('call.mp3', 500, 'audio/mpeg');
        $response = $this->post('/api/call-recordings', [
            'api_token'    => 'secret-token',
            'recording'    => $file,
            'phone_number' => '+63 917 123 4567',
        ]);

        $response->assertOk();
        $response->assertJson(['matched_lead_id' => $lead->id]);
    }

    public function test_with_no_phone_number_it_inherits_the_nearest_call_event_within_the_match_window(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $gemma->update(['api_token' => 'secret-token']);
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Juan', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);

        $event = CallEvent::create([
            'tsa_id' => $gemma->id, 'lead_id' => $lead->id, 'phone_number' => '09171234567',
            'direction' => 'outgoing', 'duration_seconds' => 120, 'occurred_at' => '2026-08-06 10:03:00',
        ]);

        $file = UploadedFile::fake()->create('call.mp3', 500, 'audio/mpeg');
        $response = $this->post('/api/call-recordings', [
            'api_token'   => 'secret-token',
            'recording'   => $file,
            'recorded_at' => '2026-08-06 10:00:00', // 3 minutes before the call event — within the 10-minute window
        ]);

        $response->assertOk();
        $response->assertJson(['matched_lead_id' => $lead->id]);
        $this->assertSame($event->id, CallRecording::first()->call_event_id);
    }

    public function test_a_call_event_outside_the_match_window_is_not_linked(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $gemma->update(['api_token' => 'secret-token']);
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Juan', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);

        CallEvent::create([
            'tsa_id' => $gemma->id, 'lead_id' => $lead->id, 'phone_number' => '09171234567',
            'direction' => 'outgoing', 'duration_seconds' => 120, 'occurred_at' => '2026-08-06 10:30:00', // 30 minutes away
        ]);

        $file = UploadedFile::fake()->create('call.mp3', 500, 'audio/mpeg');
        $response = $this->post('/api/call-recordings', [
            'api_token'   => 'secret-token',
            'recording'   => $file,
            'recorded_at' => '2026-08-06 10:00:00',
        ]);

        $response->assertOk();
        $response->assertJson(['matched_lead_id' => null]);
    }

    public function test_a_tsa_cannot_reach_the_call_recordings_page(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $user  = User::factory()->create(['role' => 'tsa', 'tsa_id' => $gemma->id]);

        $this->actingAs($user)->get(route('calls.call-recordings'))->assertForbidden();
    }

    public function test_an_admin_can_list_recordings_filtered_by_date_and_tsa(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();

        CallRecording::create(['tsa_id' => $gemma->id, 'disk_path' => 'x.mp3', 'file_size_bytes' => 1000, 'recorded_at' => '2026-08-06 10:00:00']);
        CallRecording::create(['tsa_id' => $mariel->id, 'disk_path' => 'y.mp3', 'file_size_bytes' => 1000, 'recorded_at' => '2026-08-06 11:00:00']);
        CallRecording::create(['tsa_id' => $gemma->id, 'disk_path' => 'z.mp3', 'file_size_bytes' => 1000, 'recorded_at' => '2026-08-01 10:00:00']); // outside range

        $response = $this->actingAs($admin)->get(route('calls.call-recordings', [
            'date_from' => '2026-08-06', 'date_to' => '2026-08-06', 'tsa' => $gemma->id,
        ]));

        $response->assertOk();
        $recordings = $response->viewData('recordings');
        $this->assertCount(1, $recordings);
        $this->assertSame($gemma->id, $recordings->first()->tsa_id);
    }

    public function test_streaming_a_recording_that_exists_on_disk_succeeds(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();

        Storage::disk('call_recordings')->put('call-recordings/1/test.mp3', 'fake-audio-bytes');
        $recording = CallRecording::create([
            'tsa_id' => $gemma->id, 'disk_path' => 'call-recordings/1/test.mp3',
            'original_filename' => 'test.mp3', 'file_size_bytes' => 17, 'recorded_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('calls.call-recordings.stream', $recording));

        $response->assertOk();
    }

    public function test_a_tsa_cannot_stream_a_recording_even_with_a_direct_link(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $user  = User::factory()->create(['role' => 'tsa', 'tsa_id' => $gemma->id]);

        Storage::disk('call_recordings')->put('call-recordings/1/test.mp3', 'fake-audio-bytes');
        $recording = CallRecording::create([
            'tsa_id' => $gemma->id, 'disk_path' => 'call-recordings/1/test.mp3',
            'original_filename' => 'test.mp3', 'file_size_bytes' => 17, 'recorded_at' => now(),
        ]);

        $this->actingAs($user)->get(route('calls.call-recordings.stream', $recording))->assertForbidden();
    }
}
