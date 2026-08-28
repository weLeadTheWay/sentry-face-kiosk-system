<?php

namespace Tests\Feature\Admin;

use App\Models\DowntimeMatrix;
use App\Models\DowntimeMatrixImport;
use App\Models\DowntimeStationary;
use App\Models\FacilityList;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\FacilityCategorySeeder;
use Database\Seeders\FacilityListSeeder;
use Database\Seeders\FacilityTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesFacilities;
use Tests\TestCase;

class DowntimeMatrixImportAdminTest extends TestCase
{
    use RefreshDatabase;
    use CreatesFacilities;

    private const FIXTURE_PDF = __DIR__ . '/../../Fixtures/sample_bva_downtime_matrix.pdf';

    /**
     * Every real admin nav interaction sends X-Requested-With (see
     * public/js/admin.js) - without it, controllers fall back to a
     * full-page render that (per a pre-existing, documented quirk shared
     * by every Admin controller) doesn't pass the index page's variables.
     * Sending the header here matches real usage and avoids that quirk,
     * the same way FacilityAdminTest does.
     */
    private const AJAX_HEADER = ['X-Requested-With' => 'XMLHttpRequest'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->actingAdmin();
    }

    private function actingAdmin(): User
    {
        $role = Role::create(['role_name' => 'Admin']);
        $permission = Permission::create([
            'permission_key' => 'downtime_matrix_import.manage',
            'permission_name' => 'Manage Downtime Matrix Imports',
        ]);
        $role->permissions()->attach($permission->permission_id);

        $user = User::create([
            'role_id' => $role->role_id,
            'user_name' => 'admin',
            'user_email' => 'admin@example.com',
            'hash_password' => bcrypt('password'),
            'is_active' => true,
        ]);

        $this->actingAs($user);

        return $user;
    }

    private function fixturePdf(string $name = 'sample.pdf'): UploadedFile
    {
        return new UploadedFile(self::FIXTURE_PDF, $name, 'application/pdf', null, true);
    }

    private function seedRealFacilityData(): void
    {
        $this->seed(FacilityTypeSeeder::class);
        $this->seed(FacilityCategorySeeder::class);
        $this->seed(FacilityListSeeder::class);
    }

    private function ajaxPost(string $url, array $data = [])
    {
        return $this->post($url, $data, self::AJAX_HEADER);
    }

    private function ajaxGet(string $url)
    {
        return $this->get($url, self::AJAX_HEADER);
    }

    /**
     * The upload form is deliberately a plain, non-ajax browser POST (see
     * _create.blade.php) - matching that here, rather than sending the ajax
     * header, exercises the actual production path, including how a
     * validation failure redirects back with flashed session errors.
     */
    private function upload(string $matrixType = 'BFI_BVA')
    {
        return $this->post(route('downtime-matrix-import.store'), [
            'matrix_type' => $matrixType,
            'pdf_file' => $this->fixturePdf(),
        ]);
    }

    public function test_index_requires_permission(): void
    {
        $role = Role::create(['role_name' => 'NoPermissions']);
        $this->actingAs(User::create([
            'role_id' => $role->role_id,
            'user_name' => 'nobody',
            'user_email' => 'nobody@example.com',
            'hash_password' => bcrypt('password'),
            'is_active' => true,
        ]));

        $this->ajaxGet(route('downtime-matrix-import.index'))->assertForbidden();
    }

    public function test_biosecurity_rules_landing_is_reachable_with_only_the_new_permission(): void
    {
        // Confirms the required OR-permission change to the landing route:
        // this user has downtime_matrix_import.manage but not
        // biosecurity.manage, and must still reach the landing page.
        $this->ajaxGet(route('biosecurity-rules.index'))->assertOk();
    }

    public function test_index_renders_as_ajax_partial_and_as_a_full_page(): void
    {
        $this->ajaxGet(route('downtime-matrix-import.index'))->assertOk();
        $this->get(route('downtime-matrix-import.index'))->assertOk();
    }

    public function test_create_renders_as_ajax_partial_and_as_a_full_page(): void
    {
        $this->ajaxGet(route('downtime-matrix-import.create'))->assertOk();
        $this->get(route('downtime-matrix-import.create'))->assertOk();
    }

    public function test_show_renders_as_ajax_partial_and_as_a_full_page(): void
    {
        $this->upload();
        $import = DowntimeMatrixImport::sole();

        $this->ajaxGet(route('downtime-matrix-import.show', $import))->assertOk();
        $this->get(route('downtime-matrix-import.show', $import))->assertOk();
    }

