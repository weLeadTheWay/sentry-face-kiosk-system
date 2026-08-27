<?php

namespace Tests\Unit;

use App\Models\IdentityType;
use App\Models\KioskDevice;
use App\Models\UserDirectory;
use App\Models\VisitorEntryLog;
use App\Models\VisitorRequest;
use App\Models\VisitorSession;
use App\Services\GoogleSheets\GoogleSheetsClient;
use App\Services\GoogleSheets\VisitorSheetWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\Concerns\CreatesFacilities;
use Tests\TestCase;

class VisitorSheetWriterTest extends TestCase
{
    use RefreshDatabase;
    use CreatesFacilities;

    private function makeEntryLog(string $loginId = 'XEGQNVH1', ?string $logoutId = 'GWDWS8FA'): VisitorEntryLog
    {
        $identityType = IdentityType::firstOrCreate(['identity_type_name' => 'Visitor']);
        $facility = $this->createFacility('ALPHA');
        $kiosk = KioskDevice::create(['facility_id' => $facility->facility_id, 'device_name' => 'K1', 'serial_number' => 'SN-' . uniqid()]);
        $email = 'juan+' . uniqid() . '@example.com';
        $directory = UserDirectory::create([
            'identity_type_id' => $identityType->identity_type_id,
            'person_reference' => $email,
            'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'full_name' => 'Louisa Reighn Alejo Santos',
            'email' => $email,
        ]);
        $visitorRequest = VisitorRequest::create([
            'directory_id' => $directory->directory_id,
            'visitor_id' => '08/06/2026-Louisa Reighn Alejo Santos-KLM012',
            'facility_id' => $facility->facility_id,
            'host_name' => 'Host',
            'visit_datetime' => now(),
            'registration_token' => 'REG_' . Str::upper(Str::random(8)),
            'approval_status' => 'Approved',
        ]);
        $session = VisitorSession::create([
            'visitor_request_id' => $visitorRequest->visitor_request_id,
            'session_status' => 'Inside',
            'login_id' => $loginId,
            'logout_id' => $logoutId,
            'first_in' => \Carbon\Carbon::parse('2026-08-06 14:15:46'),
            'last_out' => \Carbon\Carbon::parse('2026-08-06 17:30:05'),
        ]);

        return VisitorEntryLog::create([
            'visitor_session_id' => $session->visitor_session_id,
            'kiosk_id' => $kiosk->kiosk_id,
            'movement_type' => 'First Entry',
            'action' => 'IN',
            'authentication_method' => 'FACE',
            'photo' => 'kiosk-photos/16/first_entry-6a742692e29e3.jpg',
            'datetime' => now(),
        ]);
    }

    public function test_time_in_row_matches_required_format(): void
    {
        $log = $this->makeEntryLog();
        $mockClient = Mockery::mock(GoogleSheetsClient::class);
        $capturedRow = null;
        $mockClient->shouldReceive('appendRow')
            ->once()
            ->with(Mockery::any(), 'Time In!A:G', Mockery::on(function ($row) use (&$capturedRow) {
                $capturedRow = $row;
                return true;
            }));

        (new VisitorSheetWriter($mockClient))->appendTimeIn($log);

        [$dateIn, $timeIn, $name, $loginId, $visitorId, $picture, $pictureUrl] = $capturedRow;

        $this->assertEquals('8/06/2026', $dateIn, 'date must be M/DD/YYYY, month not zero-padded');
        $this->assertEquals('14:15:46', $timeIn, 'time must be 24-hour HH:mm:ss');
        $this->assertEquals('Louisa Reighn Alejo Santos', $name);
        $this->assertEquals('SNTRY-XEGQNVH1', $loginId, 'Login ID gets the SNTRY- prefix');
        $this->assertEquals('08/06/2026-Louisa Reighn Alejo Santos-KLM012', $visitorId, 'Visitor ID must NEVER be prefixed - stored/written exactly as received');
        $this->assertEquals('kiosk-photos/16/first_entry-6a742692e29e3.jpg', $picture);
        $this->assertStringStartsWith('http', $pictureUrl, 'picture_url must be a fully-qualified URL');
        $this->assertStringContainsString('/storage/kiosk-photos/16/first_entry-6a742692e29e3.jpg', $pictureUrl);
    }

    public function test_time_out_row_uses_logout_id_not_login_id(): void
    {
        $log = $this->makeEntryLog(loginId: 'XEGQNVH1', logoutId: 'GWDWS8FA');
        $mockClient = Mockery::mock(GoogleSheetsClient::class);
        $capturedRow = null;
        $mockClient->shouldReceive('appendRow')
            ->once()
            ->with(Mockery::any(), 'Time Out!A:G', Mockery::on(function ($row) use (&$capturedRow) {
                $capturedRow = $row;
                return true;
            }));

        (new VisitorSheetWriter($mockClient))->appendTimeOut($log);

        [$dateOut, $timeOut, $name, $logoutId, $visitorId, $picture, $pictureUrl] = $capturedRow;

        $this->assertEquals('8/06/2026', $dateOut);
        $this->assertEquals('17:30:05', $timeOut, 'time must be 24-hour HH:mm:ss');
        // Logout ID must be the session's own logout_id, NOT its login_id.
        $this->assertEquals('SNTRY-GWDWS8FA', $logoutId);
        $this->assertNotEquals('SNTRY-XEGQNVH1', $logoutId, 'Login ID and Logout ID must be independently generated values');
        $this->assertStringStartsWith('http', $pictureUrl);
    }

    public function test_sentry_prefix_is_not_doubled_if_already_present(): void
    {
        $log = $this->makeEntryLog(loginId: 'SNTRY-ALREADY', logoutId: 'SNTRY-ALSO-ALREADY');

        $mockClient = Mockery::mock(GoogleSheetsClient::class);
        $capturedTimeIn = null;
        $capturedTimeOut = null;
        $mockClient->shouldReceive('appendRow')->once()->with(Mockery::any(), 'Time In!A:G', Mockery::on(function ($row) use (&$capturedTimeIn) {
            $capturedTimeIn = $row;
            return true;
        }));
        $mockClient->shouldReceive('appendRow')->once()->with(Mockery::any(), 'Time Out!A:G', Mockery::on(function ($row) use (&$capturedTimeOut) {
            $capturedTimeOut = $row;
            return true;
        }));

        $writer = new VisitorSheetWriter($mockClient);
        $writer->appendTimeIn($log);
        $writer->appendTimeOut($log);

        $this->assertEquals('SNTRY-ALREADY', $capturedTimeIn[3]);
        $this->assertEquals('SNTRY-ALSO-ALREADY', $capturedTimeOut[3]);
    }
}
