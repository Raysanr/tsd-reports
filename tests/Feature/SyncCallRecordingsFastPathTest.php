<?php

namespace Tests\Feature;

use App\Models\CallRecordingHour;
use App\Models\Setting;
use App\Models\TsaShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Rewritten 2026-08-25 — the previous version of this file tested a
 * "<TSA>/<MONTH> <YEAR>/<MONTH> <DAY>" tree that was already stale before
 * today (the class's own docblock noted it was "simplified 2026-08-19" to a
 * flat Team > TSA structure). Confirmed live today the REAL tree gained a
 * new outer layer instead: Team > <full month name, e.g. "AUGUST"> > TSA >
 * day-subfolder(s) with genuinely inconsistent per-TSA naming (e.g.
 * "AUGUST 7" for one TSA, "August 13-- Recording uploaded" for another) —
 * see GoogleDriveClient::resolveTsaFolder()/listFilesRecursively()'s own
 * doc comments for the full history. These tests now exercise THAT
 * structure: month-folder resolution, the flat-lookup fallback when no
 * month folder exists, and a real recording synced end-to-end through an
 * inconsistently-named day-subfolder.
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

    public function test_resolves_the_tsa_folder_through_the_month_layer_and_walks_its_day_subfolders(): void
    {
        $this->configureDrive();
        TsaShift::where('team', 'Eyecare Team')->where('tsa_key', 'Julie')->update(['tsa_key' => 'Julie']);

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-token']),

            // Team root -> month folders (only AUGUST matters — the target
            // date below is in August).
            'https://www.googleapis.com/drive/v3/files?q=%27root-eyecare%27*' => Http::response(
                $this->folderListResponse([$this->folder('month-august', 'AUGUST'), $this->folder('month-july', 'JULY')])
            ),
            // AUGUST -> per-TSA folders.
            'https://www.googleapis.com/drive/v3/files?q=%27month-august%27*' => Http::response(
                $this->folderListResponse([$this->folder('julie-root', 'JULIE'), $this->folder('other-root', 'OTHER TSA')])
            ),
            // Julie's own folder -> a day-subfolder with a real, messy name
            // (not a clean "AUGUST 29" — proves this is never name-matched,
            // only walked).
            'https://www.googleapis.com/drive/v3/files?q=%27julie-root%27*' => Http::response(
                $this->folderListResponse([$this->folder('julie-aug-29', 'August 29-- Recording uploaded')])
            ),
            'https://www.googleapis.com/drive/v3/files?q=%27julie-aug-29%27*' => Http::response(
                $this->folderListResponse([$this->file('rec-1', '09171234567 2026-08-29 08-15-00.m4a')])
            ),
            'https://www.googleapis.com/drive/v3/files/rec-1*' => Http::response('not-a-real-m4a'),
        ]);

        $this->artisan('calls:sync-recordings', ['--date' => '2026-08-29'])->assertSuccessful();

        // JULY's own children and the sibling "OTHER TSA" folder must never
        // have been listed — proves this went straight to the right month
        // and TSA instead of enumerating siblings.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), "q=%27month-july%27"));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), "q=%27other-root%27"));
    }

    public function test_falls_back_to_a_flat_team_root_lookup_when_there_is_no_month_folder(): void
    {
        $this->configureDrive();

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-token']),
            // Team root has no month folders at all — the older flat
            // Team > TSA structure a team that hasn't adopted the month
            // layer yet (or is between months) would still have.
            'https://www.googleapis.com/drive/v3/files?q=%27root-eyecare%27*' => Http::response(
                $this->folderListResponse([$this->folder('julie-root', 'JULIE')])
            ),
            'https://www.googleapis.com/drive/v3/files?q=%27julie-root%27*' => Http::response(
                $this->folderListResponse([$this->file('rec-2', '09171234567 2026-08-29 09-30-00.m4a')])
            ),
            'https://www.googleapis.com/drive/v3/files/rec-2*' => Http::response('not-a-real-m4a'),
        ]);

        // Must not error out just because there's no month folder to find.
        $this->artisan('calls:sync-recordings', ['--date' => '2026-08-29'])->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), "q=%27julie-root%27"));
    }

    public function test_a_real_recording_gets_synced_through_an_inconsistently_named_day_subfolder(): void
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
                $this->folderListResponse([$this->folder('month-august', 'AUGUST')])
            ),
            'https://www.googleapis.com/drive/v3/files?q=%27month-august%27*' => Http::response(
                $this->folderListResponse([$this->folder('julie-root', 'JULIE')])
            ),
            // Real per-TSA naming is inconsistent (confirmed live) — this
            // one uses "Aug 29" with no dashes, unlike the previous test's
            // "August 29-- Recording uploaded".
            'https://www.googleapis.com/drive/v3/files?q=%27julie-root%27*' => Http::response(
                $this->folderListResponse([$this->folder('julie-aug-29', 'Aug 29')])
            ),
            'https://www.googleapis.com/drive/v3/files?q=%27julie-aug-29%27*' => Http::response(
                $this->folderListResponse([$this->file('rec-3', '09171234567 2026-08-29 08-15-00.m4a')])
            ),
            'https://www.googleapis.com/drive/v3/files/rec-3*' => Http::response($m4aBytes),
        ]);

        $this->artisan('calls:sync-recordings', ['--date' => '2026-08-29'])->assertSuccessful();

        $row = CallRecordingHour::where('tsa_key', 'Julie')->whereDate('date', '2026-08-29')->where('hour', 8)->first();
        $this->assertNotNull($row);
        $this->assertSame(5, $row->total_seconds);
        $this->assertSame(1, $row->call_count);
    }

    /**
     * Root-caused 2026-09-08 (real production case: Angel Margallo's real
     * Pancake tag/tsa_key is "Angelica" — 851 real orders already
     * attributed under that exact string, so tsa_key itself is correct and
     * must not change — but her real Drive folder is just "ANGEL", one of
     * her OTHER tag_keywords entries. Grace Olivo is the identical shape:
     * tsa_key "Joanna" vs. folder "GRACE"). Before
     * GoogleDriveClient::folderBelongsToTsa() existed, resolveTsaFolder()
     * only ever tried display_name/tsa_key, so neither TSA's real,
     * currently-uploading recordings ever synced — this proves the fix:
     * a folder named after a tag_keywords entry that is NEITHER
     * display_name NOR tsa_key is still found.
     */
    public function test_resolves_a_tsa_folder_named_after_a_secondary_tag_keyword_not_just_tsa_key_or_display_name(): void
    {
        $this->configureDrive();
        TsaShift::where('team', 'Eyecare Team')->where('tsa_key', 'Julie')
            ->update(['tsa_key' => 'Joanna', 'display_name' => 'Grace Olivo', 'tag_keywords' => 'JOANNA,GRACE']);

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-token']),
            'https://www.googleapis.com/drive/v3/files?q=%27root-eyecare%27*' => Http::response(
                // No month folder — same flat fallback as the earlier test
                // above, just with a folder name that matches neither
                // "Joanna" (tsa_key) nor "Grace Olivo" (display_name).
                $this->folderListResponse([$this->folder('grace-root', 'GRACE')])
            ),
            'https://www.googleapis.com/drive/v3/files?q=%27grace-root%27*' => Http::response(
                $this->folderListResponse([$this->file('rec-4', '09171234567 2026-08-29 10-00-00.m4a')])
            ),
            'https://www.googleapis.com/drive/v3/files/rec-4*' => Http::response('not-a-real-m4a'),
        ]);

        $this->artisan('calls:sync-recordings', ['--date' => '2026-08-29'])->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), "q=%27grace-root%27"));
    }

    /**
     * Real production shape confirmed 2026-09-12: only ONE of eight TSAs had
     * any synced CallRecordingHour data for a given day — every TSA
     * processed after her in iteration order had none, even though their
     * own Drive folders were never actually reached. Root cause: a single
     * TSA's Drive call throwing (cURL timeouts, confirmed repeatedly in
     * production logs) propagated all the way up to handle()'s own
     * try/catch, aborting the WHOLE run and discarding every other TSA's
     * already-gathered $totals with it. Julie (sort_order 3, processed
     * FIRST) throws here; Joana (sort_order 4, processed SECOND) must still
     * get her real recording synced despite Julie's folder failing.
     */
    public function test_one_tsas_drive_failure_does_not_lose_another_tsas_synced_data(): void
    {
        $this->configureDrive();

        $ftyp = "\x00\x00\x00\x10ftypM4A \x00\x00\x00\x00";
        $mvhdBody = str_repeat("\x00", 12) . pack('N', 1000) . pack('N', 5000) . str_repeat("\x00", 80);
        $mvhd = pack('N', 8 + strlen($mvhdBody)) . 'mvhd' . $mvhdBody;
        $moov = pack('N', 8 + strlen($mvhd)) . 'moov' . $mvhd;
        $m4aBytes = $ftyp . $moov;

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-token']),
            // Team root -> per-TSA folders, no month layer (flat fallback,
            // same shape the earlier "falls back to a flat team root
            // lookup" test above already exercises).
            'https://www.googleapis.com/drive/v3/files?q=%27root-eyecare%27*' => Http::response(
                $this->folderListResponse([$this->folder('julie-root', 'JULIE'), $this->folder('joana-root', 'JOANA')])
            ),
            // Julie's own folder listing call errors out.
            'https://www.googleapis.com/drive/v3/files?q=%27julie-root%27*' => Http::response(['error' => 'boom'], 500),
            // Joana's own folder — must still be reached and synced.
            'https://www.googleapis.com/drive/v3/files?q=%27joana-root%27*' => Http::response(
                $this->folderListResponse([$this->file('rec-5', '09171234567 2026-08-29 08-15-00.m4a')])
            ),
            'https://www.googleapis.com/drive/v3/files/rec-5*' => Http::response($m4aBytes),
        ]);

        $this->artisan('calls:sync-recordings', ['--date' => '2026-08-29'])->assertSuccessful();

        $this->assertNull(CallRecordingHour::where('tsa_key', 'Julie')->whereDate('date', '2026-08-29')->first());

        $joanaRow = CallRecordingHour::where('tsa_key', 'Joana')->whereDate('date', '2026-08-29')->where('hour', 8)->first();
        $this->assertNotNull($joanaRow, "Joana's recording must still sync even though Julie's folder (processed first) failed.");
        $this->assertSame(5, $joanaRow->total_seconds);
    }
}
