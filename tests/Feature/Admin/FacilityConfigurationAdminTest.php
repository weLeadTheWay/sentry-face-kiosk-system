<?php

namespace Tests\Feature\Admin;

use App\Models\FacilityCategory;
use App\Models\FacilityList;
use App\Models\FacilityType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FacilityConfigurationAdminTest extends TestCase
{
    use RefreshDatabase;

    private FacilityType $type;
    private FacilityCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAdmin();
        $this->type = FacilityType::create(['facility_type_name' => 'BVA']);
        $this->category = FacilityCategory::create(['facility_category_name' => 'FARM']);
    }

    private function actingAdmin(): User
    {
        $role = Role::create(['role_name' => 'Admin']);
        $permission = Permission::create(['permission_key' => 'facilities.manage', 'permission_name' => 'Manage Facilities']);
        $role->permissions()->attach($permission->permission_id);

        $user = User::create([
            'role_id' => $role->role_id,
            'user_name' => 'admin',
            'user_email' => 'admin@example.com',
            'hash_password' => bcrypt('password'),
            'is_active' => true,
        ]);

        $this->actingAs($user);

        return $user;
    }

    private function makeFacility(string $code): FacilityList
    {
        // fresh() so the DB-level defaults for is_gs/is_break_enabled/
        // is_truck (not passed here) are actually reflected on the
        // returned model - create() doesn't read them back on its own.
        return FacilityList::create([
            'facility_type_id' => $this->type->facility_type_id,
            'facility_category_id' => $this->category->facility_category_id,
            'facility_code' => $code,
            'facility_name' => $code . ' Farm',
            'is_rtl' => true,
        ])->fresh();
    }

    public function test_index_renders_an_empty_data_table_shell_without_querying_records(): void
    {
        $this->makeFacility('TESTFARM');

        $response = $this->get(route('facility-configuration.index'));

        $response->assertOk();
        $response->assertSee('id="fc-table"', false);
        $response->assertSee('id="fc-filter-btn"', false);
        $response->assertDontSee('TESTFARM');
    }

    public function test_data_endpoint_returns_datatables_envelope_with_all_three_toggle_fields(): void
    {
        $facility = $this->makeFacility('TESTFARM');
        $facility->update(['is_gs' => true, 'is_break_enabled' => false, 'is_truck' => true]);

        $response = $this->getJson(route('facility-configuration.data', ['draw' => 3]));

        $response->assertOk();
        $response->assertJsonPath('draw', 3);
        $response->assertJsonPath('recordsTotal', 1);
        $response->assertJsonPath('recordsFiltered', 1);
        $response->assertJsonFragment([
            'facility_id' => $facility->facility_id,
            'facility_code' => 'TESTFARM',
            'facility_name' => 'TESTFARM Farm',
            'is_gs' => true,
            'is_break_enabled' => false,
            'is_truck' => true,
        ]);
    }

    public function test_data_endpoint_filters_by_code_or_name_search(): void
    {
        $this->makeFacility('ALPHA');
        $this->makeFacility('BETA');

        $response = $this->getJson(route('facility-configuration.data', ['search' => 'ALPHA']));

        $response->assertOk();
        $response->assertJsonPath('recordsTotal', 2);
        $response->assertJsonPath('recordsFiltered', 1);
    }

    public function test_update_toggles_is_break_enabled(): void
    {
        $facility = $this->makeFacility('TESTFARM');
        $this->assertTrue($facility->is_break_enabled);

        $response = $this->patchJson(route('facility-configuration.update', $facility), [
            'field' => 'is_break_enabled',
            'value' => false,
        ]);

        $response->assertOk()->assertJson([
            'success' => true,
            'facility_id' => $facility->facility_id,
            'field' => 'is_break_enabled',
            'value' => false,
        ]);
        $this->assertFalse($facility->fresh()->is_break_enabled);
    }

    public function test_update_toggles_is_gs(): void
    {
        $facility = $this->makeFacility('TESTFARM');
        $this->assertFalse($facility->is_gs);

        $response = $this->patchJson(route('facility-configuration.update', $facility), [
            'field' => 'is_gs',
            'value' => true,
        ]);

        $response->assertOk()->assertJson(['success' => true, 'value' => true]);
        $this->assertTrue($facility->fresh()->is_gs);
    }

    public function test_update_toggles_is_truck(): void
    {
        $facility = $this->makeFacility('TESTFARM');
        $this->assertFalse($facility->is_truck);

        $response = $this->patchJson(route('facility-configuration.update', $facility), [
            'field' => 'is_truck',
            'value' => true,
        ]);

        $response->assertOk()->assertJson(['success' => true, 'value' => true]);
        $this->assertTrue($facility->fresh()->is_truck);
    }

    public function test_update_rejects_a_field_name_outside_the_allow_list(): void
    {
        $facility = $this->makeFacility('TESTFARM');

        $response = $this->patchJson(route('facility-configuration.update', $facility), [
            'field' => 'facility_code',
            'value' => true,
        ]);

        $response->assertStatus(422);
        $this->assertEquals('TESTFARM', $facility->fresh()->facility_code);
    }

    public function test_update_requires_facilities_manage_permission(): void
    {
        $facility = $this->makeFacility('TESTFARM');

        $role = Role::create(['role_name' => 'NoPermissions']);
        $user = User::create([
            'role_id' => $role->role_id,
            'user_name' => 'nobody',
            'user_email' => 'nobody@example.com',
            'hash_password' => bcrypt('password'),
            'is_active' => true,
        ]);
        $this->actingAs($user);

        $response = $this->patchJson(route('facility-configuration.update', $facility), [
            'field' => 'is_break_enabled',
            'value' => false,
        ]);

        $response->assertStatus(403);
        $this->assertTrue($facility->fresh()->is_break_enabled);
    }
}
