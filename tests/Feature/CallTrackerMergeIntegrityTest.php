<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Product;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * New (Phase 9) — not a port. Covers merge-specific behavior that has no
 * call-tracker equivalent, because it only matters once call-tracker's
 * tables/routes live inside tsd-reports' own schema/gating conventions:
 *
 * 1. Lead.tsa_id genuinely resolves against tsa_shifts, not a phantom
 *    "tsas" table (the FK/relation rename called out in Phase 1/4).
 * 2. The merged app's role:super_admin,admin route gating (replacing
 *    call-tracker's own bare isAdmin() check, which excluded super_admin)
 *    lets a super_admin reach a /calls/* admin page.
 * 3. A normal-role user still can't.
 */
class CallTrackerMergeIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_lead_tsa_id_resolves_against_the_real_tsa_shifts_table_not_a_phantom_tsas_table(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        $lead = Lead::create([
            'pancake_order_id' => 'integrity-1', 'customer_name' => 'Integrity Check',
            'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned',
        ]);

        $this->assertInstanceOf(TsaShift::class, $lead->tsa);
        $this->assertSame('Gemma', $lead->tsa->tsa_key);
        $this->assertSame($gemma->id, $lead->tsa->id);
    }

    /**
     * call-tracker's own admin gating (EnsureAdmin, a bare isAdmin() check —
     * not ported, see the merge plan's routing convention) would have
     * excluded super_admin, since that role didn't exist in call-tracker at
     * all. The merged app's routes/web.php instead gates /calls/* admin
     * pages with role:super_admin,admin — confirm a super_admin genuinely
     * gets through, not just admin.
     */
    public function test_a_super_admin_can_reach_a_calls_admin_gated_route(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($superAdmin)->get(route('calls.tsa-management'))->assertOk();
    }

    public function test_a_normal_role_user_cannot_reach_a_calls_admin_gated_route(): void
    {
        $normal = User::factory()->create(['role' => 'normal']);

        $this->actingAs($normal)->get(route('calls.tsa-management'))->assertForbidden();
    }
}
