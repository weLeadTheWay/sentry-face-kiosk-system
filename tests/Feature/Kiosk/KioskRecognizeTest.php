<?php

namespace Tests\Feature\Kiosk;

use App\Models\FaceProfile;
use App\Models\FacilityList;
use App\Models\IdentityType;
use App\Models\KioskDevice;
use App\Models\UserDirectory;
use App\Models\VisitorEntryLog;
use App\Models\VisitorRequest;
use App\Models\VisitorSession;
use App\Services\GoogleSheets\VisitorSheetWriter;
use App\Services\Kiosk\VisitorKioskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\Concerns\CreatesFacilities;
use Tests\TestCase;

class KioskRecognizeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesFacilities;

    private KioskDevice $kiosk;
    private FacilityList $facility;
    private IdentityType $identityType;

    protected function setUp(): void
    {
        parent::setUp();

        // Avoid real Google Sheets calls anywhere in this suite.
        $this->app->instance(VisitorSheetWriter::class, Mockery::mock(VisitorSheetWriter::class)
            ->shouldReceive('appendTimeIn')->andReturnNull()->byDefault()
            ->shouldReceive('appendTimeOut')->andReturnNull()->byDefault()
            ->getMock());

        $this->identityType = IdentityType::firstOrCreate(['identity_type_name' => 'Visitor']);
        $this->facility = $this->createFacility('ALPHA');
        $this->kiosk = KioskDevice::create([
            'facility_id' => $this->facility->facility_id,
            'device_name' => 'Test Kiosk',
            'serial_number' => 'SN-' . uniqid(),
        ]);
    }

    private function otherFarm(): FacilityList
    {
        return $this->createFacility('BETA');
    }

    private function descriptor(float $seed): array
    {
        return array_fill(0, 128, $seed);
    }

    private function makeDirectoryWithFace(float $descriptorSeed): UserDirectory
    {
        $email = 'visitor+' . uniqid() . '@example.com';
        $directory = UserDirectory::create([
            'identity_type_id' => $this->identityType->identity_type_id,
            'person_reference' => $email,
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'full_name' => 'Juan Dela Cruz',
            'email' => $email,
        ]);

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
            'facility_id' => $this->facility->facility_id,
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

    public function test_face_not_found_when_no_matching_profile(): void
    {
        $response = $this->recognize(['descriptor' => $this->descriptor(0.99)]);

        $response->assertStatus(404)->assertJson(['success' => false, 'type' => 'face_not_found']);
    }

    public function test_face_found_but_no_active_request_is_distinct_from_face_not_found(): void
    {
        $directory = $this->makeDirectoryWithFace(0.11);
        // No VisitorRequest created for this directory at all.

        $response = $this->recognize(['descriptor' => $this->descriptor(0.11)]);

        $response->assertStatus(404)->assertJson(['success' => false, 'type' => 'face_found_no_active_request']);
    }

    public function test_multiday_visitor_recognized_within_window_not_just_on_visit_date(): void
    {
        $directory = $this->makeDirectoryWithFace(0.22);
        $this->makeVisitorRequest($directory, [
            'visit_datetime' => now()->subDays(2),
            'departure_datetime' => now()->addDays(2),
        ]);

        $response = $this->recognize(['descriptor' => $this->descriptor(0.22)]);

        $response->assertOk()->assertJson(['success' => true, 'type' => 'face_match']);
        $this->assertEquals('no_session', $response->json('session_state.status'));
    }

    public function test_visitor_outside_departure_window_is_not_recognized(): void
    {
        $directory = $this->makeDirectoryWithFace(0.33);
        $this->makeVisitorRequest($directory, [
            'visit_datetime' => now()->subDays(5),
            'departure_datetime' => now()->subDays(1),
        ]);

        $response = $this->recognize(['descriptor' => $this->descriptor(0.33)]);

        $response->assertStatus(404)->assertJson(['success' => false, 'type' => 'face_found_no_active_request']);
    }

    public function test_completed_request_returns_request_completed_and_creates_no_new_session(): void
    {
        $directory = $this->makeDirectoryWithFace(0.44);
        $visitorRequest = $this->makeVisitorRequest($directory);

        $service = app(VisitorKioskService::class);
        $service->processEntry($visitorRequest->visitor_request_id, 'first_entry', $this->kiosk, null, 'FACE');
        $service->processEntry($visitorRequest->visitor_request_id, 'final_exit', $this->kiosk, null, 'FACE');

        $this->assertEquals(1, VisitorSession::where('visitor_request_id', $visitorRequest->visitor_request_id)->count());

        $response = $this->recognize(['descriptor' => $this->descriptor(0.44)]);

        $response->assertStatus(409)->assertJson(['success' => false, 'type' => 'request_completed']);
        $this->assertEquals(1, VisitorSession::where('visitor_request_id', $visitorRequest->visitor_request_id)->count());
    }

    public function test_newer_active_request_is_preferred_over_an_older_completed_one(): void
    {
        $directory = $this->makeDirectoryWithFace(0.66);

        // TEST 1: an older, already-completed visit for this same person,
        // still within today's window.
        $completedRequest = $this->makeVisitorRequest($directory, [
            'request_status' => 'COMPLETED',
        ]);

        // TEST 2: a newer, active, approved visit for the SAME directory,
        // also within today's window (real-world scenario reported: both
        // visit_datetime/departure_datetime windows overlap "today").
        $activeRequest = $this->makeVisitorRequest($directory);

        $response = $this->recognize(['descriptor' => $this->descriptor(0.66)]);

        $response->assertOk()->assertJson(['success' => true, 'type' => 'face_match']);
        $this->assertEquals($activeRequest->visitor_request_id, $response->json('visitor_request_id'));
        $this->assertEquals('no_session', $response->json('session_state.status'));
    }

    public function test_qr_not_found_when_visitor_id_unknown(): void
    {
        $response = $this->recognize(['qr_value' => 'NOPE']);

        $response->assertStatus(404)->assertJson(['success' => false, 'type' => 'qr_not_found']);
    }

    public function test_qr_and_face_paths_share_identical_window_and_completion_validation(): void
    {
        $directory = $this->makeDirectoryWithFace(0.55);
        $visitorRequest = $this->makeVisitorRequest($directory, [
            'visit_datetime' => now()->subDays(5),
            'departure_datetime' => now()->subDays(1),
        ]);

        $response = $this->recognize(['qr_value' => $visitorRequest->visitor_id]);

        $response->assertStatus(404)->assertJson(['success' => false, 'type' => 'qr_found_no_active_request']);
    }

    public function test_face_recognition_rejects_visitor_approved_for_a_different_farm(): void
    {
        $directory = $this->makeDirectoryWithFace(0.77);
        $this->makeVisitorRequest($directory, ['facility_id' => $this->otherFarm()->facility_id]);

        $response = $this->recognize(['descriptor' => $this->descriptor(0.77)]);

        $response->assertStatus(403)->assertJson(['success' => false, 'type' => 'wrong_farm']);
        // Message must name the visitor's actually-approved farm and make
        // clear the recognition itself succeeded - not a generic denial.
        $this->assertStringContainsString('BETA', $response->json('message'));
        $this->assertStringContainsString('Recognized successfully', $response->json('message'));
        $this->assertEquals(0, VisitorSession::count(), 'no session may be created for a farm mismatch');
    }

    public function test_qr_recognition_rejects_visitor_approved_for_a_different_farm(): void
    {
        $directory = $this->makeDirectoryWithFace(0.78);
        $visitorRequest = $this->makeVisitorRequest($directory, ['facility_id' => $this->otherFarm()->facility_id]);

        $response = $this->recognize(['qr_value' => $visitorRequest->visitor_id]);

        $response->assertStatus(403)->assertJson(['success' => false, 'type' => 'wrong_farm']);
        $this->assertEquals(0, VisitorSession::count());
    }

    public function test_matching_farm_request_is_preferred_over_a_different_farm_request(): void
    {
        $directory = $this->makeDirectoryWithFace(0.79);
        $this->makeVisitorRequest($directory, ['facility_id' => $this->otherFarm()->facility_id]);
        $correctFarmRequest = $this->makeVisitorRequest($directory); // defaults to $this->facility (the kiosk's facility)

        $response = $this->recognize(['descriptor' => $this->descriptor(0.79)]);

        $response->assertOk()->assertJson(['success' => true, 'type' => 'face_match']);
        $this->assertEquals($correctFarmRequest->visitor_request_id, $response->json('visitor_request_id'));
    }

    public function test_active_wrong_farm_request_is_preferred_over_a_completed_same_farm_request(): void
    {
        $directory = $this->makeDirectoryWithFace(0.80);
        $otherFarm = $this->otherFarm();

        // An older visit to THIS kiosk's own farm, already completed.
        $completedRequest = $this->makeVisitorRequest($directory, [
            'request_status' => 'COMPLETED',
        ]);

        // A currently-active approved visit, but for a DIFFERENT farm.
        $activeWrongFarmRequest = $this->makeVisitorRequest($directory, [
            'facility_id' => $otherFarm->facility_id,
        ]);

        // Face recognition must surface the SAME "wrong farm" result the QR
        // path already gets right (a direct visitor_id lookup with no
        // candidate selection) - not "already completed", which would
        // describe the wrong request entirely.
        $response = $this->recognize(['descriptor' => $this->descriptor(0.80)]);

        $response->assertStatus(403)->assertJson(['success' => false, 'type' => 'wrong_farm']);
        $this->assertStringContainsString('BETA', $response->json('message'));

        $qrResponse = $this->recognize(['qr_value' => $activeWrongFarmRequest->visitor_id]);
        $qrResponse->assertStatus(403)->assertJson(['success' => false, 'type' => 'wrong_farm']);
    }
}
