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
        $directory = UserDirectory::create([
            'identity_type_id' => $identityType->identity_type_id,
            'person_reference' => $email,
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'full_name' => 'Juan Dela Cruz',
            'email' => $email,
        ]);

        $this->visitorRequest = VisitorRequest::create([
            'directory_id' => $directory->directory_id,
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
