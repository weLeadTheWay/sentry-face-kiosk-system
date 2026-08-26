<?php

namespace Tests\Feature\Kiosk;

use App\Models\FaceProfile;
use App\Models\FarmList;
use App\Models\IdentityType;
use App\Models\KioskDevice;
use App\Models\UserDirectory;
use App\Models\VisitorEntryLog;
use App\Models\VisitorProfile;
use App\Models\VisitorRequest;
use App\Models\VisitorSession;
use App\Models\VisitorType;
use App\Services\GoogleSheets\VisitorSheetWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Truck reuses the entire Gatesale implementation (KioskGatesaleFlowTest
 * covers the shared machinery in depth) - this file focuses on what's
 * actually different for Truck: Plate No. requirement/display/editing, and
 * confirming the shared code paths behave identically when the directory's
 * visitor_type is Truck instead of Gatesale.
 */
class KioskTruckFlowTest extends TestCase
{
    use RefreshDatabase;

    private KioskDevice $kiosk;
    private FarmList $farm;
    private IdentityType $visitorIdentityType;
    private VisitorType $gatesaleType;
    private VisitorType $truckType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(VisitorSheetWriter::class, Mockery::mock(VisitorSheetWriter::class)
            ->shouldReceive('appendTimeIn')->andReturnNull()->byDefault()
            ->shouldReceive('appendTimeOut')->andReturnNull()->byDefault()
            ->getMock());

        $this->visitorIdentityType = IdentityType::create(['identity_type_name' => 'Visitor']);
        $this->gatesaleType = VisitorType::create(['visitor_type_name' => 'Gatesale']);
        $this->truckType = VisitorType::create(['visitor_type_name' => 'Truck']);

