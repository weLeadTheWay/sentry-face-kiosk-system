<?php

namespace Tests\Feature\Kiosk;

use App\Http\Controllers\Kiosk\KioskController;
use App\Models\FaceProfile;
use App\Models\FaceProfileEmbedding;
use App\Models\FacilityList;
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
use Tests\Concerns\CreatesFacilities;
use Tests\TestCase;

class KioskGatesaleFlowTest extends TestCase
{
    use RefreshDatabase;
    use CreatesFacilities;

    private KioskDevice $kiosk;
    private FacilityList $facility;
    private IdentityType $visitorIdentityType;
    private VisitorType $visitorType;
    private VisitorType $gatesaleType;

    protected function setUp(): void
    {
        parent::setUp();

        // Default: allow Sheets calls to no-op. Tests that assert on
        // call counts rebind a fresh mock with strict expectations.
        $this->app->instance(VisitorSheetWriter::class, Mockery::mock(VisitorSheetWriter::class)
            ->shouldReceive('appendTimeIn')->andReturnNull()->byDefault()
            ->shouldReceive('appendTimeOut')->andReturnNull()->byDefault()
            ->getMock());

        $this->visitorIdentityType = IdentityType::create(['identity_type_name' => 'Visitor']);
        $this->visitorType = VisitorType::create(['visitor_type_name' => 'Visitor']);
        $this->gatesaleType = VisitorType::create(['visitor_type_name' => 'Gatesale']);

        $this->facility = $this->createFacility('ALPHA');
        $this->kiosk = KioskDevice::create([
            'facility_id' => $this->facility->facility_id,
            'device_name' => 'Test Kiosk',
            'serial_number' => 'SN-' . uniqid(),
        ]);
    }

    private function descriptor(float $seed): array
    {
        return array_fill(0, 128, $seed);
    }

    private function makeGatesaleDirectory(float $descriptorSeed, array $overrides = []): UserDirectory
    {
        $email = 'gatesale+' . uniqid() . '@example.com';
        $directory = UserDirectory::create(array_merge([
            'identity_type_id' => $this->visitorIdentityType->identity_type_id,
            'person_reference' => $email,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'full_name' => 'Maria Santos',
            'email' => $email,
            'phone' => '09171234567',
        ], $overrides));

        VisitorProfile::create([
            'directory_id' => $directory->directory_id,
            'visitor_type_id' => $this->gatesaleType->visitor_type_id,
            'company' => 'Acme Traders',
        ]);

        FaceProfile::create([
            'directory_id' => $directory->directory_id,
            'embedding' => $this->descriptor($descriptorSeed),
            'is_active' => true,
        ]);

        return $directory;
    }

