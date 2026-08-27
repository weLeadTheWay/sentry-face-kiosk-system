<?php

namespace Tests\Feature\Admin;

use App\Models\DowntimeMatrix;
use App\Models\FacilityList;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesFacilities;
use Tests\TestCase;

class DowntimeMatrixAdminTest extends TestCase
{
    use RefreshDatabase;
    use CreatesFacilities;

    private FacilityList $origin;
    private FacilityList $destination;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAdmin();
        $this->origin = $this->createFacility('ALPHA');
        $this->destination = $this->createFacility('BETA');
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

    public function test_index_lists_existing_rule_with_facility_names(): void
    {
        DowntimeMatrix::create([
            'origin_facility_id' => $this->origin->facility_id,
            'destination_facility_id' => $this->destination->facility_id,
            'minimum_downtime' => 12,
            'maximum_downtime' => 24,
        ]);

        $response = $this->get(route('downtime-matrix.index'));

        $response->assertOk();
        $response->assertSee('ALPHA');
        $response->assertSee('BETA');
    }

    public function test_create_rule_with_valid_data(): void
    {
        $response = $this->post(route('downtime-matrix.store'), [
            'origin_facility_id' => $this->origin->facility_id,
            'destination_facility_id' => $this->destination->facility_id,
            'minimum_downtime' => 13,
            'maximum_downtime' => 26,
        ]);

        $response->assertOk();
        $rule = DowntimeMatrix::sole();
        $this->assertEquals($this->origin->facility_id, $rule->origin_facility_id);
        $this->assertEquals($this->destination->facility_id, $rule->destination_facility_id);
        $this->assertEquals(13.00, (float) $rule->minimum_downtime);
        $this->assertEquals(26.00, (float) $rule->maximum_downtime);
    }

    public function test_origin_destination_pair_must_be_unique(): void
    {
        DowntimeMatrix::create([
            'origin_facility_id' => $this->origin->facility_id,
            'destination_facility_id' => $this->destination->facility_id,
        ]);

        $response = $this->post(route('downtime-matrix.store'), [
            'origin_facility_id' => $this->origin->facility_id,
            'destination_facility_id' => $this->destination->facility_id,
        ]);

        $response->assertSessionHasErrors('origin_facility_id');
        $this->assertEquals(1, DowntimeMatrix::count());
    }

    public function test_reversed_pair_is_a_distinct_rule_not_a_duplicate(): void
    {
        // Preserves the pre-existing uniqueness semantics: (origin, dest) and
        // (dest, origin) are different pairs, not the same rule reversed.
        DowntimeMatrix::create([
            'origin_facility_id' => $this->origin->facility_id,
            'destination_facility_id' => $this->destination->facility_id,
        ]);

        $response = $this->post(route('downtime-matrix.store'), [
            'origin_facility_id' => $this->destination->facility_id,
            'destination_facility_id' => $this->origin->facility_id,
        ]);

        $response->assertOk();
        $this->assertEquals(2, DowntimeMatrix::count());
    }

    public function test_facility_ids_must_reference_existing_facilities(): void
    {
        $response = $this->post(route('downtime-matrix.store'), [
            'origin_facility_id' => 999999,
            'destination_facility_id' => 999998,
        ]);

        $response->assertSessionHasErrors(['origin_facility_id', 'destination_facility_id']);
        $this->assertEquals(0, DowntimeMatrix::count());
    }

    public function test_update_rule_changes_downtime_values_and_allows_keeping_own_pair(): void
    {
        $rule = DowntimeMatrix::create([
            'origin_facility_id' => $this->origin->facility_id,
            'destination_facility_id' => $this->destination->facility_id,
            'minimum_downtime' => 10,
            'maximum_downtime' => 20,
        ]);

        $response = $this->put(route('downtime-matrix.update', $rule), [
            'origin_facility_id' => $this->origin->facility_id,
            'destination_facility_id' => $this->destination->facility_id,
            'minimum_downtime' => 15,
            'maximum_downtime' => 30,
        ]);

        $response->assertOk();
        $rule->refresh();
        $this->assertEquals(15.00, (float) $rule->minimum_downtime);
        $this->assertEquals(30.00, (float) $rule->maximum_downtime);
    }

    public function test_update_rule_rejects_duplicate_pair_from_another_rule(): void
    {
        $third = $this->createFacility('GAMMA');
        DowntimeMatrix::create([
            'origin_facility_id' => $this->origin->facility_id,
            'destination_facility_id' => $this->destination->facility_id,
        ]);
        $rule = DowntimeMatrix::create([
            'origin_facility_id' => $this->origin->facility_id,
            'destination_facility_id' => $third->facility_id,
        ]);

        $response = $this->put(route('downtime-matrix.update', $rule), [
            'origin_facility_id' => $this->origin->facility_id,
            'destination_facility_id' => $this->destination->facility_id,
        ]);

        $response->assertSessionHasErrors('origin_facility_id');
        $this->assertEquals($third->facility_id, $rule->fresh()->destination_facility_id);
    }

    public function test_delete_rule_does_not_delete_the_facilities(): void
    {
        $rule = DowntimeMatrix::create([
            'origin_facility_id' => $this->origin->facility_id,
            'destination_facility_id' => $this->destination->facility_id,
        ]);

        $this->delete(route('downtime-matrix.destroy', $rule))->assertOk();

        $this->assertNull(DowntimeMatrix::find($rule->rule_id));
        $this->assertNotNull(FacilityList::find($this->origin->facility_id));
        $this->assertNotNull(FacilityList::find($this->destination->facility_id));
    }

    public function test_deleting_a_facility_cascades_to_its_downtime_matrix_rules(): void
    {
        $rule = DowntimeMatrix::create([
            'origin_facility_id' => $this->origin->facility_id,
            'destination_facility_id' => $this->destination->facility_id,
        ]);

        $this->origin->delete();

        $this->assertNull(DowntimeMatrix::find($rule->rule_id), 'downtime_matrix.origin_facility_id is ON DELETE CASCADE, matching the pre-cutover farm_id behavior');
    }
}
