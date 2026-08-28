<?php

namespace App\Services\DowntimeMatrixImport;

use App\Models\DowntimeMatrix;
use App\Models\DowntimeMatrixImport;
use App\Models\DowntimeMatrixImportRow;
use App\Models\DowntimeStationary;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Orchestrates the Phase 1 pipeline: store the uploaded PDF, parse it,
 * resolve/normalize/classify/validate every candidate rule, and stage the
 * result. Never writes to downtime_matrix/downtime_stationary - the only
 * new records this creates are the import header and its staging rows.
 *
 * Parsing runs synchronously on the upload request (no queue) - the
 * BFI/BVA matrix is a single page with on the order of a hundred cells,
 * well within a normal request timeout.
 *
 * Phase 2 (produce(), below) adds the actual mapping into
 * downtime_matrix/downtime_stationary once an import is VERIFIED - see that
 * method's docblock.
 */
class DowntimeMatrixImportService
{
    private const STATUS_KEYS = ['VALID', 'WARNING', 'UNMATCHED', 'AMBIGUOUS', 'INVALID'];

    /** @var string[] resolution_status values eligible to reach production */
    private const PRODUCIBLE_STATUSES = ['VALID', 'WARNING'];

    public function __construct(
        private readonly PdfTextExtractor $extractor,
        private readonly GridReconstructor $gridReconstructor,
        private readonly MatrixGridParser $gridParser,
        private readonly ImportValidator $validator,
        private readonly FacilityImportResolver $facilityResolver,
    ) {
    }

    public function import(UploadedFile $file, string $matrixType, User $uploader): DowntimeMatrixImport
    {
        $import = DowntimeMatrixImport::create([
            'matrix_type' => $matrixType,
            'original_filename' => $file->getClientOriginalName(),
            'stored_file_path' => '',
            'status' => 'PENDING_VERIFICATION',
            'uploaded_by' => $uploader->user_id,
        ]);

        $storedPath = $file->store("downtime-matrix-imports/{$import->import_id}", 'public');
        $import->update(['stored_file_path' => $storedPath]);

        try {
            $absolutePath = Storage::disk('public')->path($storedPath);
            $fragments = $this->extractor->extractFragments($absolutePath);
            $reconstructed = $this->gridReconstructor->reconstruct($fragments);
            $candidates = $this->gridParser->parse($reconstructed);
            $result = $this->validator->validate($candidates);

            $this->persistRows($import, $result);
        } catch (\Throwable $e) {
            Log::error('Downtime Matrix Import parse failed', [
                'import_id' => $import->import_id,
                'error' => $e->getMessage(),
            ]);
            $import->update(['parse_error_message' => $e->getMessage()]);
        }

        return $import->fresh();
    }

    public function verify(DowntimeMatrixImport $import, User $verifier): DowntimeMatrixImport
    {
        $import->update([
            'status' => 'VERIFIED',
            'verified_by' => $verifier->user_id,
            'verified_at' => now(),
        ]);

        return $import;
    }

    public function cancel(DowntimeMatrixImport $import, User $canceller): DowntimeMatrixImport
    {
        $import->update([
            'status' => 'CANCELLED',
            'cancelled_by' => $canceller->user_id,
            'cancelled_at' => now(),
        ]);

        return $import;
    }