    public function test_upload_and_store_renders_preview_directly(): void
    {
        $response = $this->upload();

        $response->assertOk();
        $this->assertSame(1, DowntimeMatrixImport::count());
        $import = DowntimeMatrixImport::sole();
        $this->assertSame('PENDING_VERIFICATION', $import->status);
        $this->assertNotEmpty($import->stored_file_path);
        Storage::disk('public')->assertExists($import->stored_file_path);
    }

    public function test_non_pdf_file_is_rejected(): void
    {
        $response = $this->post(route('downtime-matrix-import.store'), [
            'matrix_type' => 'BFI_BVA',
            'pdf_file' => UploadedFile::fake()->create('not-a-pdf.txt', 10, 'text/plain'),
        ]);

        $response->assertSessionHasErrors('pdf_file');
        $this->assertSame(0, DowntimeMatrixImport::count());
    }

    public function test_hogs_matrix_type_is_rejected_server_side(): void
    {
        $response = $this->upload('HOGS');

        $response->assertSessionHasErrors('matrix_type');
        $this->assertSame(0, DowntimeMatrixImport::count());
    }

    public function test_upload_parses_real_sample_pdf_and_produces_expected_row_counts(): void
    {
        $this->seedRealFacilityData();

        $this->upload()->assertOk();

        $import = DowntimeMatrixImport::sole();

        $this->assertNull($import->parse_error_message);
        // 88 real parsed cells + 8 synthesized "farm -> LEP, DC" rows (every
        // farm gets one even though that cell is blank in the source PDF -
        // blank there means no downtime is required, not missing data).
        $this->assertSame(96, $import->total_rows_parsed);
        // Only "Outside" (8 farm destinations) is classified STATIONARY.
        // Farm-to-Farm requires the origin to actually be farm-like (a real
        // farm or "LEP, DC"): 56 real farm pairs + 8 "LEP, DC" (as origin)
        // pairs + 8 synthesized farm -> "LEP, DC" (as destination) rows = 72.
        // Organikultura Area and Fabrication (8 each) are neither a farm
        // nor the Outside sentinel, so they land in Others.
        $this->assertSame(72, $import->rows()->where('rule_type', 'FARM_TO_FARM')->count());
        $this->assertSame(8, $import->rows()->where('rule_type', 'STATIONARY')->count());
        $this->assertSame(16, $import->rows()->where('rule_type', 'OTHERS')->count());
        $this->assertSame(8, $import->rows()->where('origin_raw_label', 'Outside (w/o any Farm Contact)')->where('rule_type', 'STATIONARY')->count());
        // No facility_aliases are seeded (matches real production state per
        // CLAUDE.md), and every PDF label is a "X Farm (Qualifier)" variant
        // while facility_list stores bare names ("Saturn", not "Saturn
        // Farm") - so every resolvable row lands via NORMALIZED_NAME
        // (WARNING), never an EXACT_NAME (VALID) match. That's correct,
        // safe behavior, not a bug: an admin reviewing the preview is
        // exactly the intended safety net for a normalized-not-exact match.
        $this->assertGreaterThan(0, $import->warning_rows_count);

        $saturnToVenus = $import->rows()
            ->where('origin_raw_label', 'Saturn Farm (Green)')
            ->where('destination_raw_label', 'Venus Farm')
            ->sole();
        $this->assertSame('WARNING', $saturnToVenus->resolution_status);
        $this->assertSame('NORMALIZED_NAME', $saturnToVenus->origin_resolution_method);
        $this->assertEquals(12.0, (float) $saturnToVenus->minimum_downtime);
        $this->assertEquals(36.0, (float) $saturnToVenus->maximum_downtime);
    }

    public function test_lep_dc_resolves_to_all_active_dc_warehouse_facilities_not_a_single_facility_id(): void
    {
        $this->seedRealFacilityData();

        $this->upload()->assertOk();

        $import = DowntimeMatrixImport::sole();

        $lepDcRows = $import->rows()->where('origin_raw_label', 'LEP, DC')->get();
        $this->assertGreaterThan(0, $lepDcRows->count());

        foreach ($lepDcRows as $row) {
            $this->assertNull($row->origin_facility_id, 'A facility-group match must never be assigned a single facility_id.');
            $this->assertSame('DC_WAREHOUSE', $row->origin_facility_group_category);
            $this->assertSame('FARM_TO_FARM', $row->rule_type, 'LEP, DC belongs to Farm-to-Farm, not Stationary, per the confirmed business rule.');
        }

        $dcFacilities = FacilityList::whereHas('facilityCategory', fn ($q) => $q->where('facility_category_name', 'DC_WAREHOUSE'))
            ->where('is_active', true)
            ->pluck('facility_name');
        $this->assertEqualsCanonicalizing(['DC Plaridel', 'DC Sta. Rosa'], $dcFacilities->all());
    }

