<?php

namespace Tests\Feature\Console;

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

class ResolveExpiredVisitorSessionsTest extends TestCase
{
    use RefreshDatabase;

    private FarmList $farm;
    private KioskDevice $kiosk;
    private UserDirectory $directory;
    private UserDirectory $gatesaleDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $identityType = IdentityType::firstOrCreate(['identity_type_name' => 'Visitor']);
        $gatesaleType = VisitorType::firstOrCreate(['visitor_type_name' => 'Gatesale']);
        $this->farm = FarmList::firstOrCreate(['farm_code' => 'ALPHA'], ['farm_name' => 'ALPHA']);
        $this->kiosk = KioskDevice::create([
            'farm_id' => $this->farm->farm_id,
            'device_name' => 'Test Kiosk',
            'serial_number' => 'SN-' . uniqid(),
        ]);

        $email = 'expired+' . uniqid() . '@example.com';
        $this->directory = UserDirectory::create([
            'identity_type_id' => $identityType->identity_type_id,
            'person_reference' => $email,
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'full_name' => 'Juan Dela Cruz',
            'email' => $email,
        ]);

        $gatesaleEmail = 'gatesale-expired+' . uniqid() . '@example.com';
        $this->gatesaleDirectory = UserDirectory::create([
            'identity_type_id' => $identityType->identity_type_id,
            'visitor_type_id' => $gatesaleType->visitor_type_id,
            'person_reference' => $gatesaleEmail,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'full_name' => 'Maria Santos',
            'email' => $gatesaleEmail,
        ]);
    }

    private function makeVisitorRequest(array $overrides = []): VisitorRequest
    {
        return VisitorRequest::create(array_merge([
            'directory_id' => $this->directory->directory_id,
            'visitor_id' => 'VIS-' . uniqid(),
            'farm_id' => $this->farm->farm_id,
            'host_name' => 'Host',
            'visit_datetime' => now()->subDay(),
            'departure_datetime' => now()->subDay(),
            'registration_token' => 'REG_' . Str::upper(Str::random(8)),
            'approval_status' => 'Approved',
            'request_status' => 'ACTIVE',
        ], $overrides));
    }

    private function makeSession(VisitorRequest $visitorRequest, string $status, array $overrides = []): VisitorSession
    {
        return VisitorSession::create(array_merge([
            'visitor_request_id' => $visitorRequest->visitor_request_id,
            'session_status' => $status,
            'login_id' => 'LOGIN' . uniqid(),
            'first_in' => now()->subDay()->setTime(12, 30),
        ], $overrides));
    }

    private function makeEntryLog(VisitorSession $session, string $movementType, string $action, $datetime): VisitorEntryLog
    {
        return VisitorEntryLog::create([
            'visitor_session_id' => $session->visitor_session_id,
            'kiosk_id' => $this->kiosk->kiosk_id,
            'movement_type' => $movementType,
            'action' => $action,
            'authentication_method' => 'FACE',
            'datetime' => $datetime,
        ]);
    }

    private function mockSheetWriter(): void
    {
        $mock = Mockery::mock(VisitorSheetWriter::class);
        $mock->shouldReceive('appendTimeOut')->never();
        $this->app->instance(VisitorSheetWriter::class, $mock);
    }

    // ---- Test 1: forgot to Leave Farm ----
    public function test_inside_session_past_departure_is_marked_incomplete_with_no_sheets_write(): void
    {
        $this->mockSheetWriter();

        $visitorRequest = $this->makeVisitorRequest();
        $session = $this->makeSession($visitorRequest, 'Inside');
        $this->makeEntryLog($session, 'First Entry', 'IN', $session->first_in);

        $this->artisan('visitor:resolve-expired-sessions')->assertExitCode(0);

        $visitorRequest->refresh();
        $session->refresh();

        $this->assertEquals('INCOMPLETE', $visitorRequest->request_status);
        $this->assertEquals('INCOMPLETE', $session->session_status);
        $this->assertNull($session->last_out);
    }

    // ---- Test 2: temporary exit, never returned ----
    public function test_outside_session_past_departure_is_marked_completed_auto_with_recovered_last_out(): void
    {
        $mock = Mockery::mock(VisitorSheetWriter::class);
        $mock->shouldReceive('appendTimeOut')->once();
        $this->app->instance(VisitorSheetWriter::class, $mock);

        $visitorRequest = $this->makeVisitorRequest();
        $session = $this->makeSession($visitorRequest, 'Outside');
        $this->makeEntryLog($session, 'First Entry', 'IN', $session->first_in);
        $outLog = $this->makeEntryLog($session, 'Temporary Exit', 'OUT', now()->subDay()->setTime(15, 0));

        $this->artisan('visitor:resolve-expired-sessions')->assertExitCode(0);

        $visitorRequest->refresh();
        $session->refresh();

        $this->assertEquals('COMPLETED_AUTO', $visitorRequest->request_status);
        $this->assertEquals('COMPLETED_AUTO', $session->session_status);
        $this->assertEquals($outLog->datetime->toDateTimeString(), $session->last_out->toDateTimeString());
        $this->assertNotEmpty($session->logout_id);
        $this->assertNotEquals($session->login_id, $session->logout_id);
    }

    // ---- Test 3: not yet expired ----
    public function test_request_not_yet_expired_is_untouched(): void
    {
        $this->mockSheetWriter();

        $visitorRequest = $this->makeVisitorRequest([
            'visit_datetime' => now(),
            'departure_datetime' => now()->addDay(),
        ]);
        $session = $this->makeSession($visitorRequest, 'Inside');

        $this->artisan('visitor:resolve-expired-sessions')->assertExitCode(0);

        $this->assertEquals('ACTIVE', $visitorRequest->fresh()->request_status);
        $this->assertEquals('Inside', $session->fresh()->session_status);
    }

    // ---- Test 4: already COMPLETED ----
    public function test_already_completed_request_is_untouched(): void
    {
        $this->mockSheetWriter();

        $visitorRequest = $this->makeVisitorRequest(['request_status' => 'COMPLETED']);
        $session = $this->makeSession($visitorRequest, 'Completed');

        $this->artisan('visitor:resolve-expired-sessions')->assertExitCode(0);

        $this->assertEquals('COMPLETED', $visitorRequest->fresh()->request_status);
        $this->assertEquals('Completed', $session->fresh()->session_status);
    }

    // ---- Test 5: already INCOMPLETE ----
    public function test_already_incomplete_request_is_untouched(): void
    {
        $this->mockSheetWriter();

        $visitorRequest = $this->makeVisitorRequest(['request_status' => 'INCOMPLETE']);
        $session = $this->makeSession($visitorRequest, 'INCOMPLETE');

        $this->artisan('visitor:resolve-expired-sessions')->assertExitCode(0);

        $this->assertEquals('INCOMPLETE', $visitorRequest->fresh()->request_status);
        $this->assertEquals('INCOMPLETE', $session->fresh()->session_status);
    }

    // ---- Test 6: no-show (no session at all) ----
    public function test_expired_request_with_no_session_at_all_is_marked_incomplete(): void
    {
        $this->mockSheetWriter();

        $visitorRequest = $this->makeVisitorRequest();

        $this->artisan('visitor:resolve-expired-sessions')->assertExitCode(0);

        $this->assertEquals('INCOMPLETE', $visitorRequest->fresh()->request_status);
        $this->assertEquals(0, VisitorSession::where('visitor_request_id', $visitorRequest->visitor_request_id)->count());
    }

    // ---- Test 7: null departure_datetime falls back to end of visit day ----
    public function test_null_departure_datetime_falls_back_to_end_of_visit_day(): void
    {
        $this->mockSheetWriter();

        $visitorRequest = $this->makeVisitorRequest([
            'visit_datetime' => now()->subDay(),
            'departure_datetime' => null,
        ]);
        $session = $this->makeSession($visitorRequest, 'Inside');

        $this->artisan('visitor:resolve-expired-sessions')->assertExitCode(0);

        $this->assertEquals('INCOMPLETE', $visitorRequest->fresh()->request_status);
        $this->assertEquals('INCOMPLETE', $session->fresh()->session_status);
    }

    // ---- Test 8: Outside with no OUT log at all ----
    public function test_outside_session_with_no_out_log_falls_back_to_incomplete(): void
    {
        $this->mockSheetWriter();

        $visitorRequest = $this->makeVisitorRequest();
        $session = $this->makeSession($visitorRequest, 'Outside');
        // Deliberately no entry logs at all - data inconsistency scenario.

        $this->artisan('visitor:resolve-expired-sessions')->assertExitCode(0);

        $this->assertEquals('INCOMPLETE', $visitorRequest->fresh()->request_status);
        $this->assertEquals('INCOMPLETE', $session->fresh()->session_status);
        $this->assertNull($session->fresh()->last_out);
    }

    // ---- Test 9: terminal states actually block reuse via /entry directly ----
    // (Not tested via /recognize: that path is already excluded by
    // activeToday()'s own date window once departure has passed, which
    // would mask whether the isCompleted() broadening itself is doing
    // anything. VisitorKioskService::processEntry() has no date-window
    // check at all - isCompleted() is the ONLY thing standing between a
    // stale/direct request and creating a second session for an
    // already-resolved visit, which is exactly what this proves.)
    public function test_auto_resolved_request_is_blocked_from_reuse_via_process_entry(): void
    {
        $this->mockSheetWriter();

        $visitorRequest = $this->makeVisitorRequest();
        $session = $this->makeSession($visitorRequest, 'Inside');

        $this->artisan('visitor:resolve-expired-sessions')->assertExitCode(0);
        $this->assertEquals('INCOMPLETE', $visitorRequest->fresh()->request_status);

        $response = $this->postJson("/kiosk/{$this->kiosk->kiosk_id}/entry", [
            'visitor_request_id' => $visitorRequest->visitor_request_id,
            'action' => 'first_entry',
        ], ['X-KIOSK-TOKEN' => $this->kiosk->kiosk_token]);

        $response->assertStatus(400)->assertJsonFragment(['success' => false]);
        $this->assertEquals(
            1,
            VisitorSession::where('visitor_request_id', $visitorRequest->visitor_request_id)->count(),
            'no new session may be created for a request already auto-resolved'
        );
    }

    // ---- Test 10: idempotency across repeated runs ----
    public function test_running_the_command_twice_makes_no_further_changes_and_writes_sheets_only_once(): void
    {
        $mock = Mockery::mock(VisitorSheetWriter::class);
        $mock->shouldReceive('appendTimeOut')->once();
        $this->app->instance(VisitorSheetWriter::class, $mock);

        $visitorRequest = $this->makeVisitorRequest();
        $session = $this->makeSession($visitorRequest, 'Outside');
        $this->makeEntryLog($session, 'Temporary Exit', 'OUT', now()->subDay()->setTime(15, 0));

        $this->artisan('visitor:resolve-expired-sessions')->assertExitCode(0);
        $afterFirstRun = $visitorRequest->fresh();
        $sessionAfterFirstRun = $session->fresh();

        $this->artisan('visitor:resolve-expired-sessions')->assertExitCode(0);

        $this->assertEquals($afterFirstRun->request_status, $visitorRequest->fresh()->request_status);
        $this->assertEquals($afterFirstRun->updated_at, $visitorRequest->fresh()->updated_at);
        $this->assertEquals($sessionAfterFirstRun->updated_at, $session->fresh()->updated_at);
    }

    // ---- Test 11: duplicate/historical sessions - resolve the newest ----
    public function test_resolves_the_most_recent_session_when_duplicates_exist(): void
    {
        $mock = Mockery::mock(VisitorSheetWriter::class);
        $mock->shouldReceive('appendTimeOut')->once();
        $this->app->instance(VisitorSheetWriter::class, $mock);

        $visitorRequest = $this->makeVisitorRequest();

        // Older, already-closed session (should be ignored).
        $oldSession = $this->makeSession($visitorRequest, 'Completed', [
            'first_in' => now()->subDays(3),
            'last_out' => now()->subDays(3)->addHour(),
            'completed_at' => now()->subDays(3)->addHour(),
        ]);

        // Newer active session that should actually be resolved.
        $newSession = $this->makeSession($visitorRequest, 'Outside');
        $this->makeEntryLog($newSession, 'Temporary Exit', 'OUT', now()->subDay()->setTime(15, 0));

        $this->artisan('visitor:resolve-expired-sessions')->assertExitCode(0);

        $this->assertEquals('Completed', $oldSession->fresh()->session_status, 'the old session must not be touched');
        $this->assertEquals('COMPLETED_AUTO', $newSession->fresh()->session_status);
    }

    // ---- Test 12: Gatesale Outside+expired auto-completes without a Sheets write ----
    public function test_gatesale_outside_session_auto_completes_without_google_sheets_write(): void
    {
        $mock = Mockery::mock(VisitorSheetWriter::class);
        $mock->shouldReceive('appendTimeOut')->never();
        $this->app->instance(VisitorSheetWriter::class, $mock);

        $visitorRequest = $this->makeVisitorRequest([
            'directory_id' => $this->gatesaleDirectory->directory_id,
            'visitor_id' => null,
            'registration_token' => null,
        ]);
        $session = $this->makeSession($visitorRequest, 'Outside');
        $this->makeEntryLog($session, 'Temporary Exit', 'OUT', now()->subDay()->setTime(15, 0));

        $this->artisan('visitor:resolve-expired-sessions')->assertExitCode(0);

        $this->assertEquals('COMPLETED_AUTO', $visitorRequest->fresh()->request_status);
        $this->assertEquals('COMPLETED_AUTO', $session->fresh()->session_status);
    }

    // ---- Test 13: Gatesale Inside+expired resolves to INCOMPLETE, still no Sheets write ----
    public function test_gatesale_inside_session_resolves_incomplete_without_google_sheets_write(): void
    {
        $mock = Mockery::mock(VisitorSheetWriter::class);
        $mock->shouldReceive('appendTimeOut')->never();
        $this->app->instance(VisitorSheetWriter::class, $mock);

        $visitorRequest = $this->makeVisitorRequest([
            'directory_id' => $this->gatesaleDirectory->directory_id,
            'visitor_id' => null,
            'registration_token' => null,
        ]);
        $session = $this->makeSession($visitorRequest, 'Inside');
        $this->makeEntryLog($session, 'First Entry', 'IN', $session->first_in);

        $this->artisan('visitor:resolve-expired-sessions')->assertExitCode(0);

        $this->assertEquals('INCOMPLETE', $visitorRequest->fresh()->request_status);
        $this->assertEquals('INCOMPLETE', $session->fresh()->session_status);
    }
}
