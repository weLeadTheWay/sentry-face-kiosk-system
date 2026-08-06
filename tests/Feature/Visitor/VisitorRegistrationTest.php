<?php

namespace Tests\Feature\Visitor;

use App\Models\FaceProfile;
use App\Models\FarmList;
use App\Models\IdentityType;
use App\Models\UserDirectory;
use App\Models\VisitorRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class VisitorRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function makeVisitorRequest(array $overrides = []): VisitorRequest
    {
        $identityType = IdentityType::firstOrCreate(['identity_type_name' => 'Visitor']);
        $farm = FarmList::firstOrCreate(['farm_code' => 'ALPHA'], ['farm_name' => 'ALPHA']);

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
            'farm_id' => $farm->farm_id,
            'host_name' => 'Host',
            'visit_datetime' => now(),
            'registration_token' => 'REG_' . Str::upper(Str::random(8)),
            'approval_status' => 'Approved',
        ], $overrides));
    }

    private function descriptor(float $seed): array
    {
        return array_fill(0, 128, $seed);
    }

    public function test_new_face_registration_marks_registered(): void
    {
        $visitorRequest = $this->makeVisitorRequest();

        $response = $this->postJson('/register/visitor/capture', [
            'token' => $visitorRequest->registration_token,
            'descriptor' => $this->descriptor(0.1),
        ]);

        $response->assertOk()->assertJson(['success' => true, 'status' => 'success']);
        $this->assertEquals('REGISTERED', $visitorRequest->fresh()->face_registration_status);
        $this->assertEquals(1, FaceProfile::count());
    }

    public function test_duplicate_registration_does_not_create_second_face_profile(): void
    {
        $visitorRequest = $this->makeVisitorRequest();

        $this->postJson('/register/visitor/capture', [
            'token' => $visitorRequest->registration_token,
            'descriptor' => $this->descriptor(0.2),
        ])->assertOk();

        $this->assertEquals(1, FaceProfile::count());

        // Same visitor re-submits their own (matching) descriptor.
        $response = $this->postJson('/register/visitor/capture', [
            'token' => $visitorRequest->registration_token,
            'descriptor' => $this->descriptor(0.2),
        ]);

        $response->assertOk()->assertJson(['success' => true, 'status' => 'already_registered']);
        $this->assertEquals(1, FaceProfile::count());
        $this->assertEquals('REGISTERED', $visitorRequest->fresh()->face_registration_status);
    }

    public function test_face_match_different_directory_yes_links_without_duplicate_profile(): void
    {
        $existingRequest = $this->makeVisitorRequest();
        $this->postJson('/register/visitor/capture', [
            'token' => $existingRequest->registration_token,
            'descriptor' => $this->descriptor(0.3),
        ])->assertOk();

        $newRequest = $this->makeVisitorRequest();
        $captureResponse = $this->postJson('/register/visitor/capture', [
            'token' => $newRequest->registration_token,
            'descriptor' => $this->descriptor(0.3),
        ]);
        $captureResponse->assertOk()->assertJsonPath('status', 'face_found_different_directory');
        $matchedDirectoryId = $captureResponse->json('directory_id');

        $confirmResponse = $this->postJson('/register/visitor/confirm', [
            'token' => $newRequest->registration_token,
            'directory_id' => $matchedDirectoryId,
            'confirmed' => true,
        ]);

        $confirmResponse->assertOk()->assertJson(['success' => true, 'status' => 'linked']);
        $newRequest->refresh();
        $this->assertEquals($matchedDirectoryId, $newRequest->directory_id);
        $this->assertEquals('REGISTERED', $newRequest->face_registration_status);
        $this->assertEquals(1, FaceProfile::count());
    }

    public function test_face_match_different_directory_no_sets_failed_match_and_does_not_link(): void
    {
        $existingRequest = $this->makeVisitorRequest();
        $this->postJson('/register/visitor/capture', [
            'token' => $existingRequest->registration_token,
            'descriptor' => $this->descriptor(0.4),
        ])->assertOk();

        $newRequest = $this->makeVisitorRequest();
        $originalDirectoryId = $newRequest->directory_id;

        $captureResponse = $this->postJson('/register/visitor/capture', [
            'token' => $newRequest->registration_token,
            'descriptor' => $this->descriptor(0.4),
        ]);
        $matchedDirectoryId = $captureResponse->json('directory_id');

        $confirmResponse = $this->postJson('/register/visitor/confirm', [
            'token' => $newRequest->registration_token,
            'directory_id' => $matchedDirectoryId,
            'confirmed' => false,
        ]);

        // Regression test: declining must NOT silently behave like an
        // unqualified success that the frontend would redirect to the
        // success page without any distinguishing state.
        $confirmResponse->assertOk()->assertJson(['success' => true, 'status' => 'failed_match']);

        $newRequest->refresh();
        $this->assertEquals($originalDirectoryId, $newRequest->directory_id, 'directory must not change on decline');
        $this->assertEquals('FAILED_MATCH', $newRequest->face_registration_status);
        $this->assertTrue((bool) $newRequest->manual_verification_required);
        $this->assertEquals(1, FaceProfile::count(), 'no new face profile should be created on decline');
    }

    public function test_success_page_redirects_when_registration_pending(): void
    {
        $visitorRequest = $this->makeVisitorRequest();

        $response = $this->get('/register/visitor/success?token=' . $visitorRequest->registration_token);

        $response->assertRedirect(route('visitor.register', ['token' => $visitorRequest->registration_token, 'notice' => 'Please complete your registration first.']));
    }

    public function test_success_page_renders_when_registered(): void
    {
        $visitorRequest = $this->makeVisitorRequest();
        $this->postJson('/register/visitor/capture', [
            'token' => $visitorRequest->registration_token,
            'descriptor' => $this->descriptor(0.5),
        ])->assertOk();

        $response = $this->get('/register/visitor/success?token=' . $visitorRequest->registration_token);
        $response->assertOk();
    }

    public function test_success_page_renders_when_failed_match(): void
    {
        $existingRequest = $this->makeVisitorRequest();
        $this->postJson('/register/visitor/capture', [
            'token' => $existingRequest->registration_token,
            'descriptor' => $this->descriptor(0.6),
        ])->assertOk();

        $newRequest = $this->makeVisitorRequest();
        $captureResponse = $this->postJson('/register/visitor/capture', [
            'token' => $newRequest->registration_token,
            'descriptor' => $this->descriptor(0.6),
        ]);
        $this->postJson('/register/visitor/confirm', [
            'token' => $newRequest->registration_token,
            'directory_id' => $captureResponse->json('directory_id'),
            'confirmed' => false,
        ])->assertOk();

        $response = $this->get('/register/visitor/success?token=' . $newRequest->registration_token);
        $response->assertOk();
    }

    public function test_search_page_route_renders_html_not_json(): void
    {
        $visitorRequest = $this->makeVisitorRequest();

        $response = $this->get('/register/visitor/search?token=' . $visitorRequest->registration_token);

        $response->assertOk();
        $response->assertViewIs('visitor.search');
    }

    public function test_search_query_route_returns_matching_directory(): void
    {
        $visitorRequest = $this->makeVisitorRequest();
        $this->postJson('/register/visitor/capture', [
            'token' => $visitorRequest->registration_token,
            'descriptor' => $this->descriptor(0.65),
        ])->assertOk();

        $response = $this->getJson('/register/visitor/search/query?q=Juan');

        $response->assertOk();
        $this->assertNotEmpty($response->json('results'));
        $this->assertEquals('Juan Dela Cruz', $response->json('results.0.full_name'));
    }

    public function test_option_b_verify_success_links_directory(): void
    {
        $targetRequest = $this->makeVisitorRequest();
        $this->postJson('/register/visitor/capture', [
            'token' => $targetRequest->registration_token,
            'descriptor' => $this->descriptor(0.7),
        ])->assertOk();
        $targetDirectoryId = $targetRequest->directory_id;

        $newRequest = $this->makeVisitorRequest();

        $response = $this->postJson('/register/visitor/verify', [
            'token' => $newRequest->registration_token,
            'directory_id' => $targetDirectoryId,
            'descriptor' => $this->descriptor(0.7),
        ]);

        $response->assertOk()->assertJson(['success' => true, 'status' => 'success']);
        $newRequest->refresh();
        $this->assertEquals($targetDirectoryId, $newRequest->directory_id);
        $this->assertEquals('REGISTERED', $newRequest->face_registration_status);
    }

    public function test_option_b_verify_failure_does_not_mutate_state_before_final_attempt(): void
    {
        $targetRequest = $this->makeVisitorRequest();
        $this->postJson('/register/visitor/capture', [
            'token' => $targetRequest->registration_token,
            'descriptor' => $this->descriptor(0.8),
        ])->assertOk();
        $targetDirectoryId = $targetRequest->directory_id;

        $newRequest = $this->makeVisitorRequest();
        $originalDirectoryId = $newRequest->directory_id;

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $response = $this->postJson('/register/visitor/verify', [
                'token' => $newRequest->registration_token,
                'directory_id' => $targetDirectoryId,
                'descriptor' => $this->descriptor(0.9), // does not match target's 0.8 descriptor
                'attempt' => $attempt,
            ]);

            $response->assertStatus(422)->assertJson(['success' => false, 'status' => 'face_not_found']);
        }

        $newRequest->refresh();
        $this->assertEquals($originalDirectoryId, $newRequest->directory_id);
        $this->assertEquals('PENDING', $newRequest->face_registration_status);
    }

    public function test_option_b_third_failed_attempt_marks_manual_verification_required(): void
    {
        $targetRequest = $this->makeVisitorRequest();
        $this->postJson('/register/visitor/capture', [
            'token' => $targetRequest->registration_token,
            'descriptor' => $this->descriptor(0.81),
        ])->assertOk();
        $targetDirectoryId = $targetRequest->directory_id;

        $newRequest = $this->makeVisitorRequest();
        $originalDirectoryId = $newRequest->directory_id;

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $this->postJson('/register/visitor/verify', [
                'token' => $newRequest->registration_token,
                'directory_id' => $targetDirectoryId,
                'descriptor' => $this->descriptor(0.91),
                'attempt' => $attempt,
            ])->assertStatus(422);
        }

        $response = $this->postJson('/register/visitor/verify', [
            'token' => $newRequest->registration_token,
            'directory_id' => $targetDirectoryId,
            'descriptor' => $this->descriptor(0.91),
            'attempt' => 3,
        ]);

        // The final attempt is reported as success:true at the HTTP layer
        // (the request was processed correctly) with a distinguishing
        // status - the visitor is redirected to the success/QR page rather
        // than blocked outright, per the biometric-conflict business rule.
        $response->assertOk()->assertJson(['success' => true, 'status' => 'verification_failed']);

        $newRequest->refresh();
        $this->assertEquals($originalDirectoryId, $newRequest->directory_id, 'directory must not be linked on failure');
        $this->assertEquals('FAILED_MATCH', $newRequest->face_registration_status);
        $this->assertTrue((bool) $newRequest->manual_verification_required);

        // QR must still be reachable despite the failed verification.
        $qrResponse = $this->get('/register/visitor/qr?token=' . $newRequest->registration_token);
        $qrResponse->assertOk();
    }
}