    public function test_every_farm_gets_a_destination_row_to_dc_warehouses_even_though_the_cell_is_blank(): void
    {
        $this->seedRealFacilityData();

        $this->upload()->assertOk();

        $import = DowntimeMatrixImport::sole();

        $farmOrigins = [
            'Saturn Farm (Green)', 'Venus Farm (Red-Act)', 'Cinnamon Farm (Red-Act)', 'Mars Farm (Red-Act)',
            'Madera Farm (Red-Act)', 'Rosemary Farm (Red-Act)', 'San Pascual Farm (Green)', 'Victory Farm (Green)',
        ];

        foreach ($farmOrigins as $originLabel) {
            $row = $import->rows()
                ->where('origin_raw_label', $originLabel)
                ->where('destination_raw_label', 'LEP, DC')
                ->sole();

            $this->assertSame('FARM_TO_FARM', $row->rule_type);
            $this->assertSame('DC_WAREHOUSE', $row->destination_facility_group_category);
            $this->assertNull($row->destination_facility_id);
            $this->assertNull($row->minimum_downtime, 'A blank cell here means no downtime is required, not a derivable value.');
            $this->assertNull($row->maximum_downtime);
            $this->assertStringContainsString('No downtime required for this cell.', $row->validation_message);
        }
    }

    public function test_non_sentinel_non_farm_origins_land_in_others_and_are_never_silently_dropped(): void
    {
        $this->seedRealFacilityData();

        $this->upload()->assertOk();

        $import = DowntimeMatrixImport::sole();

        foreach (['Organikultura Area', 'Fabrication'] as $label) {
            $rows = $import->rows()->where('origin_raw_label', $label)->get();
            $this->assertGreaterThan(0, $rows->count(), "expected rows for origin '{$label}'");

            foreach ($rows as $row) {
                $this->assertSame('OTHERS', $row->rule_type);
                $this->assertNull($row->origin_facility_id);
                // These rows are unresolved (no facility named
                // "Organikultura Area"/"Fabrication"), which is UNMATCHED.
                // Downtime Area is blank in the source PDF (only Dormitory
                // is populated), but a blank value is no longer INVALID on
                // its own - Dormitory stands in as the minimum threshold
                // (an INFO-tier finding, never outranks UNMATCHED), so the
                // final status is UNMATCHED with both the facility problem
                // and the derived-minimum note present in the message.
                $this->assertSame('UNMATCHED', $row->resolution_status);
                $this->assertNotNull($row->minimum_downtime, 'Dormitory alone should still derive a minimum threshold.');
                $this->assertNull($row->maximum_downtime);
                $this->assertStringContainsString('No facility', $row->validation_message);
                $this->assertStringContainsString('Minimum of', $row->validation_message);
            }
        }
    }

    public function test_verify_only_changes_staging_status(): void
    {
        $this->upload();
        $import = DowntimeMatrixImport::sole();
        $rowCountBefore = $import->rows()->count();

        $this->ajaxPost(route('downtime-matrix-import.verify', $import))->assertOk();

        $import->refresh();
        $this->assertSame('VERIFIED', $import->status);
        $this->assertNotNull($import->verified_by);
        $this->assertNotNull($import->verified_at);
        $this->assertSame($rowCountBefore, $import->rows()->count(), 'Verify must never add/remove/alter staged rows.');
    }

    public function test_cancel_only_changes_staging_status(): void
    {
        $this->upload();
        $import = DowntimeMatrixImport::sole();
        $rowCountBefore = $import->rows()->count();

        $this->ajaxPost(route('downtime-matrix-import.cancel', $import))->assertOk();

        $import->refresh();
        $this->assertSame('CANCELLED', $import->status);
        $this->assertNotNull($import->cancelled_by);
        $this->assertSame($rowCountBefore, $import->rows()->count());
    }

    public function test_import_never_writes_to_production_downtime_tables(): void
    {
        $this->seedRealFacilityData();
        $preExistingMatrixCount = DowntimeMatrix::count();
        $preExistingStationaryCount = DowntimeStationary::count();

        $this->upload()->assertOk();

        $import = DowntimeMatrixImport::sole();
        $this->ajaxPost(route('downtime-matrix-import.verify', $import))->assertOk();

        $this->assertSame($preExistingMatrixCount, DowntimeMatrix::count(), 'downtime_matrix must never be written to by the import pipeline, even after Verify.');
        $this->assertSame($preExistingStationaryCount, DowntimeStationary::count(), 'downtime_stationary must never be written to by the import pipeline, even after Verify.');
    }
}
