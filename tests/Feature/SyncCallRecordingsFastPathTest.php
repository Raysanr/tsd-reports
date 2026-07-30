<?php

namespace Tests\Feature;

use App\Models\CallRecordingHour;
use App\Models\Setting;
use App\Models\TsaShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Real production case (Eyecare/Marisol, 2026-07-29): the sync recursively
 * walked a TSA's ENTIRE Drive folder history (2,257 files across every month
 * ever uploaded) on every single run, just to find the ~50 files relevant to
 * one target day — confirmed live this took ~30 seconds of listing alone, per
 * TSA, before a single file was even downloaded. That's very likely why real
 * AHT/OPT data only ever showed up for a couple of a day's hours: the walk was
 * too slow to reliably finish (every TSA, every 2-hourly run) before running
 * out of time, so which hours got downloaded before that happened was
 * effectively random.
 *
 * The real folder tree nests a "<MONTH> <YEAR>" folder (e.g. "JULY 2026")
 * directly under each TSA, and a "<MONTH> <DAY>" folder (e.g. "JULY 29", no
 * leading zero) under THAT, holding that day's files directly — confirmed
 * live. These tests prove the sync now navigates straight there instead of
 * enumerating irrelevant month folders, and still falls back to the original
 * full walk when that clean structure isn't there.
 */
class SyncCallRecordingsFastPathTest extends TestCase
{
    use RefreshDatabase;

    private function configureDrive(): void
    {
        Setting::set('drive_client_id', 'client-id');
        Setting::set('drive_client_secret', 'client-secret');
        Setting::set('drive_refresh_token', 'refresh-token');
        Setting::set('drive_folder_eyecare', 'root-eyecare');
    }

    private function folderListResponse(array $children): array
    {
        return ['files' => $children];
    }

    private function folder(string $id, string $name): array
    {
        return ['id' => $id, 'name' => $name, 'mimeType' => 'application/vnd.google-apps.folder'];
    }

    private function file(string $id, string $name): array
    {
        return ['id' => $id, 'name' => $name, 'mimeType' => 'audio/mp4'];
    }

