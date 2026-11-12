<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\TsaShift;
use App\Models\User;
use App\Support\PhpCli;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** 2026-11-12: Drive recordings panel, TSA Drive folder name, and the
 *  php CLI used to start background artisan commands. */
class DriveRecordingsSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_blank_secret_or_refresh_token_keeps_the_saved_value(): void
    {
        $this->actingAs(User::factory()->create());
        Setting::set('drive_client_secret', 'saved-secret');
        Setting::set('drive_refresh_token', 'saved-refresh');
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'ok'])]);

        $this->post(route('settings.drive.save'), [
            'drive_client_id' => 'client-id', 'drive_client_secret' => '', 'drive_refresh_token' => '',
            'drive_folder_sh_naturals' => 'closing-folder', 'drive_folder_eyecare' => 'opening-folder',
        ])->assertSessionHas('success');

        $this->assertSame('saved-secret', Setting::get('drive_client_secret'));
        $this->assertSame('saved-refresh', Setting::get('drive_refresh_token'));
        $this->assertSame('closing-folder', Setting::get('drive_folder_sh_naturals'));
        Http::assertSent(fn ($r) => $r['client_secret'] === 'saved-secret' && $r['refresh_token'] === 'saved-refresh');
    }

    public function test_settings_fields_turn_off_autofill_and_show_the_folder_hint(): void
    {
        $html = $this->actingAs(User::factory()->create())->get(route('settings'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/name="drive_client_id"[^>]*autocomplete="off"[^>]*data-1p-ignore[^>]*data-lpignore="true"/s', $html);
        $this->assertMatchesRegularExpression('/name="drive_client_secret"[^>]*autocomplete="new-password"[^>]*data-1p-ignore/s', $html);
        $this->assertMatchesRegularExpression('/name="drive_refresh_token"[^>]*autocomplete="new-password"[^>]*data-1p-ignore/s', $html);
        $this->assertSame(2, substr_count($html, 'Paste the ID of the team folder that contains one subfolder per TSA.'));
    }

    public function test_the_drive_folder_name_saves_and_clears_in_tsa_management(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->post(route('tsa-management.store'), [
            'display_name' => 'Trocio Lika', 'team' => 'SH Naturals', 'drive_folder_name' => '  LIKA  ',
        ])->assertRedirect();
        $tsa = TsaShift::where('display_name', 'Trocio Lika')->firstOrFail();
        $this->assertSame('LIKA', $tsa->drive_folder_name);

        $this->put(route('tsa-management.update', $tsa), [
            'display_name' => 'Trocio Lika', 'team' => 'SH Naturals', 'drive_folder_name' => 'LIKA 2',
        ])->assertRedirect();
        $this->assertSame('LIKA 2', $tsa->fresh()->drive_folder_name);

        $this->put(route('tsa-management.update', $tsa), [
            'display_name' => 'Trocio Lika', 'team' => 'SH Naturals', 'drive_folder_name' => '',
        ])->assertRedirect();
        $this->assertNull($tsa->fresh()->drive_folder_name);

        $this->get(route('tsa-management'))->assertOk()->assertSee('Drive folder name');
    }

    public function test_the_php_cli_is_never_the_web_server_binary(): void
    {
        $this->assertTrue(PhpCli::isPhpCli('/usr/local/bin/php'));
        $this->assertTrue(PhpCli::isPhpCli('/usr/bin/php8.2'));
        $this->assertFalse(PhpCli::isPhpCli('/usr/local/bin/frankenphp'));
        $this->assertFalse(PhpCli::isPhpCli('/usr/sbin/php-fpm'));
        $this->assertTrue(PhpCli::isPhpCli(PhpCli::binary()) || PhpCli::binary() === 'php' || is_executable(PhpCli::binary()));
    }
}
