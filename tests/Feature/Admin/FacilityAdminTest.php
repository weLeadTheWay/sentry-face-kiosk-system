<?php

namespace Tests\Feature\Admin;

use App\Models\FacilityCategory;
use App\Models\FacilityList;
use App\Models\FacilityType;
use App\Models\IdentityType;
use App\Models\KioskDevice;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserDirectory;
use App\Models\VisitorRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FacilityAdminTest extends TestCase
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

    public function test_index_renders_an_empty_data_table_shell_without_querying_records(): void
    {
        FacilityList::create([
            'facility_type_id' => $this->type->facility_type_id,
            'facility_category_id' => $this->category->facility_category_id,
            'facility_code' => 'TESTFARM',
            'facility_name' => 'Test Farm',
            'is_rtl' => true,
        ]);

        $response = $this->get(route('facilities.index'));

        $response->assertOk();
        $response->assertSee('id="fl-table"', false);
        $response->assertSee('id="fl-filter-btn"', false);
        // The Data Table Shell must not bake any record data into the HTML -
        // that is now the data endpoint's job, fetched via AJAX. (The Type/
        // Category filter dropdowns legitimately list "BVA"/"FARM" as
        // selectable lookup options - that's not the facility record itself.)
        $response->assertDontSee('TESTFARM');
        $response->assertDontSee('Test Farm');
    }

    public function test_data_endpoint_returns_datatables_server_side_envelope(): void
    {
        FacilityList::create([
            'facility_type_id' => $this->type->facility_type_id,
            'facility_category_id' => $this->category->facility_category_id,
            'facility_code' => 'TESTFARM',
            'facility_name' => 'Test Farm',
            'is_rtl' => true,
        ]);

        $response = $this->getJson(route('facilities.data', ['draw' => 2]));

        $response->assertOk();
        $response->assertJsonPath('draw', 2);
        $response->assertJsonPath('recordsTotal', 1);
        $response->assertJsonPath('recordsFiltered', 1);
        $response->assertJsonPath('data.0.facility_code', 'TESTFARM');
        $response->assertJsonPath('data.0.facility_name', 'Test Farm');
        $response->assertJsonPath('data.0.facility_type', 'BVA');
        $response->assertJsonPath('data.0.facility_category', 'FARM');
        $response->assertJsonPath('data.0.is_rtl', true);
    }

    public function test_data_endpoint_paginates_via_start_and_length(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            FacilityList::create([
                'facility_type_id' => $this->type->facility_type_id,
                'facility_category_id' => $this->category->facility_category_id,
                'facility_code' => 'PAGE' . $i,
                'facility_name' => 'Page Facility ' . $i,
            ]);
        }

        $response = $this->getJson(route('facilities.data', ['start' => 0, 'length' => 2]));

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('recordsTotal', 3);
        $response->assertJsonPath('recordsFiltered', 3);

        $secondPage = $this->getJson(route('facilities.data', ['start' => 2, 'length' => 2]));
        $secondPage->assertJsonCount(1, 'data');
    }

    public function test_data_endpoint_filters_by_search_type_category_and_status(): void
    {
        FacilityList::create([
            'facility_type_id' => $this->type->facility_type_id,
            'facility_category_id' => $this->category->facility_category_id,
            'facility_code' => 'ONLINEFARM',
            'facility_name' => 'Online Farm',
            'is_active' => true,
        ]);
        FacilityList::create([
            'facility_type_id' => $this->type->facility_type_id,
            'facility_category_id' => $this->category->facility_category_id,
            'facility_code' => 'OFFLINEFARM',
            'facility_name' => 'Offline Farm',
            'is_active' => false,
        ]);

        $search = $this->getJson(route('facilities.data', ['search' => 'ONLINEFARM']));
        $search->assertJsonPath('recordsFiltered', 1);
        $search->assertJsonPath('data.0.facility_code', 'ONLINEFARM');

        $status = $this->getJson(route('facilities.data', ['status' => 'INACTIVE']));
        $status->assertJsonPath('recordsFiltered', 1);
        $status->assertJsonPath('data.0.facility_code', 'OFFLINEFARM');

        $all = $this->getJson(route('facilities.data', ['status' => 'ALL']));
        $all->assertJsonPath('recordsFiltered', 2);
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

        $this->getJson(route('facilities.data'))->assertForbidden();
    }

    public function test_create_facility_with_valid_data(): void
    {
        $response = $this->post(route('facilities.store'), [
            'facility_code' => 'NEWCODE',
            'facility_name' => 'New Facility',
            'facility_type_id' => $this->type->facility_type_id,
            'facility_category_id' => $this->category->facility_category_id,
            'location' => 'Cebu',
            'is_rtl' => '1',
            'is_active' => '1',
        ]);

        $response->assertOk();
        $facility = FacilityList::where('facility_code', 'NEWCODE')->sole();
        $this->assertEquals('New Facility', $facility->facility_name);
        $this->assertEquals('Cebu', $facility->location);
        $this->assertTrue($facility->is_rtl);
        $this->assertTrue($facility->is_active);
    }

    public function test_create_facility_defaults_is_gs_to_false_when_omitted(): void
    {
        $response = $this->post(route('facilities.store'), [
            'facility_code' => 'NOGSFLAG',
            'facility_name' => 'No GS Flag Facility',
            'facility_type_id' => $this->type->facility_type_id,
            'facility_category_id' => $this->category->facility_category_id,
        ]);

        $response->assertOk();
        $this->assertFalse(FacilityList::where('facility_code', 'NOGSFLAG')->sole()->is_gs);
    }

    public function test_create_and_update_facility_can_set_is_gs(): void
    {
        $response = $this->post(route('facilities.store'), [
            'facility_code' => 'GSCODE',
            'facility_name' => 'GS Facility',
            'facility_type_id' => $this->type->facility_type_id,
            'facility_category_id' => $this->category->facility_category_id,
            'is_gs' => '1',
        ]);
        $response->assertOk();
        $facility = FacilityList::where('facility_code', 'GSCODE')->sole();
        $this->assertTrue($facility->is_gs);

        $update = $this->put(route('facilities.update', $facility), [
            'facility_code' => 'GSCODE',
            'facility_name' => 'GS Facility',
            'facility_type_id' => $this->type->facility_type_id,
            'facility_category_id' => $this->category->facility_category_id,
            'is_gs' => '0',
        ]);
        $update->assertOk();
        $this->assertFalse($facility->fresh()->is_gs);
    }

    public function test_facility_code_must_be_unique(): void
    {
        FacilityList::create([
            'facility_type_id' => $this->type->facility_type_id,
            'facility_category_id' => $this->category->facility_category_id,
            'facility_code' => 'DUPCODE',
            'facility_name' => 'Existing',
        ]);

        $response = $this->post(route('facilities.store'), [
            'facility_code' => 'DUPCODE',
            'facility_name' => 'Another',
            'facility_type_id' => $this->type->facility_type_id,
            'facility_category_id' => $this->category->facility_category_id,
        ]);

        $response->assertSessionHasErrors('facility_code');
        $this->assertEquals(1, FacilityList::where('facility_code', 'DUPCODE')->count());
    }

    public function test_facility_type_and_category_are_required(): void
    {
        $response = $this->post(route('facilities.store'), [
            'facility_code' => 'NOFKS',
            'facility_name' => 'No FKs',
        ]);

        $response->assertSessionHasErrors(['facility_type_id', 'facility_category_id']);
        $this->assertEquals(0, FacilityList::count());
    }

    public function test_facility_type_and_category_must_reference_existing_rows(): void
    {
        $response = $this->post(route('facilities.store'), [
            'facility_code' => 'BADFK',
            'facility_name' => 'Bad FK',
            'facility_type_id' => 999999,
            'facility_category_id' => 999999,
        ]);

        $response->assertSessionHasErrors(['facility_type_id', 'facility_category_id']);
        $this->assertEquals(0, FacilityList::count());
    }

    public function test_edit_form_is_prefilled_with_existing_facility_data(): void
    {
        $facility = FacilityList::create([
            'facility_type_id' => $this->type->facility_type_id,
            'facility_category_id' => $this->category->facility_category_id,
            'facility_code' => 'PREFILL',
            'facility_name' => 'Prefill Facility',
            'location' => 'Manila',
        ]);

        // Matches how the admin panel actually navigates (public/js/admin.js
        // sends X-Requested-With on every .ajax-link click) - a plain,
        // non-AJAX GET to edit()/create() falls back to re-rendering the
        // full index page, whose view expects the paginated list variable
        // these two actions don't provide; that fallback path is shared by
        // every Admin controller in this codebase and is never actually
        // reached in real use, so this test exercises the real path instead.
        $response = $this->get(route('facilities.edit', $facility), ['X-Requested-With' => 'XMLHttpRequest']);

        $response->assertOk();
        $response->assertSee('PREFILL');
        $response->assertSee('Prefill Facility');
        $response->assertSee('Manila');
    }

    public function test_update_facility_changes_fields_and_allows_keeping_own_code(): void
    {
        $facility = FacilityList::create([
            'facility_type_id' => $this->type->facility_type_id,
            'facility_category_id' => $this->category->facility_category_id,
            'facility_code' => 'KEEPCODE',
            'facility_name' => 'Old Name',
        ]);

        $response = $this->put(route('facilities.update', $facility), [
            'facility_code' => 'KEEPCODE',
            'facility_name' => 'Updated Name',
            'facility_type_id' => $this->type->facility_type_id,
            'facility_category_id' => $this->category->facility_category_id,
            'location' => 'Updated Location',
        ]);

        $response->assertOk();
        $facility->refresh();
        $this->assertEquals('Updated Name', $facility->facility_name);
        $this->assertEquals('Updated Location', $facility->location);
    }

    public function test_update_facility_rejects_duplicate_code_from_another_facility(): void
    {
        FacilityList::create([
            'facility_type_id' => $this->type->facility_type_id,
            'facility_category_id' => $this->category->facility_category_id,
            'facility_code' => 'TAKEN',
            'facility_name' => 'Taken',
        ]);
        $facility = FacilityList::create([
            'facility_type_id' => $this->type->facility_type_id,
            'facility_category_id' => $this->category->facility_category_id,
            'facility_code' => 'MINE',
            'facility_name' => 'Mine',
        ]);

        $response = $this->put(route('facilities.update', $facility), [
            'facility_code' => 'TAKEN',
            'facility_name' => 'Mine',
            'facility_type_id' => $this->type->facility_type_id,
            'facility_category_id' => $this->category->facility_category_id,
        ]);

        $response->assertSessionHasErrors('facility_code');
        $this->assertEquals('MINE', $facility->fresh()->facility_code);
    }

    public function test_deactivating_a_facility_does_not_delete_it_or_break_its_kiosk_relationship(): void
    {
        $facility = FacilityList::create([
            'facility_type_id' => $this->type->facility_type_id,
            'facility_category_id' => $this->category->facility_category_id,
            'facility_code' => 'DEACT',
            'facility_name' => 'Deactivate Me',
            'is_active' => true,
        ]);
        $kiosk = KioskDevice::create([
            'facility_id' => $facility->facility_id,
            'device_name' => 'Kiosk 1',
            'serial_number' => 'SN-' . uniqid(),
        ]);

        $response = $this->put(route('facilities.update', $facility), [
            'facility_code' => 'DEACT',
            'facility_name' => 'Deactivate Me',
            'facility_type_id' => $this->type->facility_type_id,
            'facility_category_id' => $this->category->facility_category_id,
            'is_active' => '0',
        ]);

        $response->assertOk();
        $this->assertFalse($facility->fresh()->is_active);
        $this->assertNotNull(KioskDevice::find($kiosk->kiosk_id), 'deactivating a facility must not delete its kiosk');
        $this->assertEquals($facility->facility_id, $kiosk->fresh()->facility_id, 'the kiosk must stay bound to the same facility after deactivation');
    }

    public function test_deleting_a_facility_cascades_to_its_kiosk_devices(): void
    {
        $facility = FacilityList::create([
            'facility_type_id' => $this->type->facility_type_id,
            'facility_category_id' => $this->category->facility_category_id,
            'facility_code' => 'DELCASCADE',
            'facility_name' => 'Delete Cascade',
        ]);
        $kiosk = KioskDevice::create([
            'facility_id' => $facility->facility_id,
            'device_name' => 'Kiosk 1',
            'serial_number' => 'SN-' . uniqid(),
        ]);

        $this->delete(route('facilities.destroy', $facility))->assertOk();

        $this->assertNull(FacilityList::find($facility->facility_id));
        $this->assertNull(KioskDevice::find($kiosk->kiosk_id), 'kiosk_device.facility_id is ON DELETE CASCADE, matching the pre-cutover farm_id behavior');
    }

    public function test_deleting_a_facility_with_an_active_visitor_request_is_blocked(): void
    {
        $identityType = IdentityType::firstOrCreate(['identity_type_name' => 'Visitor']);
        $facility = FacilityList::create([
            'facility_type_id' => $this->type->facility_type_id,
            'facility_category_id' => $this->category->facility_category_id,
            'facility_code' => 'RESTRICTME',
            'facility_name' => 'Restrict Me',
        ]);
        $directory = UserDirectory::create([
            'identity_type_id' => $identityType->identity_type_id,
            'person_reference' => 'restrict+' . uniqid() . '@example.com',
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'full_name' => 'Juan Dela Cruz',
        ]);
        VisitorRequest::create([
            'directory_id' => $directory->directory_id,
            'facility_id' => $facility->facility_id,
            'host_name' => 'Host',
            'visit_datetime' => now(),
            'registration_token' => 'REG_' . Str::upper(Str::random(8)),
            'approval_status' => 'Approved',
        ]);

        $response = $this->delete(route('facilities.destroy', $facility));

        $response->assertStatus(500);
        $this->assertNotNull(FacilityList::find($facility->facility_id), 'the FK RESTRICT must block the delete, same as visitor_request.farm_id did before the cutover');
    }
}
