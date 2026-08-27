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

    public function test_index_lists_alias_with_its_facility_name(): void
    {
        FacilityAlias::create([
            'alias_text' => 'ALIAS FARM (Red-Act)',
            'facility_id' => $this->facility->facility_id,
        ]);

        $response = $this->get(route('facility-aliases.index'));

        $response->assertOk();
        $response->assertSee('ALIAS FARM (Red-Act)');
        $response->assertSee('Alias Farm');
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
