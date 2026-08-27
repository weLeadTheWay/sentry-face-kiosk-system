<?php

namespace Tests\Feature\Visitor;

use App\Models\IdentityType;
use App\Models\UserDirectory;
use App\Models\VisitorRequest;
use App\Services\Qr\VisitorQrCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesFacilities;
use Tests\TestCase;

class VisitorQrCodeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesFacilities;

    private function makeVisitorRequest(array $overrides = []): VisitorRequest
    {
        $identityType = IdentityType::firstOrCreate(['identity_type_name' => 'Visitor']);
        $facility = $this->createFacility('ALPHA');
        $email = 'juan+' . uniqid() . '@example.com';

        $directory = UserDirectory::create([
            'identity_type_id' => $identityType->identity_type_id,
            'person_reference' => $email,
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'full_name' => 'Juan Dela Cruz',
            'email' => $email,
        ]);

        return VisitorRequest::create(array_merge([
            'directory_id' => $directory->directory_id,
            'visitor_id' => 'VIS-' . uniqid(),
            'facility_id' => $facility->facility_id,
            'host_name' => 'Host',
            'visit_datetime' => now(),
            'registration_token' => 'REG_' . Str::upper(Str::random(8)),
            'approval_status' => 'Approved',
        ], $overrides));
    }

    public function test_qr_generator_encodes_exact_payload(): void
    {
        $png = (new VisitorQrCodeService())->generate('VIS-TEST-123');
        $this->assertNotEmpty($png);
        $this->assertStringStartsWith("\x89PNG", $png);
    }

    public function test_qr_route_returns_404_when_pending(): void
    {
        $visitorRequest = $this->makeVisitorRequest();

        $response = $this->get('/register/visitor/qr?token=' . $visitorRequest->registration_token);

        $response->assertNotFound();
    }

    public function test_qr_route_returns_png_when_registered(): void
    {
        $visitorRequest = $this->makeVisitorRequest(['face_registration_status' => 'REGISTERED']);

        $response = $this->get('/register/visitor/qr?token=' . $visitorRequest->registration_token);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
    }

    public function test_qr_route_returns_png_when_failed_match(): void
    {
        $visitorRequest = $this->makeVisitorRequest(['face_registration_status' => 'FAILED_MATCH']);

        $response = $this->get('/register/visitor/qr?token=' . $visitorRequest->registration_token);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
    }

    public function test_qr_download_sets_content_disposition(): void
    {
        $visitorRequest = $this->makeVisitorRequest(['face_registration_status' => 'REGISTERED']);

        $response = $this->get('/register/visitor/qr?token=' . $visitorRequest->registration_token . '&download=1');

        $response->assertOk();
        $response->assertHeader('Content-Disposition', 'attachment; filename="visitor-qr-' . $visitorRequest->visitor_id . '.png"');
    }

    public function test_qr_renders_and_downloads_with_realistic_appsheet_visitor_id(): void
    {
        // Real-world shape: slashes and spaces from AppSheet's
        // date/name-composed visitor_id format, stored exactly as received
        // (no SNTRY- or any other prefix - see VisitorSyncServiceTest for
        // the "stored exactly as received" guarantee).
        $visitorRequest = $this->makeVisitorRequest([
            'face_registration_status' => 'REGISTERED',
            'visitor_id' => '08/06/2026-Louisa Reighn Alejo Santos-KLM012',
        ]);

        $viewResponse = $this->get('/register/visitor/qr?token=' . $visitorRequest->registration_token);
        $viewResponse->assertOk();
        $viewResponse->assertHeader('Content-Type', 'image/png');

        $downloadResponse = $this->get('/register/visitor/qr?token=' . $visitorRequest->registration_token . '&download=1');
        $downloadResponse->assertOk();
        // Slashes/spaces must not leak unescaped into the filename.
        $disposition = $downloadResponse->headers->get('Content-Disposition');
        $this->assertStringNotContainsString('/', $disposition);
        $this->assertStringStartsWith('attachment; filename="visitor-qr-08_06_2026-Louisa', $disposition);
    }
}
