<?php

namespace Tests\Feature\Admin;

use App\Models\DowntimeMatrix;
use App\Models\DowntimeMatrixImport;
use App\Models\DowntimeMatrixImportRow;
use App\Models\DowntimeStationary;
use App\Models\FacilityList;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\DowntimeMatrixImport\FacilityImportResolver;
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

    private function ajaxPut(string $url, array $data = [])
    {
        return $this->put($url, $data, self::AJAX_HEADER);
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

    public function test_index_renders_an_empty_data_table_shell_without_querying_records(): void
    {
        $this->upload();
        $import = DowntimeMatrixImport::sole();

        $response = $this->ajaxGet(route('downtime-matrix-import.index'));

        $response->assertOk();
        $response->assertSee('id="dmi-table"', false);
        $response->assertSee('id="dmi-filter-btn"', false);
        $response->assertDontSee($import->original_filename);
    }

    public function test_data_endpoint_returns_the_uploaded_import(): void
    {
        $this->upload();
        $import = DowntimeMatrixImport::sole();

        $response = $this->getJson(route('downtime-matrix-import.data'));

        $response->assertOk();
        $response->assertJsonPath('data.0.import_id', $import->import_id);
        $response->assertJsonPath('data.0.original_filename', $import->original_filename);
        $response->assertJsonPath('data.0.status', 'PENDING_VERIFICATION');
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

    // --- Production mapping (confirmation step + real downtime_matrix/downtime_stationary writes) ---

    /**
     * Independently re-derives how many downtime_matrix/downtime_stationary
     * rows the service *should* create for $import's current staging rows,
     * by walking the same VALID/WARNING-only, group-expanding rules
     * DowntimeMatrixImportService::produce() applies - but as its own
     * separate calculation (not by calling the service's private methods),
     * so it's a genuine check rather than restating the implementation.
     *
     * @return array{matrix: int, stationary: int}
     */
    private function expectedProductionCounts(DowntimeMatrixImport $import): array
    {
        $dcFacilityIds = FacilityList::whereHas('facilityCategory', fn ($q) => $q->where('facility_category_name', 'DC_WAREHOUSE'))
            ->where('is_active', true)
            ->pluck('facility_id')
            ->all();

        $expandSide = function (DowntimeMatrixImportRow $row, string $side) use ($dcFacilityIds) {
            $facilityId = $row->{$side . '_facility_id'};
            if ($facilityId !== null) {
                return [$facilityId];
            }

            if ($row->{$side . '_facility_group_category'} === 'DC_WAREHOUSE') {
                return $dcFacilityIds;
            }

            return [];
        };

        $matrixCount = 0;
        $import->rows()->whereIn('resolution_status', ['VALID', 'WARNING'])->where('rule_type', 'FARM_TO_FARM')->get()
            ->each(function (DowntimeMatrixImportRow $row) use (&$matrixCount, $expandSide) {
                $origins = $expandSide($row, 'origin');
                $destinations = $expandSide($row, 'destination');
                if (empty($origins) || empty($destinations)) {
                    return;
                }
                foreach ($origins as $originId) {
                    foreach ($destinations as $destinationId) {
                        if ($originId !== $destinationId) {
                            $matrixCount++;
                        }
                    }
                }
            });

        $stationaryCount = $import->rows()
            ->whereIn('resolution_status', ['VALID', 'WARNING'])
            ->where('rule_type', 'STATIONARY')
            ->whereNotNull('destination_facility_id')
            ->count();

        return ['matrix' => $matrixCount, 'stationary' => $stationaryCount];
    }

    public function test_index_page_renders_the_conditional_production_action(): void
    {
        $this->upload();

        $response = $this->ajaxGet(route('downtime-matrix-import.index'));

        $response->assertOk();
        // The Production button is rendered client-side by renderActions()
        // in the DataTables columns config, gated on row.status - there is
        // no server-rendered per-row HTML to assert on directly (same as
        // every other action button in this Data Table), so this locks in
        // that the gating condition and the button text are actually
        // present in the served page. It must never be offered for
        // PENDING_VERIFICATION, CANCELLED, or PRODUCED - all three are
        // excluded by construction since the check is an exact equality
        // against 'VERIFIED', not a blocklist.
        $response->assertSee("row.status === 'VERIFIED'", false);
        $response->assertSee('Production', false);
    }

    public function test_produce_confirm_falls_back_to_plain_preview_unless_verified(): void
    {
        $this->upload();
        $import = DowntimeMatrixImport::sole();
        $this->assertSame('PENDING_VERIFICATION', $import->status);

        $response = $this->ajaxGet(route('downtime-matrix-import.produce.confirm', $import));

        $response->assertOk();
        $response->assertDontSee('Confirm Save to Production');
        $response->assertDontSee('Save to Production');
    }

    public function test_produce_confirm_shows_the_confirmation_step_when_verified(): void
    {
        $this->seedRealFacilityData();
        $this->upload()->assertOk();
        $import = DowntimeMatrixImport::sole();
        $this->ajaxPost(route('downtime-matrix-import.verify', $import))->assertOk();

        $response = $this->ajaxGet(route('downtime-matrix-import.produce.confirm', $import));

        $response->assertOk();
        $response->assertSee('Confirm Save to Production');
        $response->assertSee('Save to Production');
        $response->assertSee($import->original_filename);
        $response->assertSee('Total Rows Parsed');
        $response->assertSee('Rows to be Mapped');
        $response->assertSee('Rows to be Skipped');
        $response->assertSee((string) ($import->valid_rows_count + $import->warning_rows_count));
        $response->assertSee((string) ($import->unmatched_rows_count + $import->ambiguous_rows_count + $import->invalid_rows_count));
        // The confirmation step is a review of the same Preview, not a
        // separate summary - its three Data Table tabs must still be there.
        $response->assertSee('id="dmi-ftf-table"', false);
    }

    public function test_show_page_offers_a_production_shortcut_once_verified(): void
    {
        $this->upload();
        $import = DowntimeMatrixImport::sole();

        // Not yet verified - no shortcut to a step that isn't reachable yet.
        $this->ajaxGet(route('downtime-matrix-import.show', $import))
            ->assertOk()
            ->assertDontSee('href="' . route('downtime-matrix-import.produce.confirm', $import) . '"', false);

        $this->ajaxPost(route('downtime-matrix-import.verify', $import))->assertOk();

        // The response to Verify itself, and a plain revisit of the same
        // Preview afterward, must both offer the shortcut - an admin
        // shouldn't have to navigate back to the import list just to find
        // the Production action for an import they're already looking at.
        $verifyResponse = $this->ajaxPost(route('downtime-matrix-import.verify', $import));
        $verifyResponse->assertOk();
        $verifyResponse->assertSee('href="' . route('downtime-matrix-import.produce.confirm', $import) . '"', false);

        $this->ajaxGet(route('downtime-matrix-import.show', $import))
            ->assertOk()
            ->assertSee('href="' . route('downtime-matrix-import.produce.confirm', $import) . '"', false);

        // The shortcut only ever links to the confirmation step, never
        // straight to produce() - Save to Production must still always
        // require that explicit confirmation.
        $this->ajaxGet(route('downtime-matrix-import.show', $import))
            ->assertDontSee('action="' . route('downtime-matrix-import.produce', $import) . '"', false);
    }

    public function test_production_shortcut_is_absent_once_cancelled_or_produced(): void
    {
        $this->seedRealFacilityData();
        $this->upload()->assertOk();
        $cancelled = DowntimeMatrixImport::sole();
        $this->ajaxPost(route('downtime-matrix-import.cancel', $cancelled))->assertOk();

        $this->ajaxGet(route('downtime-matrix-import.show', $cancelled))
            ->assertOk()
            ->assertDontSee('href="' . route('downtime-matrix-import.produce.confirm', $cancelled) . '"', false);

        $this->upload()->assertOk();
        $produced = DowntimeMatrixImport::where('import_id', '!=', $cancelled->import_id)->sole();
        $this->ajaxPost(route('downtime-matrix-import.verify', $produced))->assertOk();
        $this->ajaxPost(route('downtime-matrix-import.produce', $produced))->assertOk();

        $this->ajaxGet(route('downtime-matrix-import.show', $produced))
            ->assertOk()
            ->assertDontSee('href="' . route('downtime-matrix-import.produce.confirm', $produced) . '"', false);
    }

    public function test_viewing_the_confirmation_step_makes_no_database_changes(): void
    {
        $this->seedRealFacilityData();
        $this->upload()->assertOk();
        $import = DowntimeMatrixImport::sole();
        $this->ajaxPost(route('downtime-matrix-import.verify', $import))->assertOk();

        $this->ajaxGet(route('downtime-matrix-import.produce.confirm', $import))->assertOk();

        $import->refresh();
        $this->assertSame('VERIFIED', $import->status, 'Merely viewing the confirmation step - i.e. not yet confirming - must not change status.');
        $this->assertSame(0, DowntimeMatrix::count());
        $this->assertSame(0, DowntimeStationary::count());
    }

    public function test_produce_is_a_no_op_unless_verified(): void
    {
        $this->seedRealFacilityData();
        $this->upload();
        $import = DowntimeMatrixImport::sole();
        $this->assertSame('PENDING_VERIFICATION', $import->status);

        $this->ajaxPost(route('downtime-matrix-import.produce', $import))->assertOk();

        $import->refresh();
        $this->assertSame('PENDING_VERIFICATION', $import->status, 'Producing a non-VERIFIED import must be a no-op.');
        $this->assertNull($import->produced_by);
        $this->assertNull($import->produced_at);
        $this->assertSame(0, DowntimeMatrix::count());
        $this->assertSame(0, DowntimeStationary::count());
    }

    public function test_produce_requires_permission(): void
    {
        $this->upload();
        $import = DowntimeMatrixImport::sole();
        $this->ajaxPost(route('downtime-matrix-import.verify', $import))->assertOk();

        $role = Role::create(['role_name' => 'NoPermissions']);
        $this->actingAs(User::create([
            'role_id' => $role->role_id,
            'user_name' => 'nobody',
            'user_email' => 'nobody@example.com',
            'hash_password' => bcrypt('password'),
            'is_active' => true,
        ]));

        $this->get(route('downtime-matrix-import.produce.confirm', $import))->assertForbidden();
        $this->post(route('downtime-matrix-import.produce', $import))->assertForbidden();
    }

    public function test_produce_maps_eligible_rows_and_marks_the_import_produced(): void
    {
        $this->seedRealFacilityData();
        $this->upload()->assertOk();
        $import = DowntimeMatrixImport::sole();
        $this->ajaxPost(route('downtime-matrix-import.verify', $import))->assertOk();
        $producer = auth()->user();
        $expected = $this->expectedProductionCounts($import);
        // The real fixture resolves every Farm-to-Farm/Stationary cell via
        // NORMALIZED_NAME (WARNING) rather than an exact match (see
        // test_upload_parses_real_sample_pdf_...), so this import has real
        // WARNING rows to map - not a contrived scenario.
        $this->assertGreaterThan(0, $expected['matrix'] + $expected['stationary']);

        $response = $this->ajaxPost(route('downtime-matrix-import.produce', $import));

        $response->assertOk();
        $response->assertSee('Production mapping completed.');
        $import->refresh();
        $this->assertSame('PRODUCED', $import->status);
        $this->assertSame($producer->user_id, $import->produced_by);
        $this->assertNotNull($import->produced_at);
        $this->assertSame($expected['matrix'], DowntimeMatrix::where('is_active', true)->count());
        $this->assertSame($expected['stationary'], DowntimeStationary::where('is_active', true)->count());
        // "records created" can exceed "rows processed" once a LEP, DC
        // group row expands - assert that's actually true for this real
        // fixture, not just possible in theory.
        $stagingRowsProcessed = $import->valid_rows_count + $import->warning_rows_count;
        $this->assertGreaterThan($stagingRowsProcessed, $expected['matrix'] + $expected['stationary'], 'LEP, DC group expansion should make production records outnumber staging rows for this fixture.');
    }

    public function test_produce_does_not_modify_staging_rows_or_the_original_pdf(): void
    {
        $this->seedRealFacilityData();
        $this->upload()->assertOk();
        $import = DowntimeMatrixImport::sole();
        $this->ajaxPost(route('downtime-matrix-import.verify', $import))->assertOk();
        $storedPath = $import->stored_file_path;
        $rowsBefore = $import->rows()->orderBy('import_row_id')->get(['import_row_id', 'resolution_status', 'origin_facility_id', 'destination_facility_id', 'minimum_downtime', 'maximum_downtime'])->toArray();

        $this->ajaxPost(route('downtime-matrix-import.produce', $import))->assertOk();

        $import->refresh();
        $this->assertSame($storedPath, $import->stored_file_path, 'The original uploaded PDF path must never change.');
        Storage::disk('public')->assertExists($storedPath);
        $rowsAfter = $import->rows()->orderBy('import_row_id')->get(['import_row_id', 'resolution_status', 'origin_facility_id', 'destination_facility_id', 'minimum_downtime', 'maximum_downtime'])->toArray();
        $this->assertEquals($rowsBefore, $rowsAfter, 'Producing must never modify downtime_matrix_import_rows.');
    }

    public function test_produce_skips_unmatched_rows(): void
    {
        $this->seedRealFacilityData();
        $this->upload()->assertOk();
        $import = DowntimeMatrixImport::sole();
        // Organikultura Area / Fabrication (16 rows total) are UNMATCHED in
        // the real fixture - confirmed by
        // test_non_sentinel_non_farm_origins_land_in_others_and_are_never_silently_dropped.
        $this->assertSame(16, $import->unmatched_rows_count);
        $this->ajaxPost(route('downtime-matrix-import.verify', $import))->assertOk();

        $response = $this->ajaxPost(route('downtime-matrix-import.produce', $import));

        $response->assertOk();
        $import->refresh();
        $this->assertSame(16, $import->unmatched_rows_count, 'Skipped rows are never touched - the denormalized count must be unchanged.');
        // 16 UNMATCHED rows can only have contributed 0 production records -
        // confirmed generally via expectedProductionCounts() excluding them
        // (it only ever queries VALID/WARNING rows).
        $expected = $this->expectedProductionCounts($import);
        $this->assertSame($expected['matrix'], DowntimeMatrix::count());
        $this->assertSame($expected['stationary'], DowntimeStationary::count());
    }

    public function test_produce_skips_ambiguous_rows(): void
    {
        $this->seedRealFacilityData();
        $this->upload()->assertOk();
        $import = DowntimeMatrixImport::sole();

        $row = $import->rows()->where('rule_type', 'FARM_TO_FARM')->whereIn('resolution_status', ['VALID', 'WARNING'])->firstOrFail();
        $row->update(['resolution_status' => 'AMBIGUOUS']);
        $import->refresh();
        $expected = $this->expectedProductionCounts($import);

        $this->ajaxPost(route('downtime-matrix-import.verify', $import))->assertOk();
        $this->ajaxPost(route('downtime-matrix-import.produce', $import))->assertOk();

        $this->assertSame($expected['matrix'], DowntimeMatrix::count(), 'The row forced to AMBIGUOUS must not have contributed a production record.');
    }

    public function test_produce_skips_invalid_rows(): void
    {
        $this->seedRealFacilityData();
        $this->upload()->assertOk();
        $import = DowntimeMatrixImport::sole();

        $row = $import->rows()->where('rule_type', 'FARM_TO_FARM')->whereIn('resolution_status', ['VALID', 'WARNING'])->firstOrFail();
        $row->update(['resolution_status' => 'INVALID']);
        $import->refresh();
        $expected = $this->expectedProductionCounts($import);

        $this->ajaxPost(route('downtime-matrix-import.verify', $import))->assertOk();
        $this->ajaxPost(route('downtime-matrix-import.produce', $import))->assertOk();

        $this->assertSame($expected['matrix'], DowntimeMatrix::count(), 'The row forced to INVALID must not have contributed a production record.');
    }

    public function test_produce_maps_farm_to_farm_rows_using_resolved_facility_ids(): void
    {
        $this->seedRealFacilityData();
        $this->upload()->assertOk();
        $import = DowntimeMatrixImport::sole();
        $saturnToVenus = $import->rows()
            ->where('origin_raw_label', 'Saturn Farm (Green)')
            ->where('destination_raw_label', 'Venus Farm')
            ->sole();
        $this->assertSame('WARNING', $saturnToVenus->resolution_status);
        $saturn = FacilityList::where('facility_name', 'Saturn')->sole();
        $venus = FacilityList::where('facility_name', 'Venus')->sole();
        $this->ajaxPost(route('downtime-matrix-import.verify', $import))->assertOk();

        $this->ajaxPost(route('downtime-matrix-import.produce', $import))->assertOk();

        $rule = DowntimeMatrix::where('origin_facility_id', $saturn->facility_id)
            ->where('destination_facility_id', $venus->facility_id)
            ->sole();
        $this->assertEquals(12.0, (float) $rule->minimum_downtime);
        $this->assertEquals(36.0, (float) $rule->maximum_downtime);
        $this->assertTrue($rule->is_active);
    }

    public function test_produce_maps_warning_rows_resolved_via_normalized_match(): void
    {
        $this->seedRealFacilityData();
        $this->upload()->assertOk();
        $import = DowntimeMatrixImport::sole();
        // Confirmed by test_upload_parses_real_sample_pdf_...: every
        // resolvable label in the real fixture matches via NORMALIZED_NAME,
        // never an exact match, so valid_rows_count is 0 here and every
        // mapped record necessarily came from a WARNING row.
        $this->assertSame(0, $import->valid_rows_count);
        $this->assertGreaterThan(0, $import->warning_rows_count);
        $this->ajaxPost(route('downtime-matrix-import.verify', $import))->assertOk();

        $response = $this->ajaxPost(route('downtime-matrix-import.produce', $import));

        $response->assertOk();
        $import->refresh();
        $this->assertGreaterThan(0, DowntimeMatrix::count() + DowntimeStationary::count(), 'WARNING rows (normalized-match resolutions) must still be mapped to production.');
    }

    public function test_produce_maps_stationary_rows_assigning_destination_facility_id_and_never_stores_outside(): void
    {
        $this->seedRealFacilityData();
        $this->upload()->assertOk();
        $import = DowntimeMatrixImport::sole();
        $stationaryRows = $import->rows()->where('rule_type', 'STATIONARY')->get();
        $this->assertSame(8, $stationaryRows->count());
        $this->ajaxPost(route('downtime-matrix-import.verify', $import))->assertOk();

        $this->ajaxPost(route('downtime-matrix-import.produce', $import))->assertOk();

        $expected = $this->expectedProductionCounts($import);
        $this->assertSame($expected['stationary'], DowntimeStationary::count());
        $this->assertGreaterThan(0, DowntimeStationary::count());
        foreach ($stationaryRows as $row) {
            // Only rows that actually reached production (VALID/WARNING
            // *and* a resolved destination) should have a corresponding
            // downtime_stationary row - a row with a resolved facility but
            // some other disqualifying finding (e.g. an unrelated downtime
            // value problem) is correctly skipped by produce(), so this
            // loop must skip it too rather than asserting one exists.
            if ($row->destination_facility_id === null || !in_array($row->resolution_status, ['VALID', 'WARNING'], true)) {
                continue;
            }
            DowntimeStationary::where('assigned_facility_id', $row->destination_facility_id)
                ->where('minimum_downtime', $row->minimum_downtime)
                ->firstOrFail();
        }
        // downtime_stationary has no origin column at all - "Outside" (the
        // implicit STATIONARY sentinel origin) is structurally impossible
        // to store there. Belt-and-suspenders: confirm no facility is even
        // named "Outside" that a bug could have accidentally resolved to.
        $this->assertSame(0, FacilityList::where('facility_name', 'Outside')->count());
    }

    public function test_produce_expands_lep_dc_group_to_all_active_dc_warehouse_facilities(): void
    {
        $this->seedRealFacilityData();
        $this->upload()->assertOk();
        $import = DowntimeMatrixImport::sole();
        $saturn = FacilityList::where('facility_name', 'Saturn')->sole();
        $lepDcToSaturn = $import->rows()
            ->where('rule_type', 'FARM_TO_FARM')
            ->where('origin_raw_label', 'LEP, DC')
            ->where('destination_facility_id', $saturn->facility_id)
            ->sole();
        $this->assertNull($lepDcToSaturn->origin_facility_id);
        $this->assertSame('DC_WAREHOUSE', $lepDcToSaturn->origin_facility_group_category);
        $this->assertContains($lepDcToSaturn->resolution_status, ['VALID', 'WARNING'], 'this row must be eligible for production for the rest of this test to be meaningful');
        $dcFacilities = FacilityList::whereHas('facilityCategory', fn ($q) => $q->where('facility_category_name', 'DC_WAREHOUSE'))
            ->where('is_active', true)
            ->get();
        $this->assertEqualsCanonicalizing(['DC Plaridel', 'DC Sta. Rosa'], $dcFacilities->pluck('facility_name')->all());
        $this->ajaxPost(route('downtime-matrix-import.verify', $import))->assertOk();

        $this->ajaxPost(route('downtime-matrix-import.produce', $import))->assertOk();

        foreach ($dcFacilities as $dc) {
            $rule = DowntimeMatrix::where('origin_facility_id', $dc->facility_id)
                ->where('destination_facility_id', $saturn->facility_id)
                ->sole();
            $this->assertEquals((float) $lepDcToSaturn->minimum_downtime, (float) $rule->minimum_downtime);
            $this->assertSame($lepDcToSaturn->maximum_downtime === null, $rule->maximum_downtime === null);
        }
        // The group was expanded at production time into individual rows -
        // never materialized back onto the staging row itself.
        $lepDcToSaturn->refresh();
        $this->assertNull($lepDcToSaturn->origin_facility_id);
        $this->assertSame('DC_WAREHOUSE', $lepDcToSaturn->origin_facility_group_category);
    }

    public function test_produce_maps_dormitory_only_downtime_as_minimum_with_null_maximum(): void
    {
        $this->seedRealFacilityData();
        $this->upload()->assertOk();
        $import = DowntimeMatrixImport::sole();
        $saturn = FacilityList::where('facility_name', 'Saturn')->sole();
        // "Kelsey" is a real FARM-category facility in the seeded data, but
        // is NOT one of the 8 farms the sample PDF actually references -
        // Saturn -> Kelsey therefore cannot collide with any of the 56 real
        // farm-pair rows already staged from parsing (every one of those
        // pairs two of the PDF's own 8 farms).
        $kelsey = FacilityList::where('facility_name', 'Kelsey')->sole();

        // The already-computed minimum/maximum on a staging row are copied
        // through as-is (DowntimeNormalizer already resolved
        // Downtime-Area-vs-Dormitory-only semantics at parse time) - this
        // crafts a row shaped exactly like a real "Dormitory only" reading
        // (minimum_downtime = the dormitory hours, maximum_downtime = null)
        // to confirm production mapping doesn't recompute or discard that.
        $row = $import->rows()->where('rule_type', 'FARM_TO_FARM')->whereIn('resolution_status', ['VALID', 'WARNING'])->firstOrFail();
        $row->update([
            'origin_facility_id' => $saturn->facility_id,
            'origin_facility_group_category' => null,
            'destination_facility_id' => $kelsey->facility_id,
            'destination_facility_group_category' => null,
            'minimum_downtime' => 24,
            'maximum_downtime' => null,
        ]);
        $this->ajaxPost(route('downtime-matrix-import.verify', $import))->assertOk();

        $this->ajaxPost(route('downtime-matrix-import.produce', $import))->assertOk();

        $rule = DowntimeMatrix::where('origin_facility_id', $saturn->facility_id)
            ->where('destination_facility_id', $kelsey->facility_id)
            ->sole();
        $this->assertEquals(24.0, (float) $rule->minimum_downtime);
        $this->assertNull($rule->maximum_downtime);
    }

    public function test_produce_deactivates_existing_active_production_rules_but_does_not_delete_them(): void
    {
        $this->seedRealFacilityData();
        // "Kelsey"/"Forestierra"/"Buenavista Farm" are real FARM-category
        // facilities in the seeded data but are NOT among the 8 farms the
        // sample PDF actually references - a rule between them is
        // guaranteed to never be re-touched (and therefore
        // reactivated-in-place via updateOrCreate) by this production run,
        // so it can only ever end up deactivated-and-preserved, which is
        // exactly what this test needs to isolate.
        $kelsey = FacilityList::where('facility_name', 'Kelsey')->sole();
        $forestierra = FacilityList::where('facility_name', 'Forestierra')->sole();

        $oldMatrixRule = DowntimeMatrix::create([
            'origin_facility_id' => $kelsey->facility_id,
            'destination_facility_id' => $forestierra->facility_id,
            'minimum_downtime' => 99,
            'maximum_downtime' => 100,
            'is_active' => true,
        ]);
        $oldStationaryRule = DowntimeStationary::create([
            'assigned_facility_id' => $kelsey->facility_id,
            'minimum_downtime' => 50,
            'maximum_downtime' => 60,
            'is_active' => true,
        ]);

        $this->upload()->assertOk();
        $import = DowntimeMatrixImport::sole();
        $this->ajaxPost(route('downtime-matrix-import.verify', $import))->assertOk();

        $this->ajaxPost(route('downtime-matrix-import.produce', $import))->assertOk();

        $oldMatrixRule->refresh();
        $oldStationaryRule->refresh();
        $this->assertFalse((bool) $oldMatrixRule->is_active, 'The prior active downtime_matrix rule must be deactivated.');
        $this->assertFalse((bool) $oldStationaryRule->is_active, 'The prior active downtime_stationary rule must be deactivated.');
        // Still present in the table, with its original values untouched -
        // deactivated, not deleted or overwritten.
        $this->assertDatabaseHas('downtime_matrix', ['rule_id' => $oldMatrixRule->rule_id, 'minimum_downtime' => 99.00]);
        $this->assertDatabaseHas('downtime_stationary', ['rule_id' => $oldStationaryRule->rule_id, 'minimum_downtime' => 50.00]);
    }

    public function test_a_failed_production_transaction_leaves_the_existing_production_configuration_unchanged(): void
    {
        $this->seedRealFacilityData();
        $saturn = FacilityList::where('facility_name', 'Saturn')->sole();
        $kelsey = FacilityList::where('facility_name', 'Kelsey')->sole();

        // Pre-existing "current" production config that must survive a
        // failed attempt untouched - still active, not deactivated, since
        // deactivation happens inside the same transaction that fails.
        $existingRule = DowntimeMatrix::create([
            'origin_facility_id' => $kelsey->facility_id,
            'destination_facility_id' => $saturn->facility_id,
            'minimum_downtime' => 5,
            'maximum_downtime' => 10,
            'is_active' => true,
        ]);

        $this->upload()->assertOk();
        $import = DowntimeMatrixImport::sole();
        $this->ajaxPost(route('downtime-matrix-import.verify', $import))->assertOk();

        // Force a genuine, unexpected failure partway through the mapping
        // loop. This schema's own constraints make a truly "invalid" DB
        // state hard to construct honestly (a dangling facility id, for
        // instance, can't happen - the staging rows' facility FKs use
        // nullOnDelete(), so deleting a referenced facility nulls the
        // reference out rather than leaving it dangling) - so this stands
        // in for "something unexpected happened mid-mapping" the same way a
        // real transient failure (a lock timeout, a dropped connection)
        // would: it's swapped in only for this one call, after verify()
        // (and the initial real parse) already ran normally.
        $this->partialMock(FacilityImportResolver::class, function ($mock) {
            $mock->shouldReceive('resolveGroupMembers')
                ->andThrow(new \RuntimeException('Simulated failure while expanding a facility group.'));
        });

        $response = $this->ajaxPost(route('downtime-matrix-import.produce', $import));

        $response->assertOk();
        $response->assertSee('Production mapping failed.');
        $import->refresh();
        $this->assertSame('VERIFIED', $import->status, 'A failed production transaction must leave the import at VERIFIED, not PRODUCED.');
        $this->assertNull($import->produced_by);
        $this->assertNull($import->produced_at);
        $existingRule->refresh();
        $this->assertTrue((bool) $existingRule->is_active, 'The prior active rule must still be active - its deactivation was inside the same rolled-back transaction.');
        $this->assertSame(1, DowntimeMatrix::count(), 'No new production rows may survive a rolled-back transaction.');
    }

    // --- Preview page Data Table (rows-data) --------------------------------

    public function test_show_page_shell_has_all_three_tabs_but_leaks_no_row_data(): void
    {
        $this->seedRealFacilityData();
        $this->upload();
        $import = DowntimeMatrixImport::sole();

        $response = $this->ajaxGet(route('downtime-matrix-import.show', $import));

        $response->assertOk();
        $response->assertSee('id="dmi-ftf-table"', false);
        $response->assertSee('id="dmi-stn-table"', false);
        $response->assertSee('id="dmi-oth-table"', false);
        $response->assertSee('id="dmi-ftf-filter-btn"', false);
        $response->assertSee('id="dmi-ftf-filter-origin"', false);
        $response->assertSee('id="dmi-ftf-filter-destination"', false);
        $response->assertSee('id="dmi-stn-filter-destination"', false);
        $response->assertSee('data-dmi-tab="all"', false);
        // The Import Summary aggregate (row counts per category/status) and
        // the Origin/Destination/Designated-Farm filter DROPDOWN OPTIONS
        // (distinct raw labels, small lookup data - same reasoning as every
        // other admin Data Table's filter-dropdown query) are legitimately
        // server-rendered. An actual per-ROW value like validation_message
        // is not - that only ever appears once a Filter click has run.
        $response->assertSee('Saturn Farm (Green)');
        $response->assertDontSee('No downtime required for this cell.');
    }

    public function test_rows_data_endpoint_returns_datatables_envelope_scoped_by_rule_type(): void
    {
        $this->seedRealFacilityData();
        $this->upload();
        $import = DowntimeMatrixImport::sole();

        $response = $this->getJson(route('downtime-matrix-import.rows-data', $import) . '?rule_type=STATIONARY&draw=5');

        $response->assertOk();
        $response->assertJsonPath('draw', 5);
        $response->assertJsonPath('recordsTotal', 8);
        $response->assertJsonPath('recordsFiltered', 8);
        $this->assertCount(8, $response->json('data'));
    }

    public function test_rows_data_filters_by_status(): void
    {
        $this->seedRealFacilityData();
        $this->upload();
        $import = DowntimeMatrixImport::sole();

        // Every real Farm-to-Farm row lands WARNING (normalized-name match,
        // no aliases seeded) per test_upload_parses_real_sample_pdf_...
        $warning = $this->getJson(route('downtime-matrix-import.rows-data', $import) . '?rule_type=FARM_TO_FARM&status=WARNING');
        $warning->assertJsonPath('recordsFiltered', 72);

        $invalid = $this->getJson(route('downtime-matrix-import.rows-data', $import) . '?rule_type=FARM_TO_FARM&status=INVALID');
        $invalid->assertJsonPath('recordsFiltered', 0);
        $this->assertSame([], $invalid->json('data'));
    }

    public function test_rows_data_filters_by_search_on_raw_labels(): void
    {
        $this->seedRealFacilityData();
        $this->upload();
        $import = DowntimeMatrixImport::sole();

        $response = $this->getJson(route('downtime-matrix-import.rows-data', $import) . '?rule_type=FARM_TO_FARM&label_search=Saturn+Farm+(Green)');

        $response->assertOk();
        $this->assertGreaterThan(0, $response->json('recordsFiltered'));
        foreach ($response->json('data') as $row) {
            $this->assertStringContainsString('Saturn', $row['origin_display']);
        }
    }

    public function test_rows_data_filters_by_origin_and_destination_dropdowns_exact_match(): void
    {
        $this->seedRealFacilityData();
        $this->upload();
        $import = DowntimeMatrixImport::sole();

        // Every Saturn-origin Farm-to-Farm row has this exact raw label
        // (confirmed by test_upload_parses_real_sample_pdf_...) - there are
        // 8 farms total, so Saturn's 7 non-self destinations plus its
        // synthesized LEP,DC row = 8 rows for this one origin.
        $originOnly = $this->getJson(route('downtime-matrix-import.rows-data', $import) . '?rule_type=FARM_TO_FARM&origin_raw_label=' . urlencode('Saturn Farm (Green)'));
        $originOnly->assertOk();
        $this->assertSame(8, $originOnly->json('recordsFiltered'));
        foreach ($originOnly->json('data') as $row) {
            $this->assertStringContainsString('Saturn', $row['origin_display']);
        }

        // Combining Origin + Destination narrows to exactly one pair.
        $both = $this->getJson(route('downtime-matrix-import.rows-data', $import)
            . '?rule_type=FARM_TO_FARM'
            . '&origin_raw_label=' . urlencode('Saturn Farm (Green)')
            . '&destination_raw_label=' . urlencode('Venus Farm'));
        $both->assertOk();
        $both->assertJsonPath('recordsFiltered', 1);
        $both->assertJsonPath('data.0.destination_display', 'Venus');

        // "ALL" (the dropdown's default option) means unrestricted, exactly
        // like every other admin Data Table dropdown filter in this app.
        $unfiltered = $this->getJson(route('downtime-matrix-import.rows-data', $import) . '?rule_type=FARM_TO_FARM&origin_raw_label=ALL&destination_raw_label=ALL');
        $unfiltered->assertJsonPath('recordsFiltered', 72);
    }

    public function test_rows_data_stationary_filters_by_designated_farm_dropdown(): void
    {
        $this->seedRealFacilityData();
        $this->upload();
        $import = DowntimeMatrixImport::sole();

        $response = $this->getJson(route('downtime-matrix-import.rows-data', $import) . '?rule_type=STATIONARY&destination_raw_label=' . urlencode('Saturn Farm'));

        $response->assertOk();
        $response->assertJsonPath('recordsFiltered', 1);
        $response->assertJsonPath('data.0.destination_display', 'Saturn');
    }

    public function test_show_page_populates_origin_destination_and_designated_farm_dropdown_options(): void
    {
        $this->seedRealFacilityData();
        $this->upload();
        $import = DowntimeMatrixImport::sole();

        $response = $this->ajaxGet(route('downtime-matrix-import.show', $import));

        $response->assertOk();
        // These are the distinct raw labels actually present in the parsed
        // rows for each tab, not every facility in facility_list - e.g.
        // "Outside" only ever appears as a Stationary origin, never as a
        // Farm-to-Farm option.
        $response->assertSeeInOrder(['id="dmi-ftf-filter-origin"', 'Saturn Farm (Green)'], false);
        $response->assertSeeInOrder(['id="dmi-ftf-filter-destination"', 'Venus'], false);
        $response->assertSeeInOrder(['id="dmi-stn-filter-destination"', 'Saturn'], false);
    }

    public function test_rows_data_resolves_facility_group_display_not_the_raw_category(): void
    {
        $this->seedRealFacilityData();
        $this->upload();
        $import = DowntimeMatrixImport::sole();

        $response = $this->getJson(route('downtime-matrix-import.rows-data', $import) . '?rule_type=FARM_TO_FARM&label_search=LEP&length=20');

        $response->assertOk();
        $this->assertGreaterThan(0, $response->json('recordsFiltered'));
        $groupRow = collect($response->json('data'))
            ->first(fn ($row) => str_starts_with($row['origin_display'], 'DC Warehouses (') || str_starts_with($row['destination_display'], 'DC Warehouses ('));
        $this->assertNotNull($groupRow, 'expected at least one row displaying the resolved "DC Warehouses (...)" group, not the raw "LEP, DC" label');
        $displayed = str_starts_with($groupRow['origin_display'], 'DC Warehouses (') ? $groupRow['origin_display'] : $groupRow['destination_display'];
        $this->assertStringContainsString('DC Plaridel', $displayed);
    }

    public function test_rows_data_stationary_destination_is_a_resolved_facility_name(): void
    {
        $this->seedRealFacilityData();
        $this->upload();
        $import = DowntimeMatrixImport::sole();

        $response = $this->getJson(route('downtime-matrix-import.rows-data', $import) . '?rule_type=STATIONARY&length=20');

        $response->assertOk();
        foreach ($response->json('data') as $row) {
            $this->assertStringNotContainsString('(unresolved)', $row['destination_display']);
        }
    }

    public function test_rows_data_paginates_via_start_and_length(): void
    {
        $this->seedRealFacilityData();
        $this->upload();
        $import = DowntimeMatrixImport::sole();

        $firstPage = $this->getJson(route('downtime-matrix-import.rows-data', $import) . '?rule_type=FARM_TO_FARM&start=0&length=10');
        $firstPage->assertJsonPath('recordsFiltered', 72);
        $this->assertCount(10, $firstPage->json('data'));

        $secondPage = $this->getJson(route('downtime-matrix-import.rows-data', $import) . '?rule_type=FARM_TO_FARM&start=10&length=10');
        $this->assertCount(10, $secondPage->json('data'));
        $this->assertNotSame($firstPage->json('data.0.import_row_id'), $secondPage->json('data.0.import_row_id'));
    }

    public function test_rows_data_orders_by_minimum_downtime_descending_when_requested(): void
    {
        $this->seedRealFacilityData();
        $this->upload();
        $import = DowntimeMatrixImport::sole();

        // JS column position 2 = minimum_downtime for the Farm-to-Farm/Others
        // shape (0=origin, 1=destination, 2=min, 3=max, 4=status, 5=message).
        $response = $this->getJson(route('downtime-matrix-import.rows-data', $import) . '?rule_type=FARM_TO_FARM&length=100&order[0][column]=2&order[0][dir]=desc');

        $response->assertOk();
        $values = collect($response->json('data'))->pluck('minimum_downtime')->filter(fn ($v) => $v !== null)->values();
        $sorted = $values->sortDesc()->values();
        $this->assertSame($sorted->all(), $values->all(), 'rows must be sorted by minimum_downtime descending');
    }

    public function test_rows_data_tolerates_datatables_own_reserved_search_param(): void
    {
        $this->seedRealFacilityData();
        $this->upload();
        $import = DowntimeMatrixImport::sole();

        // DataTables.js always sends search[value]/search[regex] as part of
        // its base request object, even with searching:false and even when
        // a tab's own JS never sets a "search" key itself - this arrives
        // server-side as an array under the query key "search". The
        // Farm-to-Farm/Stationary tabs' own custom filter is deliberately
        // named label_search (not search) specifically to avoid this
        // colliding with a plain string cast; this test locks that in.
        $response = $this->getJson(route('downtime-matrix-import.rows-data', $import)
            . '?rule_type=STATIONARY&search[value]=&search[regex]=false&status=ALL&destination_raw_label=ALL');

        $response->assertOk();
        $response->assertJsonPath('recordsFiltered', 8);
    }

    public function test_rows_data_requires_permission(): void
    {
        $this->seedRealFacilityData();
        $this->upload();
        $import = DowntimeMatrixImport::sole();

        $role = Role::create(['role_name' => 'NoPermissions']);
        $this->actingAs(User::create([
            'role_id' => $role->role_id,
            'user_name' => 'nobody',
            'user_email' => 'nobody@example.com',
            'hash_password' => bcrypt('password'),
            'is_active' => true,
        ]));

        $this->getJson(route('downtime-matrix-import.rows-data', $import))->assertForbidden();
    }

    public function test_update_rows_resolves_an_unmatched_row_and_marks_it_valid(): void
    {
        $this->seedRealFacilityData();
        $this->upload();
        $import = DowntimeMatrixImport::sole();

        $unmatched = $import->rows()
            ->where('origin_raw_label', 'Organikultura Area')
            ->firstOrFail();
        $this->assertNull($unmatched->origin_facility_id);

        $saturn = FacilityList::where('facility_name', 'Saturn')->sole();
        $destinationFacilityId = $unmatched->destination_facility_id;
        $this->assertNotNull($destinationFacilityId, 'destination should already be resolved for this fixture row');

        $editor = auth()->user();

        $response = $this->ajaxPut(route('downtime-matrix-import.rows.update', $import), [
            'rows' => [
                $unmatched->import_row_id => [
                    'origin_facility_id' => $saturn->facility_id,
                    'destination_facility_id' => $destinationFacilityId,
                    'minimum_downtime' => 12,
                    'maximum_downtime' => 24,
                ],
            ],
        ]);

        $response->assertOk();

        // The modal reads this JSON response directly (no page reload) to
        // confirm the save actually applied - it must reflect the new state,
        // not the pre-edit one.
        $response->assertJsonPath('applied', true);
        $savedRow = collect($response->json('rows'))->firstWhere('import_row_id', $unmatched->import_row_id);
        $this->assertNotNull($savedRow);
        $this->assertSame($saturn->facility_id, $savedRow['origin_facility_id']);
        $this->assertSame('VALID', $savedRow['resolution_status']);
        $this->assertEquals(12.0, $savedRow['minimum_downtime']);
        $this->assertEquals(24.0, $savedRow['maximum_downtime']);

        $unmatched->refresh();
        $this->assertSame($saturn->facility_id, $unmatched->origin_facility_id);
        $this->assertSame('VALID', $unmatched->resolution_status);
        $this->assertStringContainsString('Manually verified by', $unmatched->validation_message);
        $this->assertEquals(12.0, (float) $unmatched->minimum_downtime);
        $this->assertEquals(24.0, (float) $unmatched->maximum_downtime);
        $this->assertSame($editor->user_id, $unmatched->edited_by);
        $this->assertNotNull($unmatched->edited_at);
    }

    public function test_update_rows_stays_unmatched_when_a_needed_facility_is_left_unresolved(): void
    {
        $this->seedRealFacilityData();
        $this->upload();
        $import = DowntimeMatrixImport::sole();

        $unmatched = $import->rows()->where('origin_raw_label', 'Fabrication')->firstOrFail();

        $this->ajaxPut(route('downtime-matrix-import.rows.update', $import), [
            'rows' => [
                $unmatched->import_row_id => [
                    'origin_facility_id' => null,
                    'destination_facility_id' => $unmatched->destination_facility_id,
                    'minimum_downtime' => $unmatched->minimum_downtime,
                    'maximum_downtime' => null,
                ],
            ],
        ])->assertOk();

        $unmatched->refresh();
        $this->assertSame('UNMATCHED', $unmatched->resolution_status);
        $this->assertStringContainsString('origin', $unmatched->validation_message);
    }

    public function test_update_rows_flags_invalid_when_maximum_is_less_than_minimum(): void
    {
        $this->seedRealFacilityData();
        $this->upload();
        $import = DowntimeMatrixImport::sole();
        $row = $import->rows()->firstOrFail();

        $this->ajaxPut(route('downtime-matrix-import.rows.update', $import), [
            'rows' => [
                $row->import_row_id => [
                    'origin_facility_id' => $row->origin_facility_id,
                    'destination_facility_id' => $row->destination_facility_id,
                    'minimum_downtime' => 24,
                    'maximum_downtime' => 12,
                ],
            ],
        ])->assertOk();

        $row->refresh();
        $this->assertSame('INVALID', $row->resolution_status);
        $this->assertStringContainsString('cannot be less than minimum', $row->validation_message);
    }

    public function test_update_rows_recomputes_the_parents_denormalized_counts(): void
    {
        $this->seedRealFacilityData();
        $this->upload();
        $import = DowntimeMatrixImport::sole();
        $validCountBefore = $import->valid_rows_count;
        $totalBefore = $import->valid_rows_count + $import->warning_rows_count + $import->unmatched_rows_count + $import->ambiguous_rows_count + $import->invalid_rows_count;

        $unmatched = $import->rows()->where('origin_raw_label', 'Organikultura Area')->firstOrFail();
        $saturn = FacilityList::where('facility_name', 'Saturn')->sole();

        $this->ajaxPut(route('downtime-matrix-import.rows.update', $import), [
            'rows' => [
                $unmatched->import_row_id => [
                    'origin_facility_id' => $saturn->facility_id,
                    'destination_facility_id' => $unmatched->destination_facility_id,
                    'minimum_downtime' => 12,
                    'maximum_downtime' => 24,
                ],
            ],
        ])->assertOk();

        $import->refresh();
        $this->assertSame($validCountBefore + 1, $import->valid_rows_count, 'the edited row should now count toward valid_rows_count');
        $this->assertSame(
            $totalBefore,
            $import->valid_rows_count + $import->warning_rows_count + $import->unmatched_rows_count + $import->ambiguous_rows_count + $import->invalid_rows_count,
            'total row count must be conserved across the recompute'
        );
        $this->assertSame($import->valid_rows_count, DowntimeMatrixImportRow::where('import_id', $import->import_id)->where('resolution_status', 'VALID')->count());
    }

    public function test_update_rows_does_nothing_once_verified(): void
    {
        $this->seedRealFacilityData();
        $this->upload();
        $import = DowntimeMatrixImport::sole();
        $row = $import->rows()->where('resolution_status', '!=', 'VALID')->firstOrFail();
        $originalStatus = $row->resolution_status;

        $this->ajaxPost(route('downtime-matrix-import.verify', $import));

        $saturn = FacilityList::where('facility_name', 'Saturn')->sole();
        $response = $this->ajaxPut(route('downtime-matrix-import.rows.update', $import), [
            'rows' => [
                $row->import_row_id => [
                    'origin_facility_id' => $saturn->facility_id,
                    'destination_facility_id' => $saturn->facility_id,
                    'minimum_downtime' => 1,
                    'maximum_downtime' => 2,
                ],
            ],
        ]);
        $response->assertOk();
        $response->assertJsonPath('applied', false);

        $row->refresh();
        $this->assertSame($originalStatus, $row->resolution_status);
        $this->assertNull($row->edited_by, 'Editing rows on a VERIFIED import must be a no-op.');
    }

    public function test_update_rows_requires_permission(): void
    {
        $this->seedRealFacilityData();
        $this->upload();
        $import = DowntimeMatrixImport::sole();
        $row = $import->rows()->firstOrFail();

        $role = Role::create(['role_name' => 'NoPermissions']);
        $this->actingAs(User::create([
            'role_id' => $role->role_id,
            'user_name' => 'nobody',
            'user_email' => 'nobody@example.com',
            'hash_password' => bcrypt('password'),
            'is_active' => true,
        ]));

        $this->put(route('downtime-matrix-import.rows.update', $import), [
            'rows' => [$row->import_row_id => ['minimum_downtime' => 1]],
        ])->assertForbidden();
    }

    public function test_edit_modal_is_enabled_only_while_pending_verification(): void
    {
        $this->seedRealFacilityData();
        $this->upload();
        $import = DowntimeMatrixImport::sole();

        // Row-level Edit buttons are rendered client-side by the DataTables
        // render() callback, not server-rendered per row - so what the
        // server actually controls is the canEdit JS flag renderActions()
        // checks before emitting a button at all.
        $this->ajaxGet(route('downtime-matrix-import.show', $import))
            ->assertOk()
            ->assertSee('var canEdit = true;', false)
            ->assertSee('id="dmi-edit-modal"', false);

        $this->ajaxPost(route('downtime-matrix-import.verify', $import));

        $this->ajaxGet(route('downtime-matrix-import.show', $import))
            ->assertOk()
            ->assertSee('var canEdit = false;', false);
    }

    public function test_rows_data_includes_the_raw_fields_the_edit_modal_needs(): void
    {
        $this->seedRealFacilityData();
        $this->upload();
        $import = DowntimeMatrixImport::sole();

        $unmatched = $import->rows()
            ->where('rule_type', 'OTHERS')
            ->where('origin_raw_label', 'Organikultura Area')
            ->firstOrFail();

        $response = $this->getJson(route('downtime-matrix-import.rows-data', $import) . '?rule_type=OTHERS&length=100');
        $response->assertOk();

        $payload = collect($response->json('data'))->firstWhere('import_row_id', $unmatched->import_row_id);
        $this->assertNotNull($payload);
        $this->assertSame('OTHERS', $payload['rule_type']);
        $this->assertSame('Organikultura Area', $payload['origin_raw_label']);
        $this->assertArrayHasKey('destination_raw_label', $payload);
        $this->assertNull($payload['origin_facility_id']);
        $this->assertSame($unmatched->destination_facility_id, $payload['destination_facility_id']);
    }
}
