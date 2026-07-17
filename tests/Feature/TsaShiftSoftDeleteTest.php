<?php

namespace Tests\Feature;

use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TsaShiftSoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_deleting_a_tsa_shift_soft_deletes_it_not_hard_deletes(): void
    {
        $shift = TsaShift::create(['tsa_key' => 'Widget', 'display_name' => 'Widget Agent', 'team' => 'SH Naturals', 'sort_order' => 1]);

        $this->delete(route('tsa-management.destroy', $shift))->assertRedirect();

        $this->assertSoftDeleted('tsa_shifts', ['id' => $shift->id]);
        $this->assertDatabaseHas('tsa_shifts', ['id' => $shift->id]); // row still exists
    }

    public function test_deleted_tsa_shift_does_not_appear_in_the_active_index(): void
    {
        $shift = TsaShift::create(['tsa_key' => 'Widget', 'display_name' => 'Widget Agent', 'team' => 'SH Naturals', 'sort_order' => 1]);
        $shift->delete();

        $response = $this->get(route('tsa-management'));

        $response->assertOk();
        $response->assertViewHas('trashedShifts', function ($trashed) use ($shift) {
            return $trashed->pluck('id')->contains($shift->id);
        });
    }

    public function test_deleted_tsa_shift_does_not_appear_in_team_groups(): void
    {
        $shift = TsaShift::create(['tsa_key' => 'Widget', 'display_name' => 'Widget Agent', 'team' => 'SH Naturals', 'sort_order' => 1]);
        $shift->delete();

        $response = $this->get(route('tsa-management'));

        $response->assertOk();
        $response->assertViewHas('teamGroups', function ($teamGroups) use ($shift) {
            foreach ($teamGroups as $group) {
                if ($group['shifts']->pluck('id')->contains($shift->id)) {
                    return false;
                }
            }
            return true;
        });
    }

    public function test_restoring_a_deleted_tsa_shift_brings_it_back(): void
    {
        $shift = TsaShift::create(['tsa_key' => 'Widget', 'display_name' => 'Widget Agent', 'team' => 'SH Naturals', 'sort_order' => 1]);
        $shift->delete();

        $response = $this->post(route('tsa-management.restore', $shift->id));

        $response->assertRedirect(route('tsa-management'));
        $this->assertDatabaseHas('tsa_shifts', ['id' => $shift->id, 'deleted_at' => null]);
    }

    public function test_restore_route_404s_for_a_tsa_shift_that_was_never_deleted(): void
    {
        $shift = TsaShift::create(['tsa_key' => 'Widget', 'display_name' => 'Widget Agent', 'team' => 'SH Naturals', 'sort_order' => 1]);

        $this->post(route('tsa-management.restore', $shift->id))->assertNotFound();
    }
}
