<?php

namespace Tests\Unit;

use App\Models\FacilityList;
use App\Models\IdentityType;
use App\Models\UserDirectory;
use App\Models\VisitorProfile;
use App\Models\VisitorRequest;
use App\Models\VisitorType;
use App\Services\FacilityResolver;
use App\Services\VisitorSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesFacilities;
use Tests\TestCase;

class VisitorSyncServiceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesFacilities;

    private VisitorSyncService $service;
    private FacilityList $facility;
    private VisitorType $visitorType;

    protected function setUp(): void
    {
        parent::setUp();

        IdentityType::create(['identity_type_name' => 'Visitor']);
        $this->visitorType = VisitorType::create(['visitor_type_name' => 'Visitor']);
        $this->facility = $this->createFacility('ALPHA');

        $this->service = new VisitorSyncService(new FacilityResolver());
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Juan',
            'middle_name' => null,
            'last_name' => 'Dela Cruz',
            'email' => 'juan@example.com',
            'farm' => 'ALPHA',
            'host_name' => 'Maria Santos',
            'visit_datetime' => now()->format('Y-m-d H:i:s'),
            'visitor_id' => 'VIS-' . uniqid(),
            'qr_url' => 'https://example.com/qr.png',
        ], $overrides);
    }

    public function test_case1_same_name_and_email_reuses_existing_directory(): void
    {
        $first = $this->service->syncApprovedRequest($this->payload(['visitor_id' => 'VIS-1']));
        $this->assertTrue($first['success']);

        $second = $this->service->syncApprovedRequest($this->payload(['visitor_id' => 'VIS-2']));
        $this->assertTrue($second['success']);

        $this->assertEquals(
            $first['visitor_request']->directory_id,
            $second['visitor_request']->directory_id
        );
        $this->assertEquals(1, UserDirectory::count());
    }

    public function test_case2_same_name_different_email_creates_new_directory(): void
    {
        $first = $this->service->syncApprovedRequest($this->payload([
            'visitor_id' => 'VIS-3', 'email' => 'juan@example.com',
        ]));
        $second = $this->service->syncApprovedRequest($this->payload([
            'visitor_id' => 'VIS-4', 'email' => 'juan.delacruz@example.com',
        ]));

        $this->assertNotEquals(
            $first['visitor_request']->directory_id,
            $second['visitor_request']->directory_id
        );
        $this->assertEquals(2, UserDirectory::count());
    }

    public function test_case3_different_name_same_email_creates_new_directory(): void
    {
        $first = $this->service->syncApprovedRequest($this->payload([
            'visitor_id' => 'VIS-5', 'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'email' => 'shared@example.com',
        ]));
        $second = $this->service->syncApprovedRequest($this->payload([
            'visitor_id' => 'VIS-6', 'first_name' => 'Maria', 'last_name' => 'Santos', 'email' => 'shared@example.com',
        ]));

        $this->assertNotEquals(
            $first['visitor_request']->directory_id,
            $second['visitor_request']->directory_id
        );
        $this->assertEquals(2, UserDirectory::count());
    }

    public function test_case4_different_name_and_email_creates_new_directory(): void
    {
        $first = $this->service->syncApprovedRequest($this->payload(['visitor_id' => 'VIS-7']));
        $second = $this->service->syncApprovedRequest($this->payload([
            'visitor_id' => 'VIS-8', 'first_name' => 'Pedro', 'last_name' => 'Reyes', 'email' => 'pedro@example.com',
        ]));

        $this->assertNotEquals(
            $first['visitor_request']->directory_id,
            $second['visitor_request']->directory_id
        );
        $this->assertEquals(2, UserDirectory::count());
    }

    public function test_duplicate_visitor_id_is_idempotent_and_does_not_duplicate_request(): void
    {
        $first = $this->service->syncApprovedRequest($this->payload(['visitor_id' => 'VIS-DUPE']));
        $second = $this->service->syncApprovedRequest($this->payload(['visitor_id' => 'VIS-DUPE']));

        $this->assertEquals($first['registration_token'], $second['registration_token']);
        $this->assertEquals(1, VisitorRequest::where('visitor_id', 'VIS-DUPE')->count());
    }

    public function test_visitor_id_is_stored_exactly_as_received_no_prefix(): void
    {
        $result = $this->service->syncApprovedRequest($this->payload(['visitor_id' => '08/06/2026-Louisa Reighn Alejo Santos-KLM012']));

        // Must be stored verbatim - no SNTRY- or any other modification.
        // Only Login ID / Logout ID (separate, per-visitor_session values)
        // carry that prefix - see VisitorSheetWriter::formatSentryId().
        $this->assertEquals('08/06/2026-Louisa Reighn Alejo Santos-KLM012', $result['visitor_request']->visitor_id);
    }

    public function test_visitor_id_that_already_contains_sntry_is_preserved_unmodified(): void
    {
        // If AppSheet ever happens to send a value that itself starts with
        // "SNTRY-", it must still be stored exactly as received - the sync
        // service must never inspect or alter visitor_id in any way.
        $result = $this->service->syncApprovedRequest($this->payload(['visitor_id' => 'SNTRY-ALREADY-IN-PAYLOAD']));

        $this->assertEquals('SNTRY-ALREADY-IN-PAYLOAD', $result['visitor_request']->visitor_id);
    }

    public function test_appsheet_date_formats_are_parsed_correctly(): void
    {
        $result = $this->service->syncApprovedRequest($this->payload([
            'visitor_id' => 'VIS-DATE1',
            'visit_datetime' => '8/6/2026 14:00:00',
            'departure_datetime' => '08/06/2026 17:00:00',
        ]));

        $this->assertTrue($result['success']);
        $this->assertEquals('2026-08-06 14:00:00', $result['visitor_request']->visit_datetime->toDateTimeString());
        $this->assertEquals('2026-08-06 17:00:00', $result['visitor_request']->departure_datetime->toDateTimeString());
    }

    public function test_unresolvable_farm_fails_without_creating_any_rows(): void
    {
        $result = $this->service->syncApprovedRequest($this->payload([
            'visitor_id' => 'VIS-NOFARM', 'farm' => 'DOES NOT EXIST',
        ]));

        $this->assertFalse($result['success']);
        $this->assertEquals(0, UserDirectory::count());
        $this->assertEquals(0, VisitorRequest::count());
    }

    public function test_middle_name_is_persisted_and_used_in_full_name(): void
    {
        $result = $this->service->syncApprovedRequest($this->payload([
            'visitor_id' => 'VIS-MID', 'middle_name' => 'Reyes',
        ]));

        $directory = UserDirectory::find($result['visitor_request']->directory_id);
        $this->assertEquals('Reyes', $directory->middle_name);
        $this->assertEquals('Juan Reyes Dela Cruz', $directory->full_name);
    }

    public function test_new_directory_gets_visitor_type_id_set(): void
    {
        // visitor_type_id lives on visitor_profile now, not user_directory -
        // relocated here to match, not weakened (see Step 2 of the ERD
        // restructure).
        $result = $this->service->syncApprovedRequest($this->payload(['visitor_id' => 'VIS-VTYPE']));

        $directory = UserDirectory::find($result['visitor_request']->directory_id);
        $this->assertEquals($this->visitorType->visitor_type_id, $directory->visitorProfile->visitor_type_id);
        $this->assertEquals('Visitor', $directory->visitorProfile->visitorType->visitor_type_name);
    }

    public function test_reused_existing_directory_is_not_modified_by_visitor_type_assignment(): void
    {
        // Same name+email on both syncs reuses the same directory (Case 1).
        // If that existing directory's visitor_profile.visitor_type_id was
        // ever manually changed by an admin, a later sync call must never
        // silently overwrite it back - and must never create a second
        // profile for the reused directory either.
        $first = $this->service->syncApprovedRequest($this->payload(['visitor_id' => 'VIS-REUSE-1']));
        $directory = UserDirectory::find($first['visitor_request']->directory_id);
        $directory->visitorProfile->update(['visitor_type_id' => null]);

        $this->service->syncApprovedRequest($this->payload(['visitor_id' => 'VIS-REUSE-2']));

        $this->assertNull($directory->visitorProfile->fresh()->visitor_type_id);
        $this->assertEquals(1, VisitorProfile::where('directory_id', $directory->directory_id)->count());
    }

    public function test_missing_visitor_type_fails_sync_without_creating_any_rows(): void
    {
        VisitorType::where('visitor_type_name', 'Visitor')->delete();

        $result = $this->service->syncApprovedRequest($this->payload(['visitor_id' => 'VIS-NOTYPE']));

        $this->assertFalse($result['success']);
        $this->assertEquals(0, UserDirectory::count());
        $this->assertEquals(0, VisitorRequest::count());
        $this->assertEquals(0, VisitorProfile::count());
    }
}
