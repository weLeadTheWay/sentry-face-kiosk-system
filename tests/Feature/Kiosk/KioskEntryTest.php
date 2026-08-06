<?php

namespace Tests\Feature\Kiosk;

use App\Models\FarmList;
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
use Tests\TestCase;

class KioskEntryTest extends TestCase
{
    use RefreshDatabase;

    private KioskDevice $kiosk;
    private VisitorRequest $visitorRequest;
    private UserDirectory $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $identityType = IdentityType::firstOrCreate(['identity_type_name' => 'Visitor']);
        $farm = FarmList::firstOrCreate(['farm_code' => 'ALPHA'], ['farm_name' => 'ALPHA']);
        $this->kiosk = KioskDevice::create([
            'farm_id' => $farm->farm_id,
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
            'farm_id' => $farm->farm_id,
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
            'farm_id' => $this->visitorRequest->farm_id,
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
        $otherFarm = FarmList::create(['farm_code' => 'BETA', 'farm_name' => 'BETA']);
        $mismatchedRequest = VisitorRequest::create([
            'directory_id' => $this->directory->directory_id,
            'visitor_id' => 'VIS-' . uniqid(),
            'farm_id' => $otherFarm->farm_id,
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
