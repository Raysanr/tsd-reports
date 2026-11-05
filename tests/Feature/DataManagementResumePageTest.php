<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\DataSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Coming back from the Hub resumes the last Data Management page. */
class DataManagementResumePageTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        DataSet::reset(); // a /partners request switches the connection for the process
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    public function test_module_home_opens_projections_on_first_visit(): void
    {
        $this->actingAs($this->admin())->get('/data')->assertRedirect('/data/projections');
    }

    public function test_module_home_resumes_the_last_page_with_its_filters(): void
    {
        $this->actingAs($this->admin())
            ->withHeader('Sec-Fetch-Dest', 'document')
            ->get('/data/activity-log?page=1')
            ->assertOk();

        $this->get('/data')->assertRedirect('/data/activity-log?page=1');
    }

    public function test_background_fetches_are_not_remembered(): void
    {
        $this->actingAs($this->admin())
            ->withHeader('Sec-Fetch-Dest', 'empty')
            ->get('/data/activity-log')
            ->assertOk();

        $this->get('/data')->assertRedirect('/data/projections');
    }

    public function test_each_data_set_remembers_its_own_page(): void
    {
        $this->actingAs($this->admin())
            ->withHeader('Sec-Fetch-Dest', 'document')
            ->get('/data/activity-log')
            ->assertOk();

        $this->get('/partners')->assertRedirect('/partners/projections');
    }
}