    /**
     * Maps a VERIFIED import's eligible staging rows (resolution_status
     * VALID or WARNING) into the live downtime_matrix/downtime_stationary
     * configuration, then marks the import PRODUCED. The caller
     * (controller) is responsible for enforcing isVerified() first - this
     * method does not re-check it.
     *
     * Everything happens inside one DB transaction: existing active
     * downtime_matrix/downtime_stationary rows are deactivated (never
     * deleted - they remain as history), the new rows are written
     * is_active=true, and the import's status/produced_by/produced_at are
     * stamped last. If anything throws partway through, the transaction
     * rolls back automatically and this method re-throws nothing further -
     * it catches the failure itself, logs it, and returns a
     * success:false result so the caller can show what happened without a
     * 500, mirroring how import() already handles a parse failure by
     * recording it rather than letting the request fail outright. Either
     * way, downtime_matrix_import_rows and the original uploaded PDF are
     * never touched by this method.
     *
     * A staging row's origin/destination facility-group category (e.g.
     * "LEP, DC" -> DC_WAREHOUSE) is expanded into its CURRENT active
     * member facilities right here, at production time - via the same
     * $facilityResolver the parse pipeline uses - never by reusing
     * whatever the group's membership happened to be at parse time. This
     * is why a single FARM_TO_FARM staging row can produce more than one
     * downtime_matrix row.
     *
     * All actual production writes are BULK operations (Model::upsert()/
     * where()->update()), not one Eloquent create()/update() per row - a
     * real import can have 60-100+ eligible rows once facility-group
     * expansion is counted, and one query (plus one audit_logs insert) per
     * row measurably exceeded PHP's execution time limit against a real,
     * non-trivially-latent DB connection. This deliberately bypasses
     * Eloquent model events for these specific writes - no per-production-
     * rule audit_logs rows - the same precedent already established for
     * DowntimeMatrixImportRow::insert() during the parse pipeline. The
     * import's own status/produced_by/produced_at change further below
     * still goes through a normal Eloquent update() and IS audit-logged, so
     * there is still a single "who produced this import, and when" record
     * - just not a per-production-rule one.
     *
     * @return array{
     *     success: bool,
     *     error?: string,
     *     staging_rows_processed?: int,
     *     production_records_created?: int,
     *     mapped?: array{VALID: int, WARNING: int},
     *     skipped?: array{UNMATCHED: int, AMBIGUOUS: int, INVALID: int},
     * }
     */
    public function produce(DowntimeMatrixImport $import, User $producer): array
    {
        try {
            return DB::transaction(function () use ($import, $producer) {
                $rows = DowntimeMatrixImportRow::where('import_id', $import->import_id)
                    ->whereIn('resolution_status', self::PRODUCIBLE_STATUSES)
                    ->get();

                $now = now();

                // Keyed by the production table's own unique columns, so two
                // staging rows that happen to target the same pair/
                // assignment (a genuine in-import duplicate, or two
                // facility-group expansions overlapping) collapse to one
                // write instead of being sent to upsert() twice - the later
                // occurrence wins, same as a sequential updateOrCreate()
                // would have produced.
                $matrixRows = [];
                $stationaryRows = [];
                $mapped = ['VALID' => 0, 'WARNING' => 0];

                foreach ($rows as $row) {
                    if ($this->collectProductionRows($row, $matrixRows, $stationaryRows, $now)) {
                        $mapped[$row->resolution_status]++;
                    }
                }

                // Deactivated, never deleted - this is what keeps the prior
                // configuration available as history while making the newly
                // produced rules the current active one.
                DowntimeMatrix::where('is_active', true)->update(['is_active' => false]);
                DowntimeStationary::where('is_active', true)->update(['is_active' => false]);

                if (!empty($matrixRows)) {
                    // downtime_matrix has a standing
                    // UNIQUE(origin_facility_id, destination_facility_id)
                    // constraint that applies regardless of is_active, so a
                    // pair touched by an earlier production run (now sitting
                    // deactivated, per the step above) can't be re-inserted
                    // as a second row - upsert() reactivates/updates it in
                    // place instead. created_at is deliberately not in the
                    // update-columns list, so an existing row's original
                    // created_at is preserved on reactivation.
                    DowntimeMatrix::upsert(
                        array_values($matrixRows),
                        ['origin_facility_id', 'destination_facility_id'],
                        ['minimum_downtime', 'maximum_downtime', 'is_active', 'updated_at'],
                    );
                }

                if (!empty($stationaryRows)) {
                    // Same reasoning as downtime_matrix above, keyed by
                    // downtime_stationary's own UNIQUE(assigned_facility_id).
                    DowntimeStationary::upsert(
                        array_values($stationaryRows),
                        ['assigned_facility_id'],
                        ['minimum_downtime', 'maximum_downtime', 'is_active', 'updated_at'],
                    );
                }

                $import->update([
                    'status' => 'PRODUCED',
                    'produced_by' => $producer->user_id,
                    'produced_at' => $now,
                ]);

                return [
                    'success' => true,
                    'staging_rows_processed' => $rows->count(),
                    'production_records_created' => count($matrixRows) + count($stationaryRows),
                    'mapped' => $mapped,
                    'skipped' => [
                        'UNMATCHED' => $import->unmatched_rows_count,
                        'AMBIGUOUS' => $import->ambiguous_rows_count,
                        'INVALID' => $import->invalid_rows_count,
                    ],
                ];
            });
        } catch (\Throwable $e) {
            Log::error('Downtime Matrix Import production mapping failed', [
                'import_id' => $import->import_id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Appends one eligible staging row's target production row(s) into
     * $matrixRows (FARM_TO_FARM) or $stationaryRows (STATIONARY), expanding
     * either side's facility group (if any) into its current active member
     * facilities. Returns whether this row contributed at least one target
     * row - false for an OTHERS row (no production table target exists for
     * it, regardless of status) or, defensively, a row missing a required
     * resolved facility on either side.
     */
    private function collectProductionRows(DowntimeMatrixImportRow $row, array &$matrixRows, array &$stationaryRows, \Illuminate\Support\Carbon $now): bool
    {
        if ($row->rule_type === 'FARM_TO_FARM') {
            $originIds = $this->resolvedFacilityIds($row, 'origin');
            $destinationIds = $this->resolvedFacilityIds($row, 'destination');

            // Do not target a production row when either required facility
            // cannot be resolved - should not happen for a VALID/WARNING
            // row in practice (every finding that would leave a side
            // unresolved ranks at or above WARNING), but this is the
            // defense-in-depth backstop.
            if (empty($originIds) || empty($destinationIds)) {
                return false;
            }

            $added = false;

            foreach ($originIds as $originId) {
                foreach ($destinationIds as $destinationId) {
                    if ($originId === $destinationId) {
                        // A genuine self-pair is always INVALID at parse
                        // time (see ImportValidator) and so never reaches
                        // here, but a facility-group expansion could in
                        // principle overlap the other side - skip rather
                        // than ever targeting a self-referencing rule.
                        continue;
                    }

                    $matrixRows["{$originId}-{$destinationId}"] = [
                        'origin_facility_id' => $originId,
                        'destination_facility_id' => $destinationId,
                        'minimum_downtime' => $row->minimum_downtime,
                        'maximum_downtime' => $row->maximum_downtime,
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    $added = true;
                }
            }

            return $added;
        }

        if ($row->rule_type === 'STATIONARY') {
            // STATIONARY's origin is always the recognized "Outside"
            // sentinel by construction (see RuleClassifier) - it is never
            // stored as a facility, on either the staging row or here. The
            // staging row's destination_facility_id becomes
            // downtime_stationary's assigned_facility_id.
            if ($row->destination_facility_id === null) {
                return false;
            }

            $stationaryRows[(string) $row->destination_facility_id] = [
                'assigned_facility_id' => $row->destination_facility_id,
                'minimum_downtime' => $row->minimum_downtime,
                'maximum_downtime' => $row->maximum_downtime,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            return true;
        }

        // OTHERS has no downtime_matrix/downtime_stationary equivalent -
        // e.g. "Organikultura Area", "Fabrication" - never mapped, even if
        // somehow VALID/WARNING.
        return false;
    }

    /**
     * Resolves one side of a staging row to the facility id(s) it should
     * map to in production: the single resolved facility_id if there is
     * one, or - for a facility-group match (facility_id null,
     * *_facility_group_category set) - every facility CURRENTLY active in
     * that category, queried fresh via $facilityResolver right now, not
     * whatever the group's membership was at parse time. Never both at
     * once (a row's own side is either a single facility or a group, not
     * a "raw label" fallback - production mapping only ever uses resolved
     * ids, per the instructions for this phase).
     *
     * @return int[]
     */
    private function resolvedFacilityIds(DowntimeMatrixImportRow $row, string $side): array
    {
        $facilityId = $row->{$side . '_facility_id'};
        if ($facilityId !== null) {
            return [$facilityId];
        }

        $groupCategory = $row->{$side . '_facility_group_category'};
        if ($groupCategory !== null) {
            return $this->facilityResolver->resolveGroupMembers($groupCategory)->pluck('facility_id')->all();
        }

        return [];
    }

    /**
     * Applies an admin's manual corrections to a set of staged rows -
     * intended for rows resolution left flagged (WARNING/UNMATCHED/
     * AMBIGUOUS/INVALID), letting an admin fix the resolved facility and/or
     * downtime values directly instead of correcting the PDF and
     * re-uploading. Only meaningful while the import is still
     * PENDING_VERIFICATION - the caller (controller) is responsible for
     * enforcing that; this method does not re-check it, so it can also be
     * reused safely by anything that already holds that guarantee.
     *
     * @param array<int|string, array{origin_facility_id?: ?int, destination_facility_id?: ?int, minimum_downtime?: ?float, maximum_downtime?: ?float}> $rowsInput keyed by import_row_id
     */
    public function updateRows(DowntimeMatrixImport $import, array $rowsInput, User $editor): void
    {
        DB::transaction(function () use ($import, $rowsInput, $editor) {
            $rows = DowntimeMatrixImportRow::where('import_id', $import->import_id)
                ->whereIn('import_row_id', array_keys($rowsInput))
                ->get()
                ->keyBy('import_row_id');

            foreach ($rowsInput as $rowId => $input) {
                $row = $rows->get((int) $rowId);
                if ($row === null) {
                    // Defense-in-depth: ignore any row id that doesn't
                    // actually belong to this import rather than trusting
                    // client input blindly.
                    continue;
                }

                $row->origin_facility_id = $input['origin_facility_id'] ?? null;
                $row->destination_facility_id = $input['destination_facility_id'] ?? null;
                $row->minimum_downtime = $input['minimum_downtime'] ?? null;
                $row->maximum_downtime = $input['maximum_downtime'] ?? null;

                // A manually-chosen single facility always supersedes any
                // prior facility-group resolution on that side - a human
                // decision wins over the automatic group match.
                if ($row->origin_facility_id !== null) {
                    $row->origin_facility_group_category = null;
                }
                if ($row->destination_facility_id !== null) {
                    $row->destination_facility_group_category = null;
                }

                [$status, $message] = $this->recomputeStatusAfterManualEdit($row, $editor);
                $row->resolution_status = $status;
                $row->validation_message = $message;
                $row->edited_by = $editor->user_id;
                $row->edited_at = now();
                $row->save();
            }

            $import->update($this->countsFor($import));
        });
    }

    /**
     * @param array{rows: array<int, array>, counts: array<string, int>} $result
     */
    private function persistRows(DowntimeMatrixImport $import, array $result): void
    {
        DB::transaction(function () use ($import, $result) {
            if (!empty($result['rows'])) {
                $now = now();
                $rows = array_map(function (array $row) use ($import, $now) {
                    $row['import_id'] = $import->import_id;
                    $row['created_at'] = $now;
                    $row['updated_at'] = $now;

                    return $row;
                }, $result['rows']);

                DowntimeMatrixImportRow::insert($rows);
            }

            $import->update(array_merge(
                ['total_rows_parsed' => count($result['rows'])],
                $this->countsFor($import, $result['counts']),
            ));
        });
    }

    /**
     * Recomputes the parent import's denormalized *_rows_count columns
     * from the current state of its staged rows - used both right after
     * parsing (where the freshly-computed counts are already known and
     * passed in to avoid a redundant query) and after a manual edit (where
     * they're re-aggregated from the database, since edits change
     * individual rows' statuses one at a time).
     *
     * @param array<string, int>|null $knownCounts
     * @return array<string, int>
     */
    private function countsFor(DowntimeMatrixImport $import, ?array $knownCounts = null): array
    {
        if ($knownCounts !== null) {
            return [
                'valid_rows_count' => $knownCounts['VALID'],
                'warning_rows_count' => $knownCounts['WARNING'],
                'unmatched_rows_count' => $knownCounts['UNMATCHED'],
                'ambiguous_rows_count' => $knownCounts['AMBIGUOUS'],
                'invalid_rows_count' => $knownCounts['INVALID'],
            ];
        }

        $counts = array_fill_keys(self::STATUS_KEYS, 0);

        DowntimeMatrixImportRow::where('import_id', $import->import_id)
            ->selectRaw('resolution_status, count(*) as cnt')
            ->groupBy('resolution_status')
            ->get()
            ->each(function ($row) use (&$counts) {
                if (isset($counts[$row->resolution_status])) {
                    $counts[$row->resolution_status] = $row->cnt;
                }
            });

        return [
            'valid_rows_count' => $counts['VALID'],
            'warning_rows_count' => $counts['WARNING'],
            'unmatched_rows_count' => $counts['UNMATCHED'],
            'ambiguous_rows_count' => $counts['AMBIGUOUS'],
            'invalid_rows_count' => $counts['INVALID'],
        ];
    }

    /**
     * A deliberately simple recheck, not a re-run of the full parse-time
     * ImportValidator pipeline (that operates on raw PDF text/candidates,
     * not on an admin's already-chosen facility_id values). Checks the
     * things a manual edit can actually fix: does each side that needs a
     * resolved facility now have one, and are the downtime values sane.
     *
     * @return array{0: string, 1: string}
     */
    private function recomputeStatusAfterManualEdit(DowntimeMatrixImportRow $row, User $editor): array
    {
        if ($row->minimum_downtime !== null && $row->maximum_downtime !== null
            && (float) $row->maximum_downtime < (float) $row->minimum_downtime) {
            return ['INVALID', 'Maximum downtime cannot be less than minimum downtime.'];
        }

        // STATIONARY rows have no real origin to resolve - "Outside" is the
        // implicit, unstated origin by construction (see RuleClassifier) -
        // so only the destination side is ever checked for that rule_type.
        $originNeedsFacility = $row->rule_type !== 'STATIONARY';
        $originResolved = !$originNeedsFacility || $row->origin_facility_id !== null || $row->origin_facility_group_category !== null;
        $destinationResolved = $row->destination_facility_id !== null || $row->destination_facility_group_category !== null;

        if (!$originResolved || !$destinationResolved) {
            $missing = array_filter([
                !$originResolved ? 'origin' : null,
                !$destinationResolved ? 'destination' : null,
            ]);

            return ['UNMATCHED', 'Still missing a resolved facility for: ' . implode(' and ', $missing) . '.'];
        }

        return ['VALID', "Manually verified by {$editor->user_name} on " . now()->format('n/d/Y H:i') . '.'];
    }
}
