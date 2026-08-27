<?php

namespace Tests\Feature\Kiosk;

use App\Models\IdentityType;
use App\Models\KioskDevice;
use App\Models\UserDirectory;
use App\Models\VisitorRequest;
use App\Services\GoogleSheets\GoogleSheetsClient;
use App\Services\Kiosk\VisitorKioskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\Concerns\CreatesFacilities;
use Tests\TestCase;

/**
 * Regression coverage for a real production bug: VisitorKioskService's
 * `?VisitorSheetWriter $sheetWriter = null` constructor parameter silently
 * resolved to null via container auto-wiring (with zero exceptions/logs)
 * because Laravel's container returns a parameter's default value without
 * even attempting resolution when the class has no explicit binding
 * (Container::resolveClass()). Every other test in this suite mocks
 * VisitorSheetWriter directly via $this->app->instance(), which itself
 * registers a binding and incidentally masks this exact failure mode - so
 * this test deliberately mocks only the deepest real boundary
 * (GoogleSheetsClient) and lets VisitorSheetWriter/VisitorKioskService
 * resolve for real through the container, the same way KioskController does.
 */
class GoogleSheetsIntegrationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesFacilities;

    public function test_container_resolved_kiosk_service_actually_has_a_sheet_writer(): void
    {
        $service = app(VisitorKioskService::class);

        $ref = new \ReflectionProperty($service, 'sheetWriter');
        $ref->setAccessible(true);

        $this->assertNotNull(
            $ref->getValue($service),
            'VisitorSheetWriter must not silently resolve to null - see class docblock for why this regresses easily.'
        );
    }

    public function test_first_entry_and_final_exit_actually_invoke_the_google_sheets_client(): void
    {
        $mockClient = Mockery::mock(GoogleSheetsClient::class);
        $mockClient->shouldReceive('appendRow')->once()->with(
            \Mockery::any(), 'Time In!A:G', \Mockery::any()
        );
        $mockClient->shouldReceive('appendRow')->once()->with(
            \Mockery::any(), 'Time Out!A:G', \Mockery::any()
        );
        $this->app->instance(GoogleSheetsClient::class, $mockClient);

        $identityType = IdentityType::firstOrCreate(['identity_type_name' => 'Visitor']);
        $facility = $this->createFacility('ALPHA');
        $kiosk = KioskDevice::create([
            'facility_id' => $facility->facility_id,
            'device_name' => 'Test Kiosk',
            'serial_number' => 'SN-' . uniqid(),
        ]);
        $email = 'sheets+' . uniqid() . '@example.com';
        $directory = UserDirectory::create([
            'identity_type_id' => $identityType->identity_type_id,
            'person_reference' => $email,
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'full_name' => 'Juan Dela Cruz',
            'email' => $email,
        ]);
        $visitorRequest = VisitorRequest::create([
            'directory_id' => $directory->directory_id,
            'visitor_id' => 'VIS-' . uniqid(),
            'facility_id' => $facility->facility_id,
            'host_name' => 'Host',
            'visit_datetime' => now(),
            'registration_token' => 'REG_' . Str::upper(Str::random(8)),
            'approval_status' => 'Approved',
        ]);

        // Resolved via the container - not `new` - exactly like KioskController does.
        $service = app(VisitorKioskService::class);

        $first = $service->processEntry($visitorRequest->visitor_request_id, 'first_entry', $kiosk, null, 'FACE');
        $this->assertTrue($first['success']);

        $last = $service->processEntry($visitorRequest->visitor_request_id, 'final_exit', $kiosk, null, 'FACE');
        $this->assertTrue($last['success']);
    }
}
