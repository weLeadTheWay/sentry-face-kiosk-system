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

            $import->update([
                'total_rows_parsed' => count($result['rows']),
                'valid_rows_count' => $result['counts']['VALID'],
                'warning_rows_count' => $result['counts']['WARNING'],
                'unmatched_rows_count' => $result['counts']['UNMATCHED'],
                'ambiguous_rows_count' => $result['counts']['AMBIGUOUS'],
                'invalid_rows_count' => $result['counts']['INVALID'],
            ]);
        });
    }
}
