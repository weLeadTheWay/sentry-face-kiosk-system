<?php

namespace Tests\Feature\Admin;

use App\Models\FacilityAlias;
use App\Models\FacilityCategory;
use App\Models\FacilityList;
use App\Models\FacilityType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\FacilityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FacilityAliasAdminTest extends TestCase
{
    use RefreshDatabase;

    private FacilityList $facility;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAdmin();

        $type = FacilityType::create(['facility_type_name' => 'BVA']);
        $category = FacilityCategory::create(['facility_category_name' => 'FARM']);
        $this->facility = FacilityList::create([
            'facility_type_id' => $type->facility_type_id,
            'facility_category_id' => $category->facility_category_id,
            'facility_code' => 'ALIASFARM',
            'facility_name' => 'Alias Farm',
        ]);
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

    public function test_index_renders_an_empty_data_table_shell_without_querying_records(): void
    {
        FacilityAlias::create([
            'alias_text' => 'ALIAS FARM (Red-Act)',
            'facility_id' => $this->facility->facility_id,
        ]);

        $response = $this->get(route('facility-aliases.index'));

        $response->assertOk();
        // The DataTable shell (headers + filter controls) renders, but the
        // table isn't turned into a jQuery DataTable (and so never calls
        // /data) until the admin clicks Filter - so no alias text ever
        // appears in the initial HTML.
        $response->assertSee('id="fa-table"', false);
        $response->assertSee('id="fa-filter-btn"', false);
        $response->assertDontSee('ALIAS FARM (Red-Act)');
    }

    public function test_index_shell_lists_facilities_for_the_filter_dropdown_only(): void
    {
        FacilityAlias::create([
            'alias_text' => 'ALIAS FARM (Red-Act)',
            'facility_id' => $this->facility->facility_id,
        ]);

        $response = $this->get(route('facility-aliases.index'));

        // The Facility filter dropdown is populated from facility_list
        // directly on page load (small reference data) - that's distinct
        // from loading facility_aliases records, which never happens here.
        $response->assertSee('Alias Farm');
        $response->assertDontSee('ALIAS FARM (Red-Act)');
    }

    public function test_data_endpoint_returns_datatables_server_side_envelope(): void
    {
        FacilityAlias::create([
            'alias_text' => 'ALIAS FARM (Red-Act)',
            'facility_id' => $this->facility->facility_id,
        ]);

        $response = $this->getJson(route('facility-aliases.data', ['draw' => 3]));

        $response->assertOk();
        $response->assertJsonPath('draw', 3);
        $response->assertJsonPath('recordsTotal', 1);
        $response->assertJsonPath('recordsFiltered', 1);
        $response->assertJsonPath('data.0.alias_text', 'ALIAS FARM (Red-Act)');
        $response->assertJsonPath('data.0.facility_name', 'Alias Farm');
    }

    public function test_data_endpoint_with_no_filters_returns_everything_ie_no_filter_is_not_empty(): void
    {
        FacilityAlias::create(['alias_text' => 'One', 'facility_id' => $this->facility->facility_id]);
        FacilityAlias::create(['alias_text' => 'Two', 'facility_id' => $this->facility->facility_id]);

        $response = $this->getJson(route('facility-aliases.data'));

        $response->assertOk();
        $response->assertJsonPath('recordsTotal', 2);
        $response->assertJsonPath('recordsFiltered', 2);
        $response->assertJsonCount(2, 'data');
    }

    public function test_data_endpoint_facility_id_all_is_equivalent_to_no_filter(): void
    {
        FacilityAlias::create(['alias_text' => 'One', 'facility_id' => $this->facility->facility_id]);

        $response = $this->getJson(route('facility-aliases.data', ['facility_id' => 'ALL']));

        $response->assertOk();
        $response->assertJsonPath('recordsFiltered', 1);
    }

    public function test_data_endpoint_filters_by_facility_id(): void
    {
        $otherFacility = FacilityList::create([
            'facility_type_id' => $this->facility->facility_type_id,
            'facility_category_id' => $this->facility->facility_category_id,
            'facility_code' => 'OTHERFARM',
            'facility_name' => 'Other Farm',
        ]);
        FacilityAlias::create(['alias_text' => 'Mine', 'facility_id' => $this->facility->facility_id]);
        FacilityAlias::create(['alias_text' => 'TheirsToo', 'facility_id' => $otherFacility->facility_id]);

        $response = $this->getJson(route('facility-aliases.data', ['facility_id' => $this->facility->facility_id]));

        $response->assertOk();
        $response->assertJsonPath('recordsTotal', 2);
        $response->assertJsonPath('recordsFiltered', 1);
        $response->assertJsonPath('data.0.alias_text', 'Mine');
    }

    public function test_data_endpoint_filters_by_alias_text_contains(): void
    {
        FacilityAlias::create(['alias_text' => 'Saturn Farm (Green)', 'facility_id' => $this->facility->facility_id]);
        FacilityAlias::create(['alias_text' => 'Venus Farm (Red)', 'facility_id' => $this->facility->facility_id]);

        $response = $this->getJson(route('facility-aliases.data', ['alias_text' => 'Green']));

        $response->assertOk();
        $response->assertJsonPath('recordsFiltered', 1);
        $response->assertJsonPath('data.0.alias_text', 'Saturn Farm (Green)');
    }

    public function test_data_endpoint_paginates_via_start_and_length(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            FacilityAlias::create(['alias_text' => 'Alias ' . $i, 'facility_id' => $this->facility->facility_id]);
        }

        $response = $this->getJson(route('facility-aliases.data', ['start' => 0, 'length' => 2]));
        $response->assertOk();
        $response->assertJsonPath('recordsFiltered', 5);
        $response->assertJsonCount(2, 'data');

        $secondPage = $this->getJson(route('facility-aliases.data', ['start' => 2, 'length' => 2]));
        $secondPage->assertJsonCount(2, 'data');
        $this->assertNotSame(
            $response->json('data.0.alias_id'),
            $secondPage->json('data.0.alias_id'),
            'different pages must return different rows'
        );
    }

    public function test_data_endpoint_orders_descending_by_alias_text_when_requested(): void
    {
        FacilityAlias::create(['alias_text' => 'Alpha', 'facility_id' => $this->facility->facility_id]);
        FacilityAlias::create(['alias_text' => 'Beta', 'facility_id' => $this->facility->facility_id]);

        $response = $this->getJson(route('facility-aliases.data', [
            'order' => [['column' => 0, 'dir' => 'desc']],
        ]));

        $response->assertOk();
        $response->assertJsonPath('data.0.alias_text', 'Beta');
        $response->assertJsonPath('data.1.alias_text', 'Alpha');
    }

    public function test_data_endpoint_requires_permission(): void
    {
        $role = Role::create(['role_name' => 'NoPermissions']);
        $user = User::create([
            'role_id' => $role->role_id,
            'user_name' => 'nobody',
            'user_email' => 'nobody@example.com',
            'hash_password' => bcrypt('password'),
            'is_active' => true,
        ]);
        $this->actingAs($user);

        $this->getJson(route('facility-aliases.data'))->assertForbidden();
    }

    public function test_create_alias_for_existing_facility(): void
    {
        $response = $this->post(route('facility-aliases.store'), [
            'alias_text' => 'Alias Farm (Alt)',
            'facility_id' => $this->facility->facility_id,
        ]);

        $response->assertOk();
        $alias = FacilityAlias::where('alias_text', 'Alias Farm (Alt)')->sole();
        $this->assertEquals($this->facility->facility_id, $alias->facility_id);
    }

    public function test_alias_text_must_be_unique(): void
    {
        FacilityAlias::create(['alias_text' => 'DUP ALIAS', 'facility_id' => $this->facility->facility_id]);

        $response = $this->post(route('facility-aliases.store'), [
            'alias_text' => 'DUP ALIAS',
            'facility_id' => $this->facility->facility_id,
        ]);

        $response->assertSessionHasErrors('alias_text');
        $this->assertEquals(1, FacilityAlias::where('alias_text', 'DUP ALIAS')->count());
    }

    public function test_facility_id_must_reference_an_existing_facility(): void
    {
        $response = $this->post(route('facility-aliases.store'), [
            'alias_text' => 'Ghost Alias',
            'facility_id' => 999999,
        ]);

        $response->assertSessionHasErrors('facility_id');
        $this->assertEquals(0, FacilityAlias::count());
    }

    public function test_update_alias_can_repoint_to_a_different_facility(): void
    {
        $otherFacility = FacilityList::create([
            'facility_type_id' => $this->facility->facility_type_id,
            'facility_category_id' => $this->facility->facility_category_id,
            'facility_code' => 'OTHERFARM',
            'facility_name' => 'Other Farm',
        ]);
        $alias = FacilityAlias::create(['alias_text' => 'Movable Alias', 'facility_id' => $this->facility->facility_id]);

        $response = $this->put(route('facility-aliases.update', $alias), [
            'alias_text' => 'Movable Alias',
            'facility_id' => $otherFacility->facility_id,
        ]);

        $response->assertOk();
        $this->assertEquals($otherFacility->facility_id, $alias->fresh()->facility_id);
    }

    public function test_update_alias_allows_keeping_its_own_text(): void
    {
        $alias = FacilityAlias::create(['alias_text' => 'Keep Me', 'facility_id' => $this->facility->facility_id]);

        $response = $this->put(route('facility-aliases.update', $alias), [
            'alias_text' => 'Keep Me',
            'facility_id' => $this->facility->facility_id,
        ]);

        $response->assertOk();
    }

    public function test_delete_alias_does_not_delete_the_facility(): void
    {
        $alias = FacilityAlias::create(['alias_text' => 'Delete Me', 'facility_id' => $this->facility->facility_id]);

        $this->delete(route('facility-aliases.destroy', $alias))->assertOk();

        $this->assertNull(FacilityAlias::find($alias->alias_id));
        $this->assertNotNull(FacilityList::find($this->facility->facility_id));
    }

    public function test_facility_resolver_resolves_via_an_alias_created_through_this_admin_screen(): void
    {
        FacilityAlias::create([
            'alias_text' => 'Alias Farm (Red-Act)',
            'facility_id' => $this->facility->facility_id,
        ]);

        $resolved = (new FacilityResolver())->resolve('Alias Farm (Red-Act)');

        $this->assertNotNull($resolved);
        $this->assertEquals($this->facility->facility_id, $resolved->facility_id);
    }
}
