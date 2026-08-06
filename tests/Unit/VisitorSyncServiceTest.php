<?php

namespace Tests\Unit;

use App\Models\FarmList;
use App\Models\IdentityType;
use App\Models\UserDirectory;
use App\Models\VisitorRequest;
use App\Services\FarmResolver;
use App\Services\VisitorSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VisitorSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private VisitorSyncService $service;
    private FarmList $farm;

    protected function setUp(): void
    {
        parent::setUp();

        IdentityType::create(['identity_type_name' => 'Visitor']);
        $this->farm = FarmList::create(['farm_code' => 'ALPHA', 'farm_name' => 'ALPHA']);

        $this->service = new VisitorSyncService(new FarmResolver());
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Juan',
            'middle_name' => null,
            'last_name' => 'Dela Cruz',
            'email' => 'juan@example.com',
            'farm' => 'ALPHA',
            'host_name' => 'Maria Santos',
            'visit_datetime' => now()->format('Y-m-d H:i:s'),
            'visitor_id' => 'VIS-' . uniqid(),
            'qr_url' => 'https://example.com/qr.png',
        ], $overrides);
    }

    public function test_case1_same_name_and_email_reuses_existing_directory(): void
    {
        $first = $this->service->syncApprovedRequest($this->payload(['visitor_id' => 'VIS-1']));
        $this->assertTrue($first['success']);

        $second = $this->service->syncApprovedRequest($this->payload(['visitor_id' => 'VIS-2']));
        $this->assertTrue($second['success']);

        $this->assertEquals(
            $first['visitor_request']->directory_id,
            $second['visitor_request']->directory_id
        );
        $this->assertEquals(1, UserDirectory::count());
    }

    public function test_case2_same_name_different_email_creates_new_directory(): void
    {
        $first = $this->service->syncApprovedRequest($this->payload([
            'visitor_id' => 'VIS-3', 'email' => 'juan@example.com',
        ]));
        $second = $this->service->syncApprovedRequest($this->payload([
            'visitor_id' => 'VIS-4', 'email' => 'juan.delacruz@example.com',
        ]));

        $this->assertNotEquals(
            $first['visitor_request']->directory_id,
            $second['visitor_request']->directory_id
        );
        $this->assertEquals(2, UserDirectory::count());
    }

    public function test_case3_different_name_same_email_creates_new_directory(): void
    {
        $first = $this->service->syncApprovedRequest($this->payload([
            'visitor_id' => 'VIS-5', 'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'email' => 'shared@example.com',
        ]));
        $second = $this->service->syncApprovedRequest($this->payload([
            'visitor_id' => 'VIS-6', 'first_name' => 'Maria', 'last_name' => 'Santos', 'email' => 'shared@example.com',
        ]));

        $this->assertNotEquals(
            $first['visitor_request']->directory_id,
            $second['visitor_request']->directory_id
        );
        $this->assertEquals(2, UserDirectory::count());
    }

    public function test_case4_different_name_and_email_creates_new_directory(): void
    {
        $first = $this->service->syncApprovedRequest($this->payload(['visitor_id' => 'VIS-7']));
        $second = $this->service->syncApprovedRequest($this->payload([
            'visitor_id' => 'VIS-8', 'first_name' => 'Pedro', 'last_name' => 'Reyes', 'email' => 'pedro@example.com',
        ]));

        $this->assertNotEquals(
            $first['visitor_request']->directory_id,
            $second['visitor_request']->directory_id
        );
        $this->assertEquals(2, UserDirectory::count());
    }

    public function test_duplicate_visitor_id_is_idempotent_and_does_not_duplicate_request(): void
    {
        $first = $this->service->syncApprovedRequest($this->payload(['visitor_id' => 'VIS-DUPE']));
        $second = $this->service->syncApprovedRequest($this->payload(['visitor_id' => 'VIS-DUPE']));

        $this->assertEquals($first['registration_token'], $second['registration_token']);
        $this->assertEquals(1, VisitorRequest::where('visitor_id', 'VIS-DUPE')->count());
    }

    public function test_unresolvable_farm_fails_without_creating_any_rows(): void
    {
        $result = $this->service->syncApprovedRequest($this->payload([
            'visitor_id' => 'VIS-NOFARM', 'farm' => 'DOES NOT EXIST',
        ]));

        $this->assertFalse($result['success']);
        $this->assertEquals(0, UserDirectory::count());
        $this->assertEquals(0, VisitorRequest::count());
    }

    public function test_middle_name_is_persisted_and_used_in_full_name(): void
    {
        $result = $this->service->syncApprovedRequest($this->payload([
            'visitor_id' => 'VIS-MID', 'middle_name' => 'Reyes',
        ]));

        $directory = UserDirectory::find($result['visitor_request']->directory_id);
        $this->assertEquals('Reyes', $directory->middle_name);
        $this->assertEquals('Juan Reyes Dela Cruz', $directory->full_name);
    }
}
