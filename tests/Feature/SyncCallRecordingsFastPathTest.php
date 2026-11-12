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

    public function test_resolves_the_tsa_folder_inside_the_team_folder_and_walks_its_day_subfolders(): void
    {
        $this->configureDrive();
        TsaShift::where('team', 'Eyecare Team')->where('tsa_key', 'Julie')->update(['tsa_key' => 'Julie']);

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-token']),

            // Team folder -> one subfolder per TSA (2026-11-12 layout).
            'https://www.googleapis.com/drive/v3/files?q=%27root-eyecare%27*' => Http::response(
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

        // The sibling "OTHER TSA" folder must never have been listed.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), "q=%27other-root%27"));
    }

    public function test_finds_the_tsa_folder_directly_under_the_team_folder(): void
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
     * Real production gap confirmed 2026-10-05: Team Closing's Drive root
     * only had 6 TSA folders while several more closing-team TSAs had real
     * call activity — resolveTsaFolder() returning null for a TSA with no
     * matching folder (missing entirely, or named something not covered by
     * display_name/tsa_key/tag_keywords) was a bare `continue` with zero
     * trace anywhere, indistinguishable from "she made no calls that day."
     * Now logs a warning so a naming gap doesn't stay invisible.
     */
    public function test_a_tsa_with_no_matching_drive_folder_at_all_logs_a_warning_instead_of_vanishing_silently(): void
    {
        $this->configureDrive();
        TsaShift::where('team', 'Eyecare Team')->where('tsa_key', 'Julie')->update(['tsa_key' => 'Julie']);

        \Illuminate\Support\Facades\Log::spy();

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-token']),
            // No month folder, and the lone folder under team root belongs
            // to an entirely different TSA — Julie has no folder anywhere.
            'https://www.googleapis.com/drive/v3/files?q=%27root-eyecare%27*' => Http::response(
                $this->folderListResponse([$this->folder('other-root', 'SOMEONE ELSE')])
            ),
        ]);

        $this->artisan('calls:sync-recordings', ['--date' => '2026-08-29'])->assertSuccessful();

        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->with('calls:sync-recordings: no matching Drive folder for TSA', \Mockery::on(
                fn ($context) => $context['tsa_key'] === 'Julie' && $context['date'] === '2026-08-29'
            ))
            ->once();
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

    /**
     * Regression test, 2026-09-16: a Dashboard TSA Performance screenshot
     * showed several TSAs with blank AHT across a 3-day range — root-caused
     * live: this command only ever covered "today," so a day the 2-hourly
     * cron missed or ran before a phone's auto-upload caught up (confirmed
     * real: Kathleen and Hannah both had zero CallRecordingHour rows for a
     * day whose real Drive folder already had that day's recordings hours
     * earlier) stayed permanently empty. A default (no --date) run now also
     * re-sweeps yesterday, so a missed day self-heals on the very next
     * scheduled run instead of needing a manual backfill.
     */
    public function test_a_default_run_with_no_explicit_date_also_re_syncs_yesterday(): void
    {
        $this->configureDrive();
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-08-30 10:00:00', 'Asia/Manila'));

        $ftyp = "\x00\x00\x00\x10ftypM4A \x00\x00\x00\x00";
        $mvhdBody = str_repeat("\x00", 12) . pack('N', 1000) . pack('N', 5000) . str_repeat("\x00", 80);
        $mvhd = pack('N', 8 + strlen($mvhdBody)) . 'mvhd' . $mvhdBody;
        $moov = pack('N', 8 + strlen($mvhd)) . 'moov' . $mvhd;
        $m4aBytes = $ftyp . $moov;

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-token']),
            'https://www.googleapis.com/drive/v3/files?q=%27root-eyecare%27*' => Http::response(
                $this->folderListResponse([$this->folder('julie-root', 'JULIE')])
            ),
            'https://www.googleapis.com/drive/v3/files?q=%27julie-root%27*' => Http::response(
                $this->folderListResponse([
                    $this->file('rec-today', '09171234567 2026-08-30 08-15-00.m4a'),
                    $this->file('rec-yesterday', '09171234567 2026-08-29 09-00-00.m4a'),
                ])
            ),
            'https://www.googleapis.com/drive/v3/files/rec-today*' => Http::response($m4aBytes),
            'https://www.googleapis.com/drive/v3/files/rec-yesterday*' => Http::response($m4aBytes),
        ]);

        // No --date at all — the default scheduled path.
        $this->artisan('calls:sync-recordings')->assertSuccessful();

        $todayRow     = CallRecordingHour::where('tsa_key', 'Julie')->whereDate('date', '2026-08-30')->where('hour', 8)->first();
        $yesterdayRow = CallRecordingHour::where('tsa_key', 'Julie')->whereDate('date', '2026-08-29')->where('hour', 9)->first();
        $this->assertNotNull($todayRow, 'Today\'s own recording must still sync.');
        $this->assertNotNull($yesterdayRow, 'Yesterday\'s recording must ALSO sync on a default run, not just today\'s.');
    }

    /** An explicit --date backfill means exactly that one day — it must
     *  never silently ALSO re-sync the day after it. */
    public function test_an_explicit_date_backfill_does_not_also_sync_the_next_day(): void
    {
        $this->configureDrive();

        $ftyp = "\x00\x00\x00\x10ftypM4A \x00\x00\x00\x00";
        $mvhdBody = str_repeat("\x00", 12) . pack('N', 1000) . pack('N', 5000) . str_repeat("\x00", 80);
        $mvhd = pack('N', 8 + strlen($mvhdBody)) . 'mvhd' . $mvhdBody;
        $moov = pack('N', 8 + strlen($mvhd)) . 'moov' . $mvhd;
        $m4aBytes = $ftyp . $moov;

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-token']),
            'https://www.googleapis.com/drive/v3/files?q=%27root-eyecare%27*' => Http::response(
                $this->folderListResponse([$this->folder('julie-root', 'JULIE')])
            ),
            'https://www.googleapis.com/drive/v3/files?q=%27julie-root%27*' => Http::response(
                $this->folderListResponse([
                    $this->file('rec-target', '09171234567 2026-08-29 08-15-00.m4a'),
                    $this->file('rec-next-day', '09171234567 2026-08-30 09-00-00.m4a'),
                ])
            ),
            'https://www.googleapis.com/drive/v3/files/rec-target*' => Http::response($m4aBytes),
            'https://www.googleapis.com/drive/v3/files/rec-next-day*' => Http::response($m4aBytes),
        ]);

        $this->artisan('calls:sync-recordings', ['--date' => '2026-08-29'])->assertSuccessful();

        $targetRow  = CallRecordingHour::where('tsa_key', 'Julie')->whereDate('date', '2026-08-29')->first();
        $nextDayRow = CallRecordingHour::where('tsa_key', 'Julie')->whereDate('date', '2026-08-30')->first();
        $this->assertNotNull($targetRow);
        $this->assertNull($nextDayRow, 'An explicit --date must sync only that one day.');
    }

    // ── 2026-11-12: team folder > TSA subfolder > day folders ──────────

    private function m4a(int $seconds): string
    {
        $ftyp = "\x00\x00\x00\x10ftypM4A \x00\x00\x00\x00";
        $mvhdBody = str_repeat("\x00", 12) . pack('N', 1000) . pack('N', $seconds * 1000) . str_repeat("\x00", 80);
        $mvhd = pack('N', 8 + strlen($mvhdBody)) . 'mvhd' . $mvhdBody;

        return $ftyp . pack('N', 8 + strlen($mvhd)) . 'moov' . $mvhd;
    }

    private function onlyTsa(string $team, array $attrs): TsaShift
    {
        TsaShift::query()->forceDelete();

        return TsaShift::create($attrs + ['team' => $team, 'sort_order' => 1]);
    }

    public function test_each_team_only_reads_its_own_folder(): void
    {
        $this->configureDrive();
        Setting::set('drive_folder_sh_naturals', 'root-closing');
        $this->onlyTsa('SH Naturals', ['tsa_key' => 'Lika', 'display_name' => 'Trocio LIKA', 'tag_keywords' => 'LIKA']);

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-token']),
            'https://www.googleapis.com/drive/v3/files?q=%27root-closing%27*' => Http::response($this->folderListResponse([$this->folder('lika-closing', 'LIKA')])),
            'https://www.googleapis.com/drive/v3/files?q=%27root-eyecare%27*' => Http::response($this->folderListResponse([$this->folder('lika-opening', 'LIKA')])),
            'https://www.googleapis.com/drive/v3/files?q=%27lika-closing%27*' => Http::response($this->folderListResponse([])),
        ]);

        $this->artisan('calls:sync-recordings', ['--date' => '2026-11-11'])->assertSuccessful();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'q=%27lika-closing%27'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'q=%27root-eyecare%27'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'q=%27lika-opening%27'));
    }

    public function test_a_drive_folder_name_matches_only_that_folder(): void
    {
        $this->configureDrive();
        $this->onlyTsa('Eyecare Team', ['tsa_key' => 'Julie', 'display_name' => 'Julie', 'tag_keywords' => 'JULIE', 'drive_folder_name' => 'jf recordings']);

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-token']),
            'https://www.googleapis.com/drive/v3/files?q=%27root-eyecare%27*' => Http::response($this->folderListResponse([
                $this->folder('julie-name', 'JULIE'), $this->folder('julie-override', 'JF RECORDINGS'),
            ])),
            'https://www.googleapis.com/drive/v3/files?q=%27julie-override%27*' => Http::response($this->folderListResponse([])),
        ]);

        $this->artisan('calls:sync-recordings', ['--date' => '2026-11-11'])->assertSuccessful();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'q=%27julie-override%27'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'q=%27julie-name%27'));
    }

    public function test_word_and_tag_matching_find_the_folder(): void
    {
        $drive = app(\App\Support\GoogleDriveClient::class);
        $this->configureDrive();
        Http::fake([
            'https://www.googleapis.com/drive/v3/files?q=%27root-eyecare%27*' => Http::response($this->folderListResponse([
                $this->folder('lika', 'LIKA'), $this->folder('angel', 'ANGEL'), $this->folder('tsa', 'TSA'),
            ])),
        ]);

        $lika = new TsaShift(['tsa_key' => 'Trocio', 'display_name' => 'Trocio LIKA', 'team' => 'Eyecare Team', 'tag_keywords' => 'TROCIO']);
        $angel = new TsaShift(['tsa_key' => 'Angelica', 'display_name' => 'Angelica Margallo', 'team' => 'Eyecare Team', 'tag_keywords' => 'ANGELICA,ANGEL-TSA']);
        $nobody = new TsaShift(['tsa_key' => 'Mo', 'display_name' => 'Mo TSA', 'team' => 'Eyecare Team', 'tag_keywords' => 'MO']);

        $this->assertSame('lika', $drive->resolveTsaFolder('t', $lika)['id'] ?? null);
        $this->assertSame('angel', $drive->resolveTsaFolder('t', $angel)['id'] ?? null);
        $this->assertNull($drive->resolveTsaFolder('t', $nobody));
    }

    public function test_recordings_in_any_day_folder_count_for_their_file_name_date(): void
    {
        $this->configureDrive();
        $this->onlyTsa('Eyecare Team', ['tsa_key' => 'Julie', 'display_name' => 'Julie', 'tag_keywords' => 'JULIE']);

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-token']),
            'https://www.googleapis.com/drive/v3/files?q=%27root-eyecare%27*' => Http::response($this->folderListResponse([$this->folder('julie', 'JULIE')])),
            'https://www.googleapis.com/drive/v3/files?q=%27julie%27*' => Http::response($this->folderListResponse([
                $this->folder('day-a', 'whatever'), $this->folder('day-b', 'Nov 11 uploads'),
            ])),
            'https://www.googleapis.com/drive/v3/files?q=%27day-a%27*' => Http::response($this->folderListResponse([
                $this->file('r1', '09171234567 2026-11-11 09-05-00.m4a'), $this->file('r-old', '09171234567 2026-11-10 09-05-00.m4a'),
            ])),
            'https://www.googleapis.com/drive/v3/files?q=%27day-b%27*' => Http::response($this->folderListResponse([
                $this->file('r2', '09179999999 2026-11-11 09-40-00.m4a'),
            ])),
            'https://www.googleapis.com/drive/v3/files/r1*' => Http::response($this->m4a(5)),
            'https://www.googleapis.com/drive/v3/files/r2*' => Http::response($this->m4a(7)),
        ]);

        $this->artisan('calls:sync-recordings', ['--date' => '2026-11-11'])->assertSuccessful();

        $row = CallRecordingHour::where('tsa_key', 'Julie')->whereDate('date', '2026-11-11')->where('hour', 9)->first();
        $this->assertSame(12, $row?->total_seconds);
        $this->assertSame(2, $row?->call_count);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'files/r-old'));
    }
}
