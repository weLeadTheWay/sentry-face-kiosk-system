<?php

namespace Tests\Feature\Kiosk;

use App\Models\IdentityType;
use App\Models\KioskDevice;
use App\Models\UserDirectory;
use App\Models\VisitorEntryLog;
use App\Models\VisitorRequest;
use App\Models\VisitorSession;
use App\Services\GoogleSheets\VisitorSheetWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\Concerns\CreatesFacilities;
use Tests\TestCase;

class KioskEntryTest extends TestCase
{
    use RefreshDatabase;
    use CreatesFacilities;

    private KioskDevice $kiosk;
    private VisitorRequest $visitorRequest;
    private UserDirectory $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $identityType = IdentityType::firstOrCreate(['identity_type_name' => 'Visitor']);
        $facility = $this->createFacility('ALPHA');
        $this->kiosk = KioskDevice::create([
            'facility_id' => $facility->facility_id,
            'device_name' => 'Test Kiosk',
            'serial_number' => 'SN-' . uniqid(),
        ]);

        $email = 'visitor+' . uniqid() . '@example.com';
        $this->directory = UserDirectory::create([
            'identity_type_id' => $identityType->identity_type_id,
            'person_reference' => $email,
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'full_name' => 'Juan Dela Cruz',
            'email' => $email,
        ]);

