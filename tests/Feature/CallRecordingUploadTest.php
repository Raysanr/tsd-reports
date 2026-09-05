<?php

namespace Tests\Feature;

use App\Models\CallEvent;
use App\Models\CallRecording;
use App\Models\Lead;
use App\Models\Product;
use App\Models\TsaShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Split out from CallRecordingControllerTest (2026-09-05): this covers
 * `App\Http\Controllers\CallRecordingController::store` (POST
 * /api/call-recordings) — the TSA-PC-upload pipeline (Phone Link + a
 * system-audio recorder, api_token auth, no browser session). Confirmed
 * live and still route-registered, distinct from
 * App\Http\Controllers\CallTracker\CallRecordingController, which was
 * rewritten the same day to browse Google Drive directly instead of
 * reading these uploaded rows (see its own doc comment) — that page no
 * longer touches the CallRecording model at all, but the upload endpoint
 * itself is untouched and still needs its own coverage.
 */
class CallRecordingUploadTest extends TestCase
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
}