    private function makeActiveGatesaleRequest(UserDirectory $directory, array $overrides = []): VisitorRequest
    {
        return VisitorRequest::create(array_merge([
            'directory_id' => $directory->directory_id,
            'facility_id' => $this->facility->facility_id,
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

    /**
     * FRONT-only for now - the guided multi-pose capture UX hasn't shipped
     * yet, so a bare 'descriptor' key here is transparently wrapped into
     * the {poses: {FRONT: {...}}} shape the endpoint now expects, matching
     * exactly what kiosk/show.blade.php's gatesale registration JS sends.
     */
    private function registerIdentity(array $body)
    {
        if (array_key_exists('descriptor', $body)) {
            $body['poses'] = ['FRONT' => ['descriptor' => $body['descriptor']]];
            unset($body['descriptor']);
        }

        return $this->postJson("/kiosk/{$this->kiosk->kiosk_id}/gatesale/register-identity", array_merge([
            'visitor_type' => 'Gatesale',
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

    public function test_gatesale_face_without_active_request_returns_confirm_identity(): void
    {
        $directory = $this->makeGatesaleDirectory(0.10);

        $response = $this->recognize(['descriptor' => $this->descriptor(0.10)]);

        $response->assertOk()->assertJson(['success' => false, 'type' => 'gatesale_confirm_identity']);
        $this->assertEquals($directory->directory_id, $response->json('directory.directory_id'));
        $this->assertEquals('Maria Santos', $response->json('directory.full_name'));
    }

    public function test_gatesale_face_with_active_request_bypasses_confirmation_and_resumes_session(): void
    {
        $directory = $this->makeGatesaleDirectory(0.11);
        $activeRequest = $this->makeActiveGatesaleRequest($directory);
        VisitorSession::create([
            'visitor_request_id' => $activeRequest->visitor_request_id,
            'session_status' => 'Inside',
            'login_id' => 'EXIST001',
            'first_in' => now(),
        ]);

        $response = $this->recognize(['descriptor' => $this->descriptor(0.11)]);

        // An ACTIVE request today must never re-trigger "Is this you?" -
        // the visitor goes straight to Go Out / Leave Farm, same as
        // Visitor-with-Approval.
        $response->assertOk()->assertJson([
            'success' => true,
            'type' => 'gatesale_match',
            'visitor_request_id' => $activeRequest->visitor_request_id,
        ]);
        $this->assertEquals('Inside', $response->json('session_state.status'));
    }

    public function test_create_visit_with_no_active_request_creates_request_session_and_entry_log(): void
    {
        $directory = $this->makeGatesaleDirectory(0.20);

        $response = $this->createVisit([
            'directory_id' => $directory->directory_id,
            'host_name' => 'Juan Dela Cruz',
            'origin' => 'Manila',
            'purpose' => 'Pick up eggs',
        ]);

        $response->assertOk()->assertJson(['success' => true, 'type' => 'gatesale_match']);
        $this->assertEquals('Inside', $response->json('session_state.status'));

        $visitorRequest = VisitorRequest::where('directory_id', $directory->directory_id)->sole();
        $this->assertEquals('Juan Dela Cruz', $visitorRequest->host_name);
        $this->assertEquals('Manila', $visitorRequest->origin);
        $this->assertEquals('Pick up eggs', $visitorRequest->purpose);
        $this->assertEquals('ACTIVE', $visitorRequest->request_status);
        $this->assertEquals('Approved', $visitorRequest->approval_status);
        $this->assertNull($visitorRequest->registration_token);
        $this->assertEquals('REGISTERED', $visitorRequest->face_registration_status);
        $this->assertNotNull($visitorRequest->departure_datetime, 'Gatesale is a same-day visit - departure_datetime must be fixed at creation, not left null');
        $this->assertEquals($visitorRequest->visit_datetime->toDateString(), $visitorRequest->departure_datetime->toDateString());
        $this->assertEquals('23:00:00', $visitorRequest->departure_datetime->format('H:i:s'));
        $this->assertEquals(1, VisitorSession::where('visitor_request_id', $visitorRequest->visitor_request_id)->count());
        $this->assertEquals(1, VisitorEntryLog::count());

        // Follow-up actions actually work - currentVisitorRequest-equivalent
        // state (visitor_request_id) is usable for the next step, not just
        // that the initial response looked right.
        $tempExit = $this->entry($visitorRequest->visitor_request_id, 'temporary_exit');
        $tempExit->assertOk()->assertJson(['success' => true, 'session_status' => 'Outside']);
    }

    public function test_create_visit_stores_the_captured_photo_on_the_first_entry_log(): void
    {
        $directory = $this->makeGatesaleDirectory(0.205);
        $fakeJpegBase64 = 'data:image/jpeg;base64,' . base64_encode('not-a-real-jpeg');

        $response = $this->createVisit([
            'directory_id' => $directory->directory_id,
            'host_name' => 'H', 'origin' => 'O', 'purpose' => 'P',
            'photo' => $fakeJpegBase64,
        ]);

        $response->assertOk();

        $log = VisitorEntryLog::where('movement_type', 'First Entry')->sole();
        $this->assertNotNull($log->photo, 'first-entry log must store the captured photo, exactly like Visitor-with-Approval');
    }

    public function test_create_visit_missing_details_when_no_active_request_is_rejected(): void
    {
        $directory = $this->makeGatesaleDirectory(0.21);

        $response = $this->createVisit(['directory_id' => $directory->directory_id]);

        $response->assertStatus(422);
        $this->assertEquals(0, VisitorRequest::count());
    }

    public function test_create_visit_with_existing_active_request_reuses_it_without_new_details(): void
    {
        $directory = $this->makeGatesaleDirectory(0.22);
        $activeRequest = $this->makeActiveGatesaleRequest($directory);
        // Simulate the visitor already being Inside from an earlier entry today.
        VisitorSession::create([
            'visitor_request_id' => $activeRequest->visitor_request_id,
            'session_status' => 'Inside',
            'login_id' => 'EXIST001',
            'first_in' => now(),
        ]);

        $response = $this->createVisit(['directory_id' => $directory->directory_id]);

        $response->assertOk()->assertJson([
            'success' => true,
            'type' => 'gatesale_match',
            'visitor_request_id' => $activeRequest->visitor_request_id,
        ]);
        $this->assertEquals('Inside', $response->json('session_state.status'));

        $this->assertEquals(1, VisitorRequest::count());
        $this->assertEquals(1, VisitorSession::count());
        $this->assertEquals(0, VisitorEntryLog::count(), 'reusing an existing active request must not create a new first-entry log');
    }

    public function test_active_request_at_a_different_farm_denies_entry_at_recognition(): void
    {
        $otherFarm = $this->createFacility('BETA');
        $directory = $this->makeGatesaleDirectory(0.23);
        $this->makeActiveGatesaleRequest($directory, ['facility_id' => $otherFarm->facility_id]);

        $response = $this->recognize(['descriptor' => $this->descriptor(0.23)]);

        $response->assertStatus(403)->assertJson(['success' => false, 'type' => 'gatesale_active_elsewhere']);
        $this->assertStringContainsString('BETA', $response->json('message'));
        $this->assertEquals(1, VisitorRequest::count(), 'no second request may be created while active elsewhere');
    }

    public function test_active_request_at_a_different_farm_denies_entry_at_create_visit_too(): void
    {
        // Defense-in-depth: the backend must independently enforce this,
        // not only via the recognition-time short-circuit - simulates a
        // client that somehow reached create-visit with a stale directory_id.
        $otherFarm = $this->createFacility('BETA');
        $directory = $this->makeGatesaleDirectory(0.235);
        $this->makeActiveGatesaleRequest($directory, ['facility_id' => $otherFarm->facility_id]);

        $response = $this->createVisit([
            'directory_id' => $directory->directory_id,
            'host_name' => 'H', 'origin' => 'O', 'purpose' => 'P',
        ]);

        $response->assertStatus(403);
        $this->assertEquals(1, VisitorRequest::count());
    }

    public function test_may_visit_a_second_farm_after_the_first_farm_visit_is_no_longer_active(): void
    {
        $otherFarm = $this->createFacility('BETA');
        $otherKiosk = KioskDevice::create([
            'facility_id' => $otherFarm->facility_id,
            'device_name' => 'Beta Kiosk',
            'serial_number' => 'SN-' . uniqid(),
        ]);
        $directory = $this->makeGatesaleDirectory(0.236);
        $firstFarmRequest = $this->makeActiveGatesaleRequest($directory, ['facility_id' => $otherFarm->facility_id]);
        VisitorSession::create([
            'visitor_request_id' => $firstFarmRequest->visitor_request_id,
            'session_status' => 'Inside',
            'login_id' => 'FIRSTFARM',
            'first_in' => now(),
        ]);

        // Still active at BETA - ALPHA must deny.
        $this->recognize(['descriptor' => $this->descriptor(0.236)])
            ->assertStatus(403)->assertJson(['type' => 'gatesale_active_elsewhere']);

        // Complete the BETA visit, via BETA's own kiosk.
        $this->postJson("/kiosk/{$otherKiosk->kiosk_id}/entry", [
            'visitor_request_id' => $firstFarmRequest->visitor_request_id,
            'action' => 'final_exit',
        ], ['X-KIOSK-TOKEN' => $otherKiosk->kiosk_token])->assertOk();

        // No longer active anywhere - ALPHA must now allow a fresh visit.
        $secondFarmResponse = $this->recognize(['descriptor' => $this->descriptor(0.236)]);
        $secondFarmResponse->assertOk()->assertJson(['success' => false, 'type' => 'gatesale_confirm_identity']);
    }

    public function test_active_request_from_a_previous_day_at_the_same_farm_is_still_resumed(): void
    {
        // ACTIVE is the authoritative "currently ongoing" signal regardless
        // of date - the expired-session scheduler is what's responsible for
        // closing out anything genuinely stale, not this recognition check.
        $directory = $this->makeGatesaleDirectory(0.24);
        $activeRequest = $this->makeActiveGatesaleRequest($directory, ['visit_datetime' => now()->subDay()]);

        $response = $this->recognize(['descriptor' => $this->descriptor(0.24)]);

        $response->assertOk()->assertJson([
            'success' => true,
            'type' => 'gatesale_match',
            'visitor_request_id' => $activeRequest->visitor_request_id,
        ]);
    }

    public function test_after_leave_farm_scanning_again_shows_is_this_you_again(): void
    {
        $directory = $this->makeGatesaleDirectory(0.25);
        $create = $this->createVisit([
            'directory_id' => $directory->directory_id,
            'host_name' => 'H', 'origin' => 'O', 'purpose' => 'P',
        ]);
        $create->assertOk();
        $visitorRequestId = $create->json('visitor_request_id');

        // While ACTIVE, a rescan must resume - not re-confirm.
        $whileActive = $this->recognize(['descriptor' => $this->descriptor(0.25)]);
        $whileActive->assertOk()->assertJson(['success' => true, 'type' => 'gatesale_match']);

        $this->entry($visitorRequestId, 'final_exit')->assertOk();

        // No longer ACTIVE - a rescan must ask "Is this you?" again.
        $afterLeave = $this->recognize(['descriptor' => $this->descriptor(0.25)]);
        $afterLeave->assertOk()->assertJson(['success' => false, 'type' => 'gatesale_confirm_identity']);
    }

    public function test_update_details_changes_only_permitted_fields(): void
    {
        $directory = $this->makeGatesaleDirectory(0.30);

        $response = $this->updateDetails([
            'directory_id' => $directory->directory_id,
            'full_name' => 'Maria Updated Santos',
            'email' => 'updated@example.com',
            'phone' => '09179999999',
            'company' => 'New Co',
        ]);

        $response->assertOk()->assertJson(['success' => true]);

        $directory->refresh();
        $this->assertEquals('Maria Updated Santos', $directory->full_name);
        $this->assertEquals('updated@example.com', $directory->email);
        $this->assertEquals('09179999999', $directory->phone);
        $this->assertEquals('New Co', $directory->visitorProfile->company);
        $this->assertEquals($this->gatesaleType->visitor_type_id, $directory->visitorProfile->visitor_type_id);
        $this->assertEquals($this->visitorIdentityType->identity_type_id, $directory->identity_type_id);
        $this->assertEquals(0, VisitorRequest::count(), 'saving edited details alone must never create a request');
    }

    public function test_update_details_rejects_a_non_gatesale_directory(): void
    {
        $visitorDirectory = UserDirectory::create([
            'identity_type_id' => $this->visitorIdentityType->identity_type_id,
            'person_reference' => 'plain+' . uniqid() . '@example.com',
            'first_name' => 'Plain', 'last_name' => 'Visitor', 'full_name' => 'Plain Visitor',
        ]);
        VisitorProfile::create([
            'directory_id' => $visitorDirectory->directory_id,
            'visitor_type_id' => $this->visitorType->visitor_type_id,
        ]);

        $response = $this->updateDetails([
            'directory_id' => $visitorDirectory->directory_id,
            'full_name' => 'Hacked Name',
        ]);

        $response->assertStatus(422);
        $this->assertEquals('Plain Visitor', $visitorDirectory->fresh()->full_name);
    }

    public function test_sequential_create_visit_submissions_do_not_duplicate(): void
    {
        $directory = $this->makeGatesaleDirectory(0.40);
        $payload = [
            'directory_id' => $directory->directory_id,
            'host_name' => 'H', 'origin' => 'O', 'purpose' => 'P',
        ];

        // PHPUnit runs synchronously, so this proves the reuse-or-create
        // logic is correct under repeated calls rather than true
        // multi-process concurrency - the Cache::lock()/lockForUpdate()
        // combination is what protects the real concurrent case.
        $first = $this->createVisit($payload);
        $second = $this->createVisit($payload);

        $first->assertOk();
        $second->assertOk();
        $this->assertEquals(
            $first->json('visitor_request_id'),
            $second->json('visitor_request_id')
        );
        $this->assertEquals(1, VisitorRequest::count());
        $this->assertEquals(1, VisitorSession::count());
        $this->assertEquals(1, VisitorEntryLog::count());
    }

    public function test_register_identity_creates_directory_and_face_profile_only(): void
    {
        $response = $this->registerIdentity([
            'full_name' => 'Brand New Visitor',
            'company' => 'New Co',
            'email' => 'brandnew@example.com',
            'phone' => '09170001111',
            'descriptor' => $this->descriptor(0.50),
        ]);

        $response->assertOk()->assertJson(['success' => true]);

        $directory = UserDirectory::where('full_name', 'Brand New Visitor')->sole();
        $this->assertEquals($this->gatesaleType->visitor_type_id, $directory->visitorProfile->visitor_type_id);
        $this->assertEquals('New Co', $directory->visitorProfile->company);
        $this->assertEquals($this->visitorIdentityType->identity_type_id, $directory->identity_type_id);
        $this->assertEquals(1, FaceProfile::where('directory_id', $directory->directory_id)->count());

        // Registration alone must never create a visit.
        $this->assertEquals(0, VisitorRequest::count());
        $this->assertEquals(0, VisitorSession::count());
        $this->assertEquals(0, VisitorEntryLog::count());
    }

    public function test_register_identity_front_only_creates_a_single_front_embedding_row(): void
    {
        // FRONT-only via the plain 'descriptor' key - registerIdentity()'s
        // helper wraps it into {poses:{FRONT:{...}}}, exactly the shape a
        // guided capture that only obtained FRONT would send.
        $this->registerIdentity([
            'full_name' => 'Front Only Visitor',
            'company' => 'Co',
            'descriptor' => $this->descriptor(0.60),
        ])->assertOk()->assertJson(['success' => true]);

        $directory = UserDirectory::where('full_name', 'Front Only Visitor')->sole();
        $this->assertEquals(1, FaceProfileEmbedding::whereHas('faceProfile', fn ($q) => $q->where('directory_id', $directory->directory_id))->count());
        $embedding = FaceProfileEmbedding::whereHas('faceProfile', fn ($q) => $q->where('directory_id', $directory->directory_id))->sole();
        $this->assertEquals('FRONT', $embedding->pose);
    }

    public function test_register_identity_full_pose_creates_one_embedding_row_per_pose(): void
    {
        $response = $this->registerIdentity([
            'full_name' => 'Full Pose Visitor',
            'company' => 'Co',
            'poses' => [
                'FRONT' => ['descriptor' => $this->descriptor(0.70)],
                'LEFT' => ['descriptor' => $this->descriptor(0.71)],
                'RIGHT' => ['descriptor' => $this->descriptor(0.72)],
            ],
        ]);

        $response->assertOk()->assertJson(['success' => true]);

        $directory = UserDirectory::where('full_name', 'Full Pose Visitor')->sole();
        $faceProfile = FaceProfile::where('directory_id', $directory->directory_id)->sole();

        $this->assertEquals(3, $faceProfile->embeddings()->count());
        $byPose = $faceProfile->embeddings()->get()->keyBy('pose');
        $this->assertEquals($this->descriptor(0.70), $byPose['FRONT']->embedding);
        $this->assertEquals($this->descriptor(0.71), $byPose['LEFT']->embedding);
        $this->assertEquals($this->descriptor(0.72), $byPose['RIGHT']->embedding);

        // FRONT still populates the legacy face_profile columns - gatesale
        // registration never stores a face_image (unchanged from before
        // this feature), only the embedding.
        $this->assertEquals($this->descriptor(0.70), $faceProfile->embedding);
    }

    public function test_register_identity_partial_pose_never_fabricates_the_missing_pose(): void
    {
        $response = $this->registerIdentity([
            'full_name' => 'Partial Pose Visitor',
            'company' => 'Co',
            'poses' => [
                'FRONT' => ['descriptor' => $this->descriptor(0.80)],
                'LEFT' => ['descriptor' => $this->descriptor(0.81)],
            ],
        ]);

        $response->assertOk()->assertJson(['success' => true]);

        $directory = UserDirectory::where('full_name', 'Partial Pose Visitor')->sole();
        $faceProfile = FaceProfile::where('directory_id', $directory->directory_id)->sole();

        $this->assertEqualsCanonicalizing(['FRONT', 'LEFT'], $faceProfile->embeddings()->pluck('pose')->all());
    }

    public function test_register_identity_then_create_visit_completes_full_gatesale_flow(): void
    {
        $register = $this->registerIdentity([
            'full_name' => 'Brand New Visitor',
            'company' => 'New Co',
            'descriptor' => $this->descriptor(0.505),
        ]);
        $register->assertOk();
        $directoryId = $register->json('directory.directory_id');

        $visit = $this->createVisit([
            'directory_id' => $directoryId,
            'host_name' => 'Host Person',
            'origin' => 'Cebu',
            'purpose' => 'Delivery pickup',
        ]);

        $visit->assertOk()->assertJson(['success' => true, 'type' => 'gatesale_match']);
        $this->assertEquals('Inside', $visit->json('session_state.status'));
        $this->assertEquals(1, VisitorRequest::where('directory_id', $directoryId)->count());
        $this->assertEquals(1, VisitorSession::count());
        $this->assertEquals(1, VisitorEntryLog::count());
        $this->assertEquals('REGISTERED', VisitorRequest::where('directory_id', $directoryId)->sole()->face_registration_status);
    }

    public function test_register_identity_requires_full_name_and_company(): void
    {
        $response = $this->registerIdentity([
            'descriptor' => $this->descriptor(0.506),
        ]);

        $response->assertStatus(422);
        $this->assertEquals(0, UserDirectory::count());
        $this->assertEquals(0, FaceProfile::count());
    }

    public function test_register_identity_rejects_truck_visitor_type(): void
    {
        $response = $this->registerIdentity([
            'visitor_type' => 'Truck',
            'full_name' => 'Truck Driver',
            'company' => 'Logistics Co',
            'descriptor' => $this->descriptor(0.507),
        ]);

        $response->assertStatus(422);
        $this->assertEquals(0, UserDirectory::count());
    }

    public function test_register_identity_with_matching_gatesale_face_does_not_duplicate_and_routes_to_confirmation(): void
    {
        $directory = $this->makeGatesaleDirectory(0.51);

        $response = $this->registerIdentity([
            'full_name' => 'Should Not Be Used',
            'company' => 'C',
            'descriptor' => $this->descriptor(0.51),
        ]);

        $response->assertOk()->assertJson(['success' => false, 'type' => 'gatesale_confirm_identity']);
        $this->assertEquals($directory->directory_id, $response->json('directory.directory_id'));
        $this->assertEquals(1, UserDirectory::count());
        $this->assertEquals(1, FaceProfile::count());
    }

    public function test_register_identity_with_matching_gatesale_face_and_active_request_resumes_session(): void
    {
        $directory = $this->makeGatesaleDirectory(0.515);
        $activeRequest = $this->makeActiveGatesaleRequest($directory);

        $response = $this->registerIdentity([
            'full_name' => 'Should Not Be Used',
            'company' => 'C',
            'descriptor' => $this->descriptor(0.515),
        ]);

        $response->assertOk()->assertJson([
            'success' => true,
            'type' => 'gatesale_match',
            'visitor_request_id' => $activeRequest->visitor_request_id,
        ]);
        $this->assertEquals(1, UserDirectory::count());
    }

    public function test_register_identity_with_matching_non_gatesale_face_is_rejected_without_conversion(): void
    {
        $visitorDirectory = UserDirectory::create([
            'identity_type_id' => $this->visitorIdentityType->identity_type_id,
            'person_reference' => 'plain+' . uniqid() . '@example.com',
            'first_name' => 'Plain', 'last_name' => 'Visitor', 'full_name' => 'Plain Visitor',
        ]);
        $visitorProfile = VisitorProfile::create([
            'directory_id' => $visitorDirectory->directory_id,
            'visitor_type_id' => $this->visitorType->visitor_type_id,
        ]);
        FaceProfile::create([
            'directory_id' => $visitorDirectory->directory_id,
            'embedding' => $this->descriptor(0.52),
            'is_active' => true,
        ]);

        $response = $this->registerIdentity([
            'full_name' => 'Attempted Gatesale',
            'company' => 'C',
            'descriptor' => $this->descriptor(0.52),
        ]);

        $response->assertStatus(422);
        $this->assertEquals(1, UserDirectory::count());
        $this->assertEquals(1, VisitorProfile::count(), 'no duplicate/converted profile may be created');
        $this->assertEquals(1, FaceProfile::count());
        $this->assertEquals($this->visitorType->visitor_type_id, $visitorProfile->fresh()->visitor_type_id);
    }

    public function test_gatesale_full_lifecycle_never_writes_to_google_sheets(): void
    {
        $mockWriter = Mockery::mock(VisitorSheetWriter::class);
        $mockWriter->shouldNotReceive('appendTimeIn');
        $mockWriter->shouldNotReceive('appendTimeOut');
        $this->app->instance(VisitorSheetWriter::class, $mockWriter);

        $directory = $this->makeGatesaleDirectory(0.60);

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

    public function test_visitor_with_approval_google_sheets_behavior_is_unaffected(): void
    {
        $mockWriter = Mockery::mock(VisitorSheetWriter::class);
        $mockWriter->shouldReceive('appendTimeIn')->once();
        $mockWriter->shouldReceive('appendTimeOut')->once();
        $this->app->instance(VisitorSheetWriter::class, $mockWriter);

        $email = 'approved+' . uniqid() . '@example.com';
        $directory = UserDirectory::create([
            'identity_type_id' => $this->visitorIdentityType->identity_type_id,
            'person_reference' => $email,
            'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'full_name' => 'Juan Dela Cruz',
            'email' => $email,
        ]);
        VisitorProfile::create([
            'directory_id' => $directory->directory_id,
            'visitor_type_id' => $this->visitorType->visitor_type_id,
        ]);
        $visitorRequest = VisitorRequest::create([
            'directory_id' => $directory->directory_id,
            'visitor_id' => 'VIS-' . uniqid(),
            'facility_id' => $this->facility->facility_id,
            'host_name' => 'Host',
            'visit_datetime' => now(),
            'registration_token' => 'REG_' . Str::upper(Str::random(8)),
            'approval_status' => 'Approved',
        ]);

        $this->entry($visitorRequest->visitor_request_id, 'first_entry')->assertOk();
        $this->entry($visitorRequest->visitor_request_id, 'final_exit')->assertOk();
    }

    // ---- facility_list.is_gs eligibility gate ----

    public function test_gatesale_recognition_is_rejected_when_facility_is_not_gatesale_enabled(): void
    {
        $this->facility->update(['is_gs' => false]);
        $directory = $this->makeGatesaleDirectory(0.90);

        $response = $this->recognize(['descriptor' => $this->descriptor(0.90)]);

        $response->assertStatus(403)->assertJson(['success' => false, 'type' => 'gatesale_not_available']);
        $this->assertEquals(0, VisitorRequest::count());
    }

    public function test_gatesale_create_visit_is_rejected_directly_when_facility_is_not_gatesale_enabled(): void
    {
        // Defense-in-depth: reject even when a client calls create-visit
        // directly with a known directory_id, bypassing recognize() entirely.
        $this->facility->update(['is_gs' => false]);
        $directory = $this->makeGatesaleDirectory(0.91);

        $response = $this->createVisit([
            'directory_id' => $directory->directory_id,
            'host_name' => 'H', 'origin' => 'O', 'purpose' => 'P',
        ]);

        $response->assertStatus(403);
        $this->assertEquals(0, VisitorRequest::count());
    }

    public function test_gatesale_register_identity_is_rejected_when_facility_is_not_gatesale_enabled(): void
    {
        $this->facility->update(['is_gs' => false]);

        $response = $this->registerIdentity([
            'full_name' => 'Should Not Register',
            'company' => 'New Co',
            'descriptor' => $this->descriptor(0.92),
        ]);

        $response->assertStatus(403)->assertJson(['success' => false, 'type' => 'gatesale_not_available']);
        $this->assertEquals(0, UserDirectory::count());
        $this->assertEquals(0, FaceProfile::count());
    }

    public function test_gatesale_resuming_an_already_active_visit_is_unaffected_by_a_later_flag_flip(): void
    {
        // The flag only gates NEW visits - a visit created while the
        // facility was eligible must not be disrupted mid-visit if an admin
        // later flips is_gs off.
        $directory = $this->makeGatesaleDirectory(0.93);
        $activeRequest = $this->makeActiveGatesaleRequest($directory);
        VisitorSession::create([
            'visitor_request_id' => $activeRequest->visitor_request_id,
            'session_status' => 'Inside',
            'login_id' => 'EXIST002',
            'first_in' => now(),
        ]);

        $this->facility->update(['is_gs' => false]);

        $response = $this->recognize(['descriptor' => $this->descriptor(0.93)]);

        $response->assertOk()->assertJson(['success' => true, 'type' => 'gatesale_match']);
    }

    public function test_gatesale_full_flow_succeeds_when_facility_is_gatesale_enabled(): void
    {
        $this->facility->update(['is_gs' => true]);

        $register = $this->registerIdentity([
            'full_name' => 'Eligible Facility Visitor',
            'company' => 'New Co',
            'descriptor' => $this->descriptor(0.94),
        ]);
        $register->assertOk()->assertJson(['success' => true]);
        $directoryId = $register->json('directory.directory_id');

        $visit = $this->createVisit([
            'directory_id' => $directoryId,
            'host_name' => 'Host', 'origin' => 'Origin', 'purpose' => 'Purpose',
        ]);

        $visit->assertOk()->assertJson(['success' => true, 'type' => 'gatesale_match']);
        $this->assertEquals(1, VisitorRequest::where('directory_id', $directoryId)->count());
    }

    public function test_visitor_with_approval_flow_is_unaffected_by_facility_gatesale_flag(): void
    {
        // The is_gs flag only ever gates the Gatesale self-service branch -
        // the plain Visitor-with-Approval flow must keep working normally
        // at a facility with Gatesale disabled.
        $this->facility->update(['is_gs' => false]);

        $email = 'approved+' . uniqid() . '@example.com';
        $directory = UserDirectory::create([
            'identity_type_id' => $this->visitorIdentityType->identity_type_id,
            'person_reference' => $email,
            'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'full_name' => 'Juan Dela Cruz',
            'email' => $email,
        ]);
        VisitorProfile::create([
            'directory_id' => $directory->directory_id,
            'visitor_type_id' => $this->visitorType->visitor_type_id,
        ]);
        FaceProfile::create([
            'directory_id' => $directory->directory_id,
            'embedding' => $this->descriptor(0.95),
            'is_active' => true,
        ]);
        $visitorRequest = VisitorRequest::create([
            'directory_id' => $directory->directory_id,
            'visitor_id' => 'VIS-' . uniqid(),
            'facility_id' => $this->facility->facility_id,
            'host_name' => 'Host',
            'visit_datetime' => now(),
            'registration_token' => 'REG_' . Str::upper(Str::random(8)),
            'approval_status' => 'Approved',
        ]);

        $response = $this->recognize(['descriptor' => $this->descriptor(0.95)]);

        $response->assertOk()->assertJson([
            'success' => true,
            'type' => 'face_match',
            'visitor_request_id' => $visitorRequest->visitor_request_id,
        ]);
    }

    public function test_gatesale_eligibility_check_treats_a_kiosk_with_no_resolvable_facility_as_not_eligible(): void
    {
        // Simulates an invalid/nonexistent facility reference - the check
        // must fail safe (not eligible) rather than error or default open.
        // Built in-memory (never persisted) since kiosk_device.facility_id
        // has a NOT NULL + CASCADE foreign key and cannot actually reference
        // a nonexistent facility_list row in a real database.
        $kioskWithBadFacility = new KioskDevice(['facility_id' => 999999]);

        $method = new \ReflectionMethod(KioskController::class, 'isGatesaleEligibleFacility');
        $method->setAccessible(true);
        $controller = $this->app->make(KioskController::class);

        $this->assertFalse($method->invoke($controller, $kioskWithBadFacility));
    }
}