        $this->visitorRequest = VisitorRequest::create([
            'directory_id' => $this->directory->directory_id,
            'visitor_id' => 'VIS-' . uniqid(),
            'facility_id' => $facility->facility_id,
            'host_name' => 'Host',
            'visit_datetime' => now(),
            'registration_token' => 'REG_' . Str::upper(Str::random(8)),
            'approval_status' => 'Approved',
        ]);
    }

    private function entry(string $action, string $authMethod = 'FACE')
    {
        return $this->postJson("/kiosk/{$this->kiosk->kiosk_id}/entry", [
            'visitor_request_id' => $this->visitorRequest->visitor_request_id,
            'action' => $action,
            'authentication_method' => $authMethod,
        ], [
            'X-KIOSK-TOKEN' => $this->kiosk->kiosk_token,
        ]);
    }

    public function test_authentication_method_is_persisted_across_full_lifecycle(): void
    {
        $this->entry('first_entry', 'FACE')->assertOk();
        $this->entry('temporary_exit', 'QR')->assertOk();
        $this->entry('return', 'FACE')->assertOk();
        $this->entry('final_exit', 'QR')->assertOk();

        $logs = VisitorEntryLog::whereHas('session', function ($q) {
            $q->where('visitor_request_id', $this->visitorRequest->visitor_request_id);
        })->orderBy('visitor_log_id')->get();

        $this->assertEquals(
            ['First Entry', 'Temporary Exit', 'Return', 'Final Exit'],
            $logs->pluck('movement_type')->all()
        );
        $this->assertEquals(['FACE', 'QR', 'FACE', 'QR'], $logs->pluck('authentication_method')->all());
    }

    public function test_exactly_one_session_created_across_full_lifecycle(): void
    {
        $this->entry('first_entry')->assertOk();
        $this->entry('temporary_exit')->assertOk();
        $this->entry('return')->assertOk();
        $this->entry('final_exit')->assertOk();

        $this->assertEquals(
            1,
            VisitorSession::where('visitor_request_id', $this->visitorRequest->visitor_request_id)->count()
        );
    }

    public function test_final_exit_marks_request_completed_and_blocks_further_entries(): void
    {
        $this->entry('first_entry')->assertOk();
        $this->entry('final_exit')->assertOk();

        $this->assertEquals('COMPLETED', $this->visitorRequest->fresh()->request_status);

        $response = $this->entry('first_entry');
        $response->assertStatus(400)->assertJsonFragment(['success' => false]);

        $this->assertEquals(
            1,
            VisitorSession::where('visitor_request_id', $this->visitorRequest->visitor_request_id)->count()
        );
    }

    public function test_temporary_exit_is_rejected_when_facility_disables_breaks(): void
    {
        $facility = $this->createFacility('MADERA', 'Madera', isBreakEnabled: false);
        $kiosk = KioskDevice::create([
            'facility_id' => $facility->facility_id,
            'device_name' => 'Break-Disabled Kiosk',
            'serial_number' => 'SN-' . uniqid(),
        ]);
        $visitorRequest = VisitorRequest::create([
            'directory_id' => $this->directory->directory_id,
            'visitor_id' => 'VIS-' . uniqid(),
            'facility_id' => $facility->facility_id,
            'host_name' => 'Host',
            'visit_datetime' => now(),
            'registration_token' => 'REG_' . Str::upper(Str::random(8)),
            'approval_status' => 'Approved',
        ]);

        $entry = function (string $action) use ($kiosk, $visitorRequest) {
            return $this->postJson("/kiosk/{$kiosk->kiosk_id}/entry", [
                'visitor_request_id' => $visitorRequest->visitor_request_id,
                'action' => $action,
            ], ['X-KIOSK-TOKEN' => $kiosk->kiosk_token]);
        };

        $entry('first_entry')->assertOk();

        // The intermediate break must be rejected server-side, independent
        // of whether the kiosk frontend even offers the button.
        $entry('temporary_exit')
            ->assertStatus(400)
            ->assertJson(['success' => false]);

        $session = VisitorSession::where('visitor_request_id', $visitorRequest->visitor_request_id)->first();
        $this->assertEquals('Inside', $session->session_status);

        // Leave Farm remains directly available - strictly one IN -> one OUT.
        $entry('final_exit')->assertOk()->assertJson(['success' => true]);
        $this->assertEquals('COMPLETED', $visitorRequest->fresh()->request_status);

        $logs = VisitorEntryLog::whereHas('session', function ($q) use ($visitorRequest) {
            $q->where('visitor_request_id', $visitorRequest->visitor_request_id);
        })->orderBy('visitor_log_id')->pluck('movement_type')->all();
        $this->assertEquals(['First Entry', 'Final Exit'], $logs);
    }

    public function test_already_started_break_is_not_retroactively_invalidated_by_a_later_flag_flip(): void
    {
        // Break enabled at the time the temporary exit is started...
        $this->entry('first_entry')->assertOk();
        $this->entry('temporary_exit')->assertOk();

        // ...then an admin disables breaks for this facility mid-visit.
        $this->visitorRequest->facility->update(['is_break_enabled' => false]);

        // The visitor must still be able to Return and Leave Farm normally -
        // the flag only prevents STARTING a new break, never resuming one
        // already in progress.
        $this->entry('return')->assertOk()->assertJson(['success' => true, 'session_status' => 'Inside']);
        $this->entry('final_exit')->assertOk()->assertJson(['success' => true]);
        $this->assertEquals('COMPLETED', $this->visitorRequest->fresh()->request_status);
    }

    public function test_sheets_write_failure_does_not_block_entry(): void
    {
        $this->app->instance(VisitorSheetWriter::class, Mockery::mock(VisitorSheetWriter::class)
            ->shouldReceive('appendTimeIn')->andThrow(new \Exception('Sheets down'))
            ->shouldReceive('appendTimeOut')->andThrow(new \Exception('Sheets down'))
            ->getMock());

        $firstEntry = $this->entry('first_entry');
        $firstEntry->assertOk()->assertJson(['success' => true]);

        $finalExit = $this->entry('final_exit');
        $finalExit->assertOk()->assertJson(['success' => true]);

        $this->assertEquals(
            1,
            VisitorSession::where('visitor_request_id', $this->visitorRequest->visitor_request_id)->count()
        );
        $this->assertEquals('COMPLETED', $this->visitorRequest->fresh()->request_status);
    }

    public function test_login_id_is_generated_at_first_entry(): void
    {
        $this->entry('first_entry')->assertOk();

        $session = VisitorSession::where('visitor_request_id', $this->visitorRequest->visitor_request_id)->first();
        $this->assertNotEmpty($session->login_id);
        $this->assertNull($session->logout_id, 'logout_id must not exist until final_exit');
    }

    public function test_logout_id_is_generated_at_final_exit_independently_of_login_id(): void
    {
        $this->entry('first_entry')->assertOk();
        $session = VisitorSession::where('visitor_request_id', $this->visitorRequest->visitor_request_id)->first();
        $loginId = $session->login_id;

        $this->entry('final_exit')->assertOk();
        $session->refresh();

        $this->assertNotEmpty($session->logout_id);
        $this->assertEquals($loginId, $session->login_id, 'login_id must not change at final_exit');
        $this->assertNotEquals($loginId, $session->logout_id, 'login_id and logout_id must be independently generated');
    }

    public function test_two_separate_visits_by_the_same_person_get_different_login_ids(): void
    {
        $this->entry('first_entry')->assertOk();
        $firstSession = VisitorSession::where('visitor_request_id', $this->visitorRequest->visitor_request_id)->first();
        $this->entry('final_exit')->assertOk();

        $secondRequest = VisitorRequest::create([
            'directory_id' => $this->directory->directory_id,
            'visitor_id' => 'VIS-' . uniqid(),
            'facility_id' => $this->visitorRequest->facility_id,
            'host_name' => 'Host',
            'visit_datetime' => now(),
            'registration_token' => 'REG_' . Str::upper(Str::random(8)),
            'approval_status' => 'Approved',
        ]);
        $this->postJson("/kiosk/{$this->kiosk->kiosk_id}/entry", [
            'visitor_request_id' => $secondRequest->visitor_request_id,
            'action' => 'first_entry',
        ], ['X-KIOSK-TOKEN' => $this->kiosk->kiosk_token])->assertOk();
        $secondSession = VisitorSession::where('visitor_request_id', $secondRequest->visitor_request_id)->first();

        $this->assertNotEquals(
            $firstSession->login_id,
            $secondSession->login_id,
            'the same person visiting again must get a brand new Login ID, not a reused per-person one'
        );
    }

    public function test_entry_is_rejected_for_farm_mismatched_visitor_request(): void
    {
        $otherFacility = $this->createFacility('BETA');
        $mismatchedRequest = VisitorRequest::create([
            'directory_id' => $this->directory->directory_id,
            'visitor_id' => 'VIS-' . uniqid(),
            'facility_id' => $otherFacility->facility_id,
            'host_name' => 'Host',
            'visit_datetime' => now(),
            'registration_token' => 'REG_' . Str::upper(Str::random(8)),
            'approval_status' => 'Approved',
        ]);

        $response = $this->postJson("/kiosk/{$this->kiosk->kiosk_id}/entry", [
            'visitor_request_id' => $mismatchedRequest->visitor_request_id,
            'action' => 'first_entry',
        ], ['X-KIOSK-TOKEN' => $this->kiosk->kiosk_token]);

        $response->assertStatus(400)->assertJsonFragment(['success' => false]);
        $this->assertEquals(
            0,
            VisitorSession::where('visitor_request_id', $mismatchedRequest->visitor_request_id)->count(),
            'no session may be created for a farm mismatch, even calling /entry directly'
        );
    }

    public function test_authentication_method_defaults_to_face_when_omitted(): void
    {
        $this->postJson("/kiosk/{$this->kiosk->kiosk_id}/entry", [
            'visitor_request_id' => $this->visitorRequest->visitor_request_id,
            'action' => 'first_entry',
        ], [
            'X-KIOSK-TOKEN' => $this->kiosk->kiosk_token,
        ])->assertOk();

        $log = VisitorEntryLog::whereHas('session', function ($q) {
            $q->where('visitor_request_id', $this->visitorRequest->visitor_request_id);
        })->first();

        $this->assertEquals('FACE', $log->authentication_method);
    }
}