        $this->farm = FarmList::firstOrCreate(['farm_code' => 'ALPHA'], ['farm_name' => 'ALPHA']);
        $this->kiosk = KioskDevice::create([
            'farm_id' => $this->farm->farm_id,
            'device_name' => 'Test Kiosk',
            'serial_number' => 'SN-' . uniqid(),
        ]);
    }

    private function descriptor(float $seed): array
    {
        return array_fill(0, 128, $seed);
    }

    private function makeTruckDirectory(float $descriptorSeed, array $overrides = []): UserDirectory
    {
        $email = 'truck+' . uniqid() . '@example.com';
        $directory = UserDirectory::create(array_merge([
            'identity_type_id' => $this->visitorIdentityType->identity_type_id,
            'person_reference' => $email,
            'first_name' => 'Pedro',
            'last_name' => 'Reyes',
            'full_name' => 'Pedro Reyes',
            'email' => $email,
            'phone' => '09171234567',
        ], $overrides));

        VisitorProfile::create([
            'directory_id' => $directory->directory_id,
            'visitor_type_id' => $this->truckType->visitor_type_id,
            'company' => 'Reyes Logistics',
            'plate_no' => 'ABC-1234',
        ]);

        FaceProfile::create([
            'directory_id' => $directory->directory_id,
            'embedding' => $this->descriptor($descriptorSeed),
            'is_active' => true,
        ]);

        return $directory;
    }

    private function makeActiveTruckRequest(UserDirectory $directory, array $overrides = []): VisitorRequest
    {
        return VisitorRequest::create(array_merge([
            'directory_id' => $directory->directory_id,
            'farm_id' => $this->farm->farm_id,
            'host_name' => 'Existing Host',
            'origin' => 'Existing Origin',
            'purpose' => 'Existing Purpose',
            'visit_datetime' => now(),
            'departure_datetime' => null,
            'registration_token' => null,
            'face_registration_status' => 'REGISTERED',
        ], $overrides));
    }

    private function recognize(array $body)
    {
        return $this->postJson("/kiosk/{$this->kiosk->kiosk_id}/recognize", $body, [
            'X-KIOSK-TOKEN' => $this->kiosk->kiosk_token,
        ]);
    }

    private function updateDetails(array $body)
    {
        return $this->postJson("/kiosk/{$this->kiosk->kiosk_id}/gatesale/update-details", $body, [
            'X-KIOSK-TOKEN' => $this->kiosk->kiosk_token,
        ]);
    }

    private function createVisit(array $body)
    {
        return $this->postJson("/kiosk/{$this->kiosk->kiosk_id}/gatesale/create-visit", $body, [
            'X-KIOSK-TOKEN' => $this->kiosk->kiosk_token,
        ]);
    }

    private function registerIdentity(array $body)
    {
        return $this->postJson("/kiosk/{$this->kiosk->kiosk_id}/gatesale/register-identity", array_merge([
            'visitor_type' => 'Truck',
        ], $body), [
            'X-KIOSK-TOKEN' => $this->kiosk->kiosk_token,
        ]);
    }

    private function entry(int $visitorRequestId, string $action)
    {
        return $this->postJson("/kiosk/{$this->kiosk->kiosk_id}/entry", [
            'visitor_request_id' => $visitorRequestId,
            'action' => $action,
        ], ['X-KIOSK-TOKEN' => $this->kiosk->kiosk_token]);
    }

    // ---- 1, 5, 7: new Truck registration ----
    public function test_register_identity_truck_creates_directory_with_plate_no_and_no_visit(): void
    {
        $response = $this->registerIdentity([
            'full_name' => 'Brand New Trucker',
            'company' => 'Reyes Logistics',
            'plate_no' => 'XYZ-9999',
            'descriptor' => $this->descriptor(0.10),
        ]);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertEquals('XYZ-9999', $response->json('directory.plate_no'));
        $this->assertEquals('Truck', $response->json('directory.visitor_type'));

        $directory = UserDirectory::where('full_name', 'Brand New Trucker')->sole();
        $this->assertEquals($this->truckType->visitor_type_id, $directory->visitorProfile->visitor_type_id);
        $this->assertEquals('XYZ-9999', $directory->visitorProfile->plate_no);
        $this->assertEquals(1, FaceProfile::where('directory_id', $directory->directory_id)->count());

        $this->assertEquals(0, VisitorRequest::count());
        $this->assertEquals(0, VisitorSession::count());
        $this->assertEquals(0, VisitorEntryLog::count());
    }

    // ---- 2, 3: Plate No. required, enforced server-side ----
    public function test_register_identity_truck_without_plate_no_is_rejected_server_side(): void
    {
        $response = $this->registerIdentity([
            'full_name' => 'No Plate Trucker',
            'company' => 'Reyes Logistics',
            'descriptor' => $this->descriptor(0.11),
        ]);

        $response->assertStatus(422);
        $this->assertEquals(0, UserDirectory::count());
        $this->assertEquals(0, FaceProfile::count());
    }

    // ---- 4: Gatesale registration still works without Plate No. ----
    public function test_gatesale_registration_still_works_without_plate_no(): void
    {
        $response = $this->postJson("/kiosk/{$this->kiosk->kiosk_id}/gatesale/register-identity", [
            'visitor_type' => 'Gatesale',
            'full_name' => 'Gatesale Visitor',
            'company' => 'Acme',
            'descriptor' => $this->descriptor(0.12),
        ], ['X-KIOSK-TOKEN' => $this->kiosk->kiosk_token]);

        $response->assertOk()->assertJson(['success' => true]);
        $directory = UserDirectory::where('full_name', 'Gatesale Visitor')->sole();
        $this->assertEquals($this->gatesaleType->visitor_type_id, $directory->visitorProfile->visitor_type_id);
        $this->assertNull($directory->visitorProfile->plate_no);
    }

    // ---- 6, 8, 9: Visit Details completes the flow, sets REGISTERED, stores photo ----
    public function test_register_identity_then_create_visit_completes_full_truck_flow_with_photo(): void
    {
        $register = $this->registerIdentity([
            'full_name' => 'Brand New Trucker',
            'company' => 'Reyes Logistics',
            'plate_no' => 'XYZ-9999',
            'descriptor' => $this->descriptor(0.20),
        ]);
        $register->assertOk();
        $directoryId = $register->json('directory.directory_id');

        $fakeJpegBase64 = 'data:image/jpeg;base64,' . base64_encode('not-a-real-jpeg');
        $visit = $this->createVisit([
            'directory_id' => $directoryId,
            'host_name' => 'Host Person',
            'origin' => 'Warehouse A',
            'purpose' => 'Delivery',
            'photo' => $fakeJpegBase64,
        ]);

        $visit->assertOk()->assertJson(['success' => true, 'type' => 'gatesale_match']);
        $this->assertEquals('Inside', $visit->json('session_state.status'));

        $visitorRequest = VisitorRequest::where('directory_id', $directoryId)->sole();
        $this->assertEquals('REGISTERED', $visitorRequest->face_registration_status);
        $this->assertEquals(1, VisitorSession::count());

        $log = VisitorEntryLog::where('movement_type', 'First Entry')->sole();
        $this->assertNotNull($log->photo, 'Truck first-entry log must store the captured photo, same as Gatesale/Visitor-with-Approval');
    }

    // ---- 10: existing Truck face, no ACTIVE request -> Is this you? ----
    public function test_existing_truck_face_without_active_request_returns_confirm_identity_with_plate_no(): void
    {
        $directory = $this->makeTruckDirectory(0.30);

        $response = $this->recognize(['descriptor' => $this->descriptor(0.30)]);

        $response->assertOk()->assertJson(['success' => false, 'type' => 'gatesale_confirm_identity']);
        $this->assertEquals('ABC-1234', $response->json('directory.plate_no'));
        $this->assertEquals('Truck', $response->json('directory.visitor_type'));
    }

    // ---- 11: ACTIVE in current farm bypasses confirmation ----
    public function test_existing_truck_active_in_current_farm_bypasses_confirmation(): void
    {
        $directory = $this->makeTruckDirectory(0.31);
        $activeRequest = $this->makeActiveTruckRequest($directory);
        VisitorSession::create([
            'visitor_request_id' => $activeRequest->visitor_request_id,
            'session_status' => 'Inside',
            'login_id' => 'TRUCK001',
            'first_in' => now(),
        ]);

        $response = $this->recognize(['descriptor' => $this->descriptor(0.31)]);

        $response->assertOk()->assertJson([
            'success' => true,
            'type' => 'gatesale_match',
            'visitor_request_id' => $activeRequest->visitor_request_id,
        ]);
    }

    // ---- 12, 13: ACTIVE in another farm is denied, no simultaneous visits ----
    public function test_existing_truck_active_in_another_farm_is_denied(): void
    {
        $otherFarm = FarmList::firstOrCreate(['farm_code' => 'BETA'], ['farm_name' => 'BETA']);
        $directory = $this->makeTruckDirectory(0.32);
        $this->makeActiveTruckRequest($directory, ['farm_id' => $otherFarm->farm_id]);

        $response = $this->recognize(['descriptor' => $this->descriptor(0.32)]);

        $response->assertStatus(403)->assertJson(['success' => false, 'type' => 'gatesale_active_elsewhere']);
        $this->assertStringContainsString('BETA', $response->json('message'));
        $this->assertEquals(1, VisitorRequest::count());

        $createResponse = $this->createVisit([
            'directory_id' => $directory->directory_id,
            'host_name' => 'H', 'origin' => 'O', 'purpose' => 'P',
        ]);
        $createResponse->assertStatus(403);
        $this->assertEquals(1, VisitorRequest::count(), 'must not create a second simultaneous ACTIVE request across farms');
    }

    // ---- 14: EDIT DETAILS can update Plate No. ----
    public function test_edit_details_updates_truck_plate_no(): void
    {
        $directory = $this->makeTruckDirectory(0.40);

        $response = $this->updateDetails([
            'directory_id' => $directory->directory_id,
            'full_name' => $directory->full_name,
            'email' => $directory->email,
            'phone' => $directory->phone,
            'company' => $directory->visitorProfile->company,
            'plate_no' => 'NEW-5555',
        ]);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertEquals('NEW-5555', $response->json('directory.plate_no'));
        $this->assertEquals('NEW-5555', $directory->visitorProfile->fresh()->plate_no);
    }

    public function test_edit_details_rejects_empty_plate_no_for_truck(): void
    {
        $directory = $this->makeTruckDirectory(0.41);

        $response = $this->updateDetails([
            'directory_id' => $directory->directory_id,
            'full_name' => $directory->full_name,
            'plate_no' => '',
        ]);

        $response->assertStatus(422);
        $this->assertEquals('ABC-1234', $directory->visitorProfile->fresh()->plate_no, 'plate_no must be left untouched when validation fails');
    }

    public function test_edit_details_never_converts_visitor_type(): void
    {
        $directory = $this->makeTruckDirectory(0.42);

        $this->updateDetails([
            'directory_id' => $directory->directory_id,
            'full_name' => 'Renamed',
            'plate_no' => 'STILL-TRUCK',
        ])->assertOk();

        $this->assertEquals($this->truckType->visitor_type_id, $directory->visitorProfile->fresh()->visitor_type_id);
    }

    // ---- 15: EDIT DETAILS never creates a request by itself ----
    public function test_edit_details_alone_never_creates_a_visit(): void
    {
        $directory = $this->makeTruckDirectory(0.43);

        $this->updateDetails([
            'directory_id' => $directory->directory_id,
            'full_name' => $directory->full_name,
            'plate_no' => 'NEW-0001',
        ])->assertOk();

        $this->assertEquals(0, VisitorRequest::count());
    }

    // ---- 17: scan again after Leave Farm can start a new visitation ----
    public function test_after_leave_farm_truck_can_start_a_new_visitation(): void
    {
        $directory = $this->makeTruckDirectory(0.50);
        $create = $this->createVisit([
            'directory_id' => $directory->directory_id,
            'host_name' => 'H', 'origin' => 'O', 'purpose' => 'P',
        ]);
        $create->assertOk();
        $visitorRequestId = $create->json('visitor_request_id');

        $this->entry($visitorRequestId, 'final_exit')->assertOk();

        $afterLeave = $this->recognize(['descriptor' => $this->descriptor(0.50)]);
        $afterLeave->assertOk()->assertJson(['success' => false, 'type' => 'gatesale_confirm_identity']);
    }

    // ---- 18: Truck never writes to Google Sheets ----
    public function test_truck_full_lifecycle_never_writes_to_google_sheets(): void
    {
        $mockWriter = Mockery::mock(VisitorSheetWriter::class);
        $mockWriter->shouldNotReceive('appendTimeIn');
        $mockWriter->shouldNotReceive('appendTimeOut');
        $this->app->instance(VisitorSheetWriter::class, $mockWriter);

        $directory = $this->makeTruckDirectory(0.60);

        $create = $this->createVisit([
            'directory_id' => $directory->directory_id,
            'host_name' => 'H', 'origin' => 'O', 'purpose' => 'P',
        ]);
        $create->assertOk();
        $visitorRequestId = $create->json('visitor_request_id');

        $this->entry($visitorRequestId, 'temporary_exit')->assertOk();
        $this->entry($visitorRequestId, 'return')->assertOk();
        $this->entry($visitorRequestId, 'final_exit')->assertOk();

        $this->assertEquals('COMPLETED', VisitorRequest::find($visitorRequestId)->request_status);
    }

    // ---- 20: Visitor-with-Approval Google Sheets behavior remains unchanged ----
    public function test_visitor_with_approval_google_sheets_behavior_is_unaffected_by_truck(): void
    {
        $mockWriter = Mockery::mock(VisitorSheetWriter::class);
        $mockWriter->shouldReceive('appendTimeIn')->once();
        $mockWriter->shouldReceive('appendTimeOut')->once();
        $this->app->instance(VisitorSheetWriter::class, $mockWriter);

        $visitorType = VisitorType::firstOrCreate(['visitor_type_name' => 'Visitor']);
        $email = 'approved+' . uniqid() . '@example.com';
        $directory = UserDirectory::create([
            'identity_type_id' => $this->visitorIdentityType->identity_type_id,
            'person_reference' => $email,
            'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'full_name' => 'Juan Dela Cruz',
            'email' => $email,
        ]);
        VisitorProfile::create([
            'directory_id' => $directory->directory_id,
            'visitor_type_id' => $visitorType->visitor_type_id,
        ]);
        $visitorRequest = VisitorRequest::create([
            'directory_id' => $directory->directory_id,
            'visitor_id' => 'VIS-' . uniqid(),
            'farm_id' => $this->farm->farm_id,
            'host_name' => 'Host',
            'visit_datetime' => now(),
            'registration_token' => 'REG_' . Str::upper(Str::random(8)),
            'approval_status' => 'Approved',
        ]);

        $this->entry($visitorRequest->visitor_request_id, 'first_entry')->assertOk();
        $this->entry($visitorRequest->visitor_request_id, 'final_exit')->assertOk();
    }

    // ---- Truck does not appear as Plate No. for Gatesale ----
    public function test_gatesale_confirm_identity_never_shows_plate_no(): void
    {
        $gatesaleDirectory = UserDirectory::create([
            'identity_type_id' => $this->visitorIdentityType->identity_type_id,
            'person_reference' => 'gs+' . uniqid() . '@example.com',
            'first_name' => 'Maria', 'last_name' => 'Santos', 'full_name' => 'Maria Santos',
        ]);
        VisitorProfile::create([
            'directory_id' => $gatesaleDirectory->directory_id,
            'visitor_type_id' => $this->gatesaleType->visitor_type_id,
        ]);
        FaceProfile::create([
            'directory_id' => $gatesaleDirectory->directory_id,
            'embedding' => $this->descriptor(0.70),
            'is_active' => true,
        ]);

        $response = $this->recognize(['descriptor' => $this->descriptor(0.70)]);

        $response->assertOk()->assertJson(['success' => false, 'type' => 'gatesale_confirm_identity']);
        $this->assertNull($response->json('directory.plate_no'));
        $this->assertEquals('Gatesale', $response->json('directory.visitor_type'));
    }
}
