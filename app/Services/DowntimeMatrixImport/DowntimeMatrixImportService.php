<?php

namespace App\Services\DowntimeMatrixImport;

use App\Models\DowntimeMatrixImport;
use App\Models\DowntimeMatrixImportRow;
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
 */
class DowntimeMatrixImportService
{
    private const STATUS_KEYS = ['VALID', 'WARNING', 'UNMATCHED', 'AMBIGUOUS', 'INVALID'];

    public function __construct(
        private readonly PdfTextExtractor $extractor,
        private readonly GridReconstructor $gridReconstructor,
        private readonly MatrixGridParser $gridParser,
        private readonly ImportValidator $validator,
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
     * Marks a VERIFIED import as promoted to production. This is a
     * status-only transition, same as verify()/cancel() - it does NOT map
     * or write any staged row into downtime_matrix/downtime_stationary.
     * That mapping is a deliberately separate, not-yet-built phase; this
     * method only exists to record that an admin reviewed the import one
     * more time at the confirmation step and chose to proceed. The caller
     * (controller) is responsible for enforcing isVerified() first - this
     * method does not re-check it.
     */
    public function promote(DowntimeMatrixImport $import, User $promoter): DowntimeMatrixImport
    {
        $import->update([
            'status' => 'PROMOTED',
            'promoted_by' => $promoter->user_id,
            'promoted_at' => now(),
        ]);

        return $import;
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
