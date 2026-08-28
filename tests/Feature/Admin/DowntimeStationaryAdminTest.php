<?php

namespace Tests\Feature\Admin;

use App\Models\DowntimeStationary;
use App\Models\FacilityList;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesFacilities;
use Tests\TestCase;

class DowntimeStationaryAdminTest extends TestCase
{
    use RefreshDatabase;
    use CreatesFacilities;

    private FacilityList $facility;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAdmin();
        $this->facility = $this->createFacility('ALPHA');
    }

    private function actingAdmin(): User
    {
        $role = Role::create(['role_name' => 'Admin']);
        $permission = Permission::create(['permission_key' => 'biosecurity.manage', 'permission_name' => 'Manage Biosecurity Rules']);
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

    public function test_index_renders_an_empty_data_table_shell_without_querying_records(): void
    {
        DowntimeStationary::create([
            'assigned_facility_id' => $this->facility->facility_id,
            'minimum_downtime' => 12,
            'maximum_downtime' => 24,
        ]);

        $response = $this->get(route('downtime-stationary.index'));

        $response->assertOk();
        $response->assertSee('id="ds-table"', false);
        $response->assertSee('id="ds-filter-btn"', false);
        // The Assigned Facility filter dropdown legitimately lists ALPHA as a
        // selectable facility (small lookup data) - what must NOT appear is
        // the rule record itself, i.e. an empty <tbody>.
        $response->assertSee('<tbody></tbody>', false);
    }

    public function test_data_endpoint_returns_rule_with_facility_name(): void
    {
        DowntimeStationary::create([
            'assigned_facility_id' => $this->facility->facility_id,
            'minimum_downtime' => 12,
            'maximum_downtime' => 24,
        ]);

        $response = $this->getJson(route('downtime-stationary.data'));

        $response->assertOk();
        $response->assertJsonPath('data.0.assigned_facility', 'ALPHA');
        $response->assertJsonPath('data.0.minimum_downtime', 12);
        $response->assertJsonPath('data.0.maximum_downtime', 24);
    }

    public function test_create_rule_with_valid_data(): void
    {
        $response = $this->post(route('downtime-stationary.store'), [
            'assigned_facility_id' => $this->facility->facility_id,
            'minimum_downtime' => 8,
            'maximum_downtime' => 16,
        ]);

        $response->assertOk();
        $rule = DowntimeStationary::sole();
        $this->assertEquals($this->facility->facility_id, $rule->assigned_facility_id);
        $this->assertEquals(8.00, (float) $rule->minimum_downtime);
        $this->assertEquals(16.00, (float) $rule->maximum_downtime);
    }

    public function test_assigned_facility_must_be_unique(): void
    {
        DowntimeStationary::create(['assigned_facility_id' => $this->facility->facility_id]);

        $response = $this->post(route('downtime-stationary.store'), [
            'assigned_facility_id' => $this->facility->facility_id,
        ]);

        $response->assertSessionHasErrors('assigned_facility_id');
        $this->assertEquals(1, DowntimeStationary::count());
    }

    public function test_assigned_facility_must_reference_an_existing_facility(): void
    {
        $response = $this->post(route('downtime-stationary.store'), [
            'assigned_facility_id' => 999999,
        ]);

        $response->assertSessionHasErrors('assigned_facility_id');
        $this->assertEquals(0, DowntimeStationary::count());
    }

    public function test_update_rule_changes_downtime_values_and_allows_keeping_own_facility(): void
    {
        $rule = DowntimeStationary::create([
            'assigned_facility_id' => $this->facility->facility_id,
            'minimum_downtime' => 5,
            'maximum_downtime' => 10,
        ]);

        $response = $this->put(route('downtime-stationary.update', $rule), [
            'assigned_facility_id' => $this->facility->facility_id,
            'minimum_downtime' => 7,
            'maximum_downtime' => 14,
        ]);

        $response->assertOk();
        $rule->refresh();
        $this->assertEquals(7.00, (float) $rule->minimum_downtime);
        $this->assertEquals(14.00, (float) $rule->maximum_downtime);
    }

    public function test_update_rule_rejects_duplicate_facility_from_another_rule(): void
    {
        $other = $this->createFacility('BETA');
        DowntimeStationary::create(['assigned_facility_id' => $this->facility->facility_id]);
        $rule = DowntimeStationary::create(['assigned_facility_id' => $other->facility_id]);

        $response = $this->put(route('downtime-stationary.update', $rule), [
            'assigned_facility_id' => $this->facility->facility_id,
        ]);

        $response->assertSessionHasErrors('assigned_facility_id');
        $this->assertEquals($other->facility_id, $rule->fresh()->assigned_facility_id);
    }

    public function test_delete_rule_does_not_delete_the_facility(): void
    {
        $rule = DowntimeStationary::create(['assigned_facility_id' => $this->facility->facility_id]);

        $this->delete(route('downtime-stationary.destroy', $rule))->assertOk();

        $this->assertNull(DowntimeStationary::find($rule->rule_id));
        $this->assertNotNull(FacilityList::find($this->facility->facility_id));
    }

    public function test_deleting_a_facility_cascades_to_its_downtime_stationary_rule(): void
    {
        $rule = DowntimeStationary::create(['assigned_facility_id' => $this->facility->facility_id]);

        $this->facility->delete();

        $this->assertNull(DowntimeStationary::find($rule->rule_id), 'downtime_stationary.assigned_facility_id is ON DELETE CASCADE, matching the pre-cutover farm_id behavior');
    }
}
