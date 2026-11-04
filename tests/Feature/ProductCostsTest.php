<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ProductCost;
use App\Models\User;
use App\Support\DataSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductCostsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        DataSet::reset();
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_sheet_rows_are_seeded_and_listed(): void
    {
        $this->assertSame(160, ProductCost::count());
        $this->assertSame(30.0, ProductCost::where('variation', '1 Golden Buddha Oil')->value('cost'));
        $this->assertSame('1 Golden Buddha Oil', ProductCost::orderBy('id')->value('variation'));

        $this->actingAs($this->admin())->get(route('data.product-costs'))
            ->assertOk()
            ->assertSee('1 Scar Cream')
            ->assertSee('Product Costs');
    }

    public function test_conflicting_sheet_duplicates_keep_the_first_row(): void
    {
        $this->assertSame(81.0, ProductCost::where('variation', '1 Lumicare Oil + 1 Haplunas Healing Eye Cream - (PT)')->value('cost'));
        $this->assertSame(65.0, ProductCost::where('variation', '1Sinuxyl Inhaler + 1 Sinuxyl')->value('cost'));
    }

    public function test_page_is_admin_only(): void
    {
        $tsa = User::factory()->create(['role' => 'normal']);

        $this->actingAs($tsa)->get(route('data.product-costs'))->assertForbidden();
        $this->actingAs($tsa)->get(route('data.projections'))->assertOk()->assertDontSee('Product Costs');
    }

    public function test_admin_can_add_edit_and_remove_a_row(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('data.product-costs.store'), ['variation' => '9 Test Oil', 'cost' => 12.5])
            ->assertRedirect(route('data.product-costs'));
        $row = ProductCost::where('variation', '9 Test Oil')->firstOrFail();

        $this->patchJson(route('data.product-costs.update', $row), ['cost' => 20])->assertOk();
        $this->assertSame(20.0, $row->fresh()->cost);

        $this->patchJson(route('data.product-costs.update', $row), ['variation' => '9 Renamed Oil'])->assertOk();
        $this->assertSame('9 Renamed Oil', $row->fresh()->variation);

        $this->deleteJson(route('data.product-costs.destroy', $row))->assertOk();
        $this->assertNull(ProductCost::find($row->id));

        $this->assertSame(4, ActivityLog::where('action', 'like', 'product_costs.%')->count());
    }

    public function test_invalid_values_are_rejected(): void
    {
        $this->actingAs($this->admin());
        $row = ProductCost::where('variation', '1 Clear Sight')->firstOrFail();

        $this->patchJson(route('data.product-costs.update', $row), ['cost' => -1])->assertUnprocessable();
        $this->patchJson(route('data.product-costs.update', $row), ['cost' => ''])->assertUnprocessable();
        $this->patchJson(route('data.product-costs.update', $row), ['variation' => '1 Audicure'])->assertUnprocessable();
        $this->patchJson(route('data.product-costs.update', $row), ['variation' => '1 Clear Sight'])->assertOk();
    }

    public function test_a_locked_table_refuses_every_write(): void
    {
        $this->actingAs($this->admin());
        $row = ProductCost::where('variation', '1 Clear Sight')->firstOrFail();

        $this->patchJson(route('data.product-costs.toggle-lock'), ['locked' => '1'])->assertOk();

        $this->patchJson(route('data.product-costs.update', $row), ['cost' => 99])->assertStatus(423);
        $this->deleteJson(route('data.product-costs.destroy', $row))->assertStatus(423);
        $this->postJson(route('data.product-costs.store'), ['variation' => 'X', 'cost' => 1])->assertStatus(423);
        $this->assertSame(30.0, $row->fresh()->cost);

        $this->patchJson(route('data.product-costs.toggle-lock'), ['locked' => '0'])->assertOk();
        $this->patchJson(route('data.product-costs.update', $row), ['cost' => 99])->assertOk();
    }

    public function test_tsas_do_not_see_product_cost_activity(): void
    {
        ActivityLog::create(['action' => 'product_costs.added', 'description' => 'Added secret margin row']);

        $this->actingAs(User::factory()->create(['role' => 'normal']))
            ->get(route('data.activity-log'))->assertOk()->assertDontSee('secret margin row');
        $this->actingAs($this->admin())
            ->get(route('data.activity-log'))->assertOk()->assertSee('secret margin row');
    }

    public function test_partners_starts_empty_and_keeps_its_own_rows(): void
    {
        $this->artisan('migrate', ['--database' => DataSet::PARTNERS]);
        $this->actingAs($this->admin());

        $this->get('/partners/product-costs')->assertOk()->assertSee('No product costs yet.');
        $this->post('/partners/product-costs', ['variation' => 'Partner Item', 'cost' => 5])->assertRedirect();

        $this->assertSame(1, ProductCost::count());

        DataSet::reset();
        $this->assertSame(160, ProductCost::count());
        $this->assertNull(ProductCost::where('variation', 'Partner Item')->first());
    }
}
