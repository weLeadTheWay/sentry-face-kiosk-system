<?php

namespace Tests\Unit;

use App\Models\IdentityType;
use App\Models\UserDirectory;
use App\Models\VisitorProfile;
use App\Models\VisitorType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step 1 of the ERD restructure: visitor_profile is a new, additive table.
 * These tests cover only the new model code (relations) - the existing
 * visitor_type_id/company/plate_no columns on UserDirectory itself are
 * untouched and remain covered by the existing Gatesale/Truck test suites.
 */
class VisitorProfileTest extends TestCase
{
    use RefreshDatabase;

    private IdentityType $identityType;
    private VisitorType $gatesaleType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->identityType = IdentityType::create(['identity_type_name' => 'Visitor']);
        $this->gatesaleType = VisitorType::create(['visitor_type_name' => 'Gatesale']);
    }

    private function makeDirectory(array $overrides = []): UserDirectory
    {
        $email = 'directory+' . uniqid() . '@example.com';

        return UserDirectory::create(array_merge([
            'identity_type_id' => $this->identityType->identity_type_id,
            'person_reference' => $email,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'full_name' => 'Maria Santos',
            'email' => $email,
        ], $overrides));
    }

    public function test_user_directory_visitor_profile_returns_the_correct_profile(): void
    {
        $directory = $this->makeDirectory();
        $profile = VisitorProfile::create([
            'directory_id' => $directory->directory_id,
            'visitor_type_id' => $this->gatesaleType->visitor_type_id,
            'company' => 'Acme Trading',
            'plate_no' => null,
        ]);

        $this->assertTrue($directory->visitorProfile->is($profile));
        $this->assertEquals('Acme Trading', $directory->visitorProfile->company);
    }

    public function test_user_directory_visitor_profile_is_null_when_none_exists(): void
    {
        // Simulates a legacy/Employee directory (no visitor_type_id, no
        // visitor_profile row) - the relation must not error.
        $directory = $this->makeDirectory();

        $this->assertNull($directory->visitorProfile);
    }

    public function test_visitor_profile_directory_relation_resolves(): void
    {
        $directory = $this->makeDirectory();
        $profile = VisitorProfile::create([
            'directory_id' => $directory->directory_id,
            'visitor_type_id' => $this->gatesaleType->visitor_type_id,
        ]);

        $this->assertTrue($profile->directory->is($directory));
        $this->assertEquals('Maria Santos', $profile->directory->full_name);
    }

    public function test_visitor_profile_visitor_type_relation_resolves(): void
    {
        $directory = $this->makeDirectory();
        $profile = VisitorProfile::create([
            'directory_id' => $directory->directory_id,
            'visitor_type_id' => $this->gatesaleType->visitor_type_id,
        ]);

        $this->assertEquals('Gatesale', $profile->visitorType->visitor_type_name);
    }

    public function test_deleting_the_directory_cascades_to_delete_its_visitor_profile(): void
    {
        $directory = $this->makeDirectory();
        VisitorProfile::create([
            'directory_id' => $directory->directory_id,
            'visitor_type_id' => $this->gatesaleType->visitor_type_id,
        ]);

        $this->assertEquals(1, VisitorProfile::count());

        $directory->delete();

        $this->assertEquals(0, VisitorProfile::count());
    }

    public function test_directory_id_is_unique_on_visitor_profile(): void
    {
        $directory = $this->makeDirectory();
        VisitorProfile::create([
            'directory_id' => $directory->directory_id,
            'visitor_type_id' => $this->gatesaleType->visitor_type_id,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        VisitorProfile::create([
            'directory_id' => $directory->directory_id,
            'visitor_type_id' => $this->gatesaleType->visitor_type_id,
        ]);
    }
}
