<?php

namespace Tests\Feature\Kiosk;

use App\Models\FaceProfile;
use App\Models\FarmList;
use App\Models\IdentityType;
use App\Models\KioskDevice;
use App\Models\UserDirectory;
use App\Models\VisitorEntryLog;
use App\Models\VisitorRequest;
use App\Models\VisitorSession;
use App\Models\VisitorType;
use App\Services\GoogleSheets\VisitorSheetWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class KioskIdentityRoutingTest extends TestCase
{
    use RefreshDatabase;

    private KioskDevice $kiosk;
    private FarmList $farm;
    private IdentityType $employeeType;
    private IdentityType $visitorIdentityType;
    private VisitorType $visitorType;
    private VisitorType $gatesaleType;
    private VisitorType $truckType;

    protected function setUp(): void
    {
        parent::setUp();

        // Avoid real Google Sheets calls anywhere in this suite.
        $this->app->instance(VisitorSheetWriter::class, Mockery::mock(VisitorSheetWriter::class)
            ->shouldReceive('appendTimeIn')->andReturnNull()->byDefault()
            ->shouldReceive('appendTimeOut')->andReturnNull()->byDefault()
            ->getMock());

        $this->employeeType = IdentityType::create(['identity_type_name' => 'Employee']);
        $this->visitorIdentityType = IdentityType::create(['identity_type_name' => 'Visitor']);
        $this->visitorType = VisitorType::create(['visitor_type_name' => 'Visitor']);
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

    private function makeDirectoryWithFace(float $descriptorSeed, array $overrides = []): UserDirectory
    {
        $email = 'visitor+' . uniqid() . '@example.com';
        $directory = UserDirectory::create(array_merge([
            'identity_type_id' => $this->visitorIdentityType->identity_type_id,
            'visitor_type_id' => $this->visitorType->visitor_type_id,
            'person_reference' => $email,
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'full_name' => 'Juan Dela Cruz',
            'email' => $email,
        ], $overrides));

        FaceProfile::create([
            'directory_id' => $directory->directory_id,
            'embedding' => $this->descriptor($descriptorSeed),
            'is_active' => true,
        ]);

        return $directory;
    }

    private function makeVisitorRequest(UserDirectory $directory, array $overrides = []): VisitorRequest
    {
        return VisitorRequest::create(array_merge([
            'directory_id' => $directory->directory_id,
            'visitor_id' => 'VIS-' . uniqid(),
            'farm_id' => $this->farm->farm_id,
            'host_name' => 'Host',
            'visit_datetime' => now(),
            'registration_token' => 'REG_' . Str::upper(Str::random(8)),
            'approval_status' => 'Approved',
        ], $overrides));
    }

    private function recognize(array $body)
    {
        return $this->postJson("/kiosk/{$this->kiosk->kiosk_id}/recognize", $body, [
            'X-KIOSK-TOKEN' => $this->kiosk->kiosk_token,
        ]);
    }

    private function assertNoNewDomainRows(): void
    {
        $this->assertEquals(0, VisitorRequest::count());
        $this->assertEquals(0, VisitorSession::count());
        $this->assertEquals(0, VisitorEntryLog::count());
    }

    public function test_1_existing_approved_visitor_is_unaffected(): void
    {
        $directory = $this->makeDirectoryWithFace(0.10);
        $visitorRequest = $this->makeVisitorRequest($directory);

        $response = $this->recognize(['descriptor' => $this->descriptor(0.10)]);

        $response->assertOk()->assertJson(['success' => true, 'type' => 'face_match']);
        $this->assertEquals($visitorRequest->visitor_request_id, $response->json('visitor_request_id'));
        $this->assertEquals('no_session', $response->json('session_state.status'));
    }

    public function test_2_employee_is_detected_and_placeholder_creates_no_rows(): void
    {
        $this->makeDirectoryWithFace(0.20, [
            'identity_type_id' => $this->employeeType->identity_type_id,
            'visitor_type_id' => null,
        ]);

        $response = $this->recognize(['descriptor' => $this->descriptor(0.20)]);

        $response->assertOk()->assertJson(['success' => false, 'type' => 'employee_detected']);
        $this->assertEquals(0, UserDirectory::where('identity_type_id', $this->visitorIdentityType->identity_type_id)->count());
        $this->assertNoNewDomainRows();
    }

    public function test_3_gatesale_is_routed_to_identity_confirmation_creates_no_rows(): void
    {
        $this->makeDirectoryWithFace(0.30, [
            'visitor_type_id' => $this->gatesaleType->visitor_type_id,
        ]);

        $response = $this->recognize(['descriptor' => $this->descriptor(0.30)]);

        $response->assertOk()->assertJson(['success' => false, 'type' => 'gatesale_confirm_identity']);
        $this->assertNoNewDomainRows();
    }

    public function test_4_truck_is_routed_to_identity_confirmation_creates_no_rows(): void
    {
        // Truck is fully implemented as of Phase 3 - shares the exact same
        // routing as Gatesale (see KioskTruckFlowTest for the full behavior).
        $this->makeDirectoryWithFace(0.40, [
            'visitor_type_id' => $this->truckType->visitor_type_id,
        ]);

        $response = $this->recognize(['descriptor' => $this->descriptor(0.40)]);

        $response->assertOk()->assertJson(['success' => false, 'type' => 'gatesale_confirm_identity']);
        $this->assertEquals('Truck', $response->json('directory.visitor_type'));
        $this->assertNoNewDomainRows();
    }

    public function test_5_unknown_face_still_returns_face_not_found(): void
    {
        $response = $this->recognize(['descriptor' => $this->descriptor(0.99)]);

        $response->assertStatus(404)->assertJson(['success' => false, 'type' => 'face_not_found']);
        $this->assertNoNewDomainRows();
    }

    public function test_6_wrong_farm_is_unaffected_by_identity_routing(): void
    {
        $otherFarm = FarmList::firstOrCreate(['farm_code' => 'BETA'], ['farm_name' => 'BETA']);
        $directory = $this->makeDirectoryWithFace(0.60);
        $this->makeVisitorRequest($directory, ['farm_id' => $otherFarm->farm_id]);

        $response = $this->recognize(['descriptor' => $this->descriptor(0.60)]);

        $response->assertStatus(403)->assertJson(['success' => false, 'type' => 'wrong_farm']);
        $this->assertEquals(0, VisitorSession::count());
    }

    public function test_7_legacy_directory_with_null_visitor_type_id_still_uses_existing_flow(): void
    {
        $directory = $this->makeDirectoryWithFace(0.70, ['visitor_type_id' => null]);
        $visitorRequest = $this->makeVisitorRequest($directory);

        $response = $this->recognize(['descriptor' => $this->descriptor(0.70)]);

        $response->assertOk()->assertJson(['success' => true, 'type' => 'face_match']);
        $this->assertEquals($visitorRequest->visitor_request_id, $response->json('visitor_request_id'));
    }

    public function test_8_unsupported_visitor_type_gets_explicit_placeholder_not_silent_passthrough(): void
    {
        $futureType = VisitorType::create(['visitor_type_name' => 'Contractor Vehicle']);
        $this->makeDirectoryWithFace(0.80, ['visitor_type_id' => $futureType->visitor_type_id]);

        $response = $this->recognize(['descriptor' => $this->descriptor(0.80)]);

        $response->assertOk()->assertJson(['success' => false, 'type' => 'visitor_type_not_supported']);
        $this->assertNoNewDomainRows();
    }

    public function test_9_full_existing_session_walkthrough_is_unaffected(): void
    {
        $directory = $this->makeDirectoryWithFace(0.90);
        $visitorRequest = $this->makeVisitorRequest($directory);

        $enter = $this->postJson("/kiosk/{$this->kiosk->kiosk_id}/entry", [
            'visitor_request_id' => $visitorRequest->visitor_request_id,
            'action' => 'first_entry',
        ], ['X-KIOSK-TOKEN' => $this->kiosk->kiosk_token]);
        $enter->assertOk()->assertJson(['success' => true, 'session_status' => 'Inside']);

        $tempExit = $this->postJson("/kiosk/{$this->kiosk->kiosk_id}/entry", [
            'visitor_request_id' => $visitorRequest->visitor_request_id,
            'action' => 'temporary_exit',
        ], ['X-KIOSK-TOKEN' => $this->kiosk->kiosk_token]);
        $tempExit->assertOk()->assertJson(['success' => true, 'session_status' => 'Outside']);

        $return = $this->postJson("/kiosk/{$this->kiosk->kiosk_id}/entry", [
            'visitor_request_id' => $visitorRequest->visitor_request_id,
            'action' => 'return',
        ], ['X-KIOSK-TOKEN' => $this->kiosk->kiosk_token]);
        $return->assertOk()->assertJson(['success' => true, 'session_status' => 'Inside']);

        $finalExit = $this->postJson("/kiosk/{$this->kiosk->kiosk_id}/entry", [
            'visitor_request_id' => $visitorRequest->visitor_request_id,
            'action' => 'final_exit',
        ], ['X-KIOSK-TOKEN' => $this->kiosk->kiosk_token]);
        $finalExit->assertOk()->assertJson(['success' => true, 'session_status' => 'Completed']);

        $this->assertEquals('COMPLETED', $visitorRequest->fresh()->request_status);
        $this->assertEquals(4, VisitorEntryLog::where('kiosk_id', $this->kiosk->kiosk_id)->count());
    }
}