    public function test_navigates_straight_to_the_day_folder_without_listing_other_months(): void
    {
        $this->configureDrive();
        TsaShift::where('team', 'Eyecare Team')->where('tsa_key', 'Julie')->update(['tsa_key' => 'Julie']);

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-token']),

            // Root Eyecare folder -> month-recordings folder
            'https://www.googleapis.com/drive/v3/files?q=%27root-eyecare%27*' => Http::response(
                $this->folderListResponse([$this->folder('month-root', 'JULY CALL RECORDINGS')])
            ),
            // Month-recordings folder -> per-TSA folders
            'https://www.googleapis.com/drive/v3/files?q=%27month-root%27*' => Http::response(
                $this->folderListResponse([$this->folder('julie-root', 'JULIE'), $this->folder('other-root', 'OTHER TSA')])
            ),
            // Julie's own root -> ONLY the year-month folder (fast path)
            'https://www.googleapis.com/drive/v3/files?q=%27julie-root%27*' => Http::response(
                $this->folderListResponse([$this->folder('julie-july-2026', 'JULY 2026')])
            ),
            // Year-month folder -> the target day folder (+ a decoy other day)
            'https://www.googleapis.com/drive/v3/files?q=%27julie-july-2026%27*' => Http::response(
                $this->folderListResponse([
                    $this->folder('julie-july-29', 'JULY 29'),
                    $this->folder('julie-july-28', 'JULY 28'),
                ])
            ),
            // The day folder itself -> one file
            'https://www.googleapis.com/drive/v3/files?q=%27julie-july-29%27*' => Http::response(
                $this->folderListResponse([$this->file('rec-1', '09171234567 2026-07-29 08-15-00.m4a')])
            ),
            // Download — content doesn't need to be a real m4a for this test;
            // an unparsable download just means no CallRecordingHour row, which
            // isn't what this test is checking.
            'https://www.googleapis.com/drive/v3/files/rec-1*' => Http::response('not-a-real-m4a'),
        ]);

        $this->artisan('calls:sync-recordings', ['--date' => '2026-07-29'])->assertSuccessful();

        // The decoy "JULY 28" day folder and the "OTHER TSA" folder must never
        // have had their own children listed — proves the walk went straight
        // to the one relevant folder instead of enumerating siblings.
        Http::assertNotSent(fn($request) => str_contains($request->url(), "q=%27julie-july-28%27"));
        Http::assertNotSent(fn($request) => str_contains($request->url(), "q=%27other-root%27"));
    }

    public function test_falls_back_to_the_full_walk_when_there_is_no_year_month_day_structure(): void
    {
        $this->configureDrive();

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-token']),
            'https://www.googleapis.com/drive/v3/files?q=%27root-eyecare%27*' => Http::response(
                $this->folderListResponse([$this->folder('month-root', 'JULY CALL RECORDINGS')])
            ),
            'https://www.googleapis.com/drive/v3/files?q=%27month-root%27*' => Http::response(
                $this->folderListResponse([$this->folder('julie-root', 'JULIE')])
            ),
            // Julie's own root has files sitting directly in it — no
            // "<MONTH> <YEAR>" subfolder at all (the messy variant the class
            // doc already describes).
            'https://www.googleapis.com/drive/v3/files?q=%27julie-root%27*' => Http::response(
                $this->folderListResponse([$this->file('rec-2', '09171234567 2026-07-29 09-30-00.m4a')])
            ),
            'https://www.googleapis.com/drive/v3/files/rec-2*' => Http::response('not-a-real-m4a'),
        ]);

        // Must not error out just because the fast-path structure isn't there.
        $this->artisan('calls:sync-recordings', ['--date' => '2026-07-29'])->assertSuccessful();

        Http::assertSent(fn($request) => str_contains($request->url(), "q=%27julie-root%27"));
    }

    public function test_real_recording_still_gets_synced_via_the_fast_path(): void
    {
        $this->configureDrive();

        // A minimal but real, parseable M4A: ftyp box + moov > mvhd (version 0,
        // timescale 1000, duration 5000 => 5.0 real seconds).
        $ftyp = "\x00\x00\x00\x10ftypM4A \x00\x00\x00\x00";
        $mvhdBody = str_repeat("\x00", 12) . pack('N', 1000) . pack('N', 5000) . str_repeat("\x00", 80);
        $mvhd = pack('N', 8 + strlen($mvhdBody)) . 'mvhd' . $mvhdBody;
        $moov = pack('N', 8 + strlen($mvhd)) . 'moov' . $mvhd;
        $m4aBytes = $ftyp . $moov;

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-token']),
            'https://www.googleapis.com/drive/v3/files?q=%27root-eyecare%27*' => Http::response(
                $this->folderListResponse([$this->folder('month-root', 'JULY CALL RECORDINGS')])
            ),
            'https://www.googleapis.com/drive/v3/files?q=%27month-root%27*' => Http::response(
                $this->folderListResponse([$this->folder('julie-root', 'JULIE')])
            ),
            'https://www.googleapis.com/drive/v3/files?q=%27julie-root%27*' => Http::response(
                $this->folderListResponse([$this->folder('julie-july-2026', 'JULY 2026')])
            ),
            'https://www.googleapis.com/drive/v3/files?q=%27julie-july-2026%27*' => Http::response(
                $this->folderListResponse([$this->folder('julie-july-29', 'JULY 29')])
            ),
            'https://www.googleapis.com/drive/v3/files?q=%27julie-july-29%27*' => Http::response(
                $this->folderListResponse([$this->file('rec-3', '09171234567 2026-07-29 08-15-00.m4a')])
            ),
            'https://www.googleapis.com/drive/v3/files/rec-3*' => Http::response($m4aBytes),
        ]);

        $this->artisan('calls:sync-recordings', ['--date' => '2026-07-29'])->assertSuccessful();

        $row = CallRecordingHour::where('tsa_key', 'Julie')->whereDate('date', '2026-07-29')->where('hour', 8)->first();
        $this->assertNotNull($row);
        $this->assertSame(5, $row->total_seconds);
        $this->assertSame(1, $row->call_count);
    }
}
