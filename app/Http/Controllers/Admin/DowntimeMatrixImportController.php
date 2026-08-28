<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\HandlesDataTablesRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDowntimeMatrixImportRequest;
use App\Http\Requests\Admin\UpdateDowntimeMatrixImportRowsRequest;
use App\Models\DowntimeMatrixImport;
use App\Models\DowntimeMatrixImportRow;
use App\Models\FacilityList;
use App\Services\DowntimeMatrixImport\DowntimeMatrixImportService;
use App\Services\DowntimeMatrixImport\FacilityImportResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DowntimeMatrixImportController extends Controller
{
    use HandlesDataTablesRequest;

    public function __construct(
        private readonly DowntimeMatrixImportService $service,
        private readonly FacilityImportResolver $facilityResolver,
    ) {
    }

    public function index()
    {
        return $this->view('admin.downtime-matrix-import._index', [], 'admin.downtime-matrix-import.index');
    }

    public function data(): JsonResponse
    {
        $base = DowntimeMatrixImport::query()
            ->select(['import_id', 'original_filename', 'matrix_type', 'status', 'uploaded_by', 'created_at', 'valid_rows_count', 'warning_rows_count', 'unmatched_rows_count', 'ambiguous_rows_count', 'invalid_rows_count'])
            ->with('uploadedBy:user_id,user_name');

        $recordsTotal = (clone $base)->count();

        $filtered = clone $base;

        $status = request()->query('status');
        if ($status !== null && $status !== '' && $status !== 'ALL') {
            $filtered->where('status', $status);
        }

        $matrixType = request()->query('matrix_type');
        if ($matrixType !== null && $matrixType !== '' && $matrixType !== 'ALL') {
            $filtered->where('matrix_type', $matrixType);
        }

        $recordsFiltered = (clone $filtered)->count();

        // Keys are the real JS column position (0=original_filename,
        // 1=matrix_type[non-orderable], 2=uploaded_by[non-orderable],
        // 3=created_at, 4=status..9=invalid_rows_count[all non-orderable]),
        // matching what DataTables reports back.
        $orderableColumns = [0 => 'original_filename', 3 => 'created_at'];
        $orderColumn = $this->dtOrderColumn($orderableColumns, 'import_id');

        $rows = $filtered
            ->orderBy($orderColumn, $this->dtOrderDir())
            ->offset($this->dtStart())
            ->limit($this->dtLength())
            ->get();

        return response()->json([
            'draw' => $this->dtDraw(),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $rows->map(fn (DowntimeMatrixImport $import) => [
                'import_id' => $import->import_id,
                'original_filename' => $import->original_filename,
                'matrix_type' => $import->matrix_type,
                'uploaded_by' => $import->uploadedBy->user_name ?? null,
                'created_at' => $import->created_at?->format('n/d/Y H:i'),
                'status' => $import->status,
                'valid_rows_count' => $import->valid_rows_count,
                'warning_rows_count' => $import->warning_rows_count,
                'unmatched_rows_count' => $import->unmatched_rows_count,
                'ambiguous_rows_count' => $import->ambiguous_rows_count,
                'invalid_rows_count' => $import->invalid_rows_count,
            ])->all(),
        ]);
    }

    public function create()
    {
        return $this->view('admin.downtime-matrix-import._create', [], 'admin.downtime-matrix-import.create');
    }

    public function store(StoreDowntimeMatrixImportRequest $request)
    {
        $import = $this->service->import(
            $request->file('pdf_file'),
            $request->validated()['matrix_type'],
            auth()->user(),
        );

        return $this->showResponse($import);
    }

    public function show(DowntimeMatrixImport $downtime_matrix_import)
    {
        return $this->showResponse($downtime_matrix_import);
    }

    public function verify(DowntimeMatrixImport $downtime_matrix_import)
    {
        if (!$downtime_matrix_import->isPendingVerification()) {
            return $this->showResponse($downtime_matrix_import);
        }

        $this->service->verify($downtime_matrix_import, auth()->user());

        return $this->showResponse($downtime_matrix_import->fresh());
    }

    public function cancel(DowntimeMatrixImport $downtime_matrix_import)
    {
        if ($downtime_matrix_import->isPendingVerification()) {
            $this->service->cancel($downtime_matrix_import, auth()->user());
        }

        return $this->index();
    }

    /**
     * The confirmation step for promoting a VERIFIED import - reachable via
     * the "Production" action on the import list (visible only for VERIFIED
     * rows). Reuses the same Preview view as show(), just with
     * $promotionMode=true so its bottom action block renders "Save to
     * Production"/"Cancel" instead of nothing - this lets an admin review
     * the exact same staged rows one more time before confirming. Falls
     * back to a plain (non-promotion-mode) Preview if the import isn't
     * actually VERIFIED (e.g. a stale link, or it was already promoted),
     * mirroring how verify()/cancel() already fall back for a status that's
     * no longer actionable.
     */
    public function promoteConfirm(DowntimeMatrixImport $downtime_matrix_import)
    {
        if (!$downtime_matrix_import->isVerified()) {
            return $this->showResponse($downtime_matrix_import);
        }

        return $this->showResponse($downtime_matrix_import, promotionMode: true);
    }

    /**
     * Marks the import PROMOTED. This is a status-only transition, same
     * shape as verify()/cancel() - it does NOT map or write any staged row
     * into downtime_matrix/downtime_stationary. That promotion-target
     * mapping is explicitly out of scope for this phase; this action only
     * records that an admin confirmed the import at the review step above.
     */
    public function promote(DowntimeMatrixImport $downtime_matrix_import)
    {
        if ($downtime_matrix_import->isVerified()) {
            $this->service->promote($downtime_matrix_import, auth()->user());
        }

        return $this->showResponse($downtime_matrix_import->fresh());
    }

    /**
     * Saves a manual correction from the Preview page's per-row "Edit"
     * modal - lets an admin fix a row flagged WARNING/UNMATCHED/AMBIGUOUS/
     * INVALID (the resolved Origin/Destination facility and/or the
     * downtime hours) instead of only being able to correct the source
     * PDF and re-upload. The request shape (rows: {id: {...}}) supports
     * more than one row per call, but the modal only ever sends one - a
     * no-op (not an error) once the import is no longer
     * PENDING_VERIFICATION, since editing a decision that's already been
     * made doesn't make sense; the JSON response's (unchanged) row data
     * lets the modal detect that and inform the admin rather than
     * silently appearing to have saved.
     */
    public function updateRows(UpdateDowntimeMatrixImportRowsRequest $request, DowntimeMatrixImport $downtime_matrix_import): JsonResponse
    {
        $applied = $downtime_matrix_import->isPendingVerification();

        if ($applied) {
            $this->service->updateRows($downtime_matrix_import, $request->validated()['rows'], auth()->user());
        }

        $rowIds = array_keys($request->validated()['rows']);
        $groupDisplayCache = [];
        $rows = DowntimeMatrixImportRow::where('import_id', $downtime_matrix_import->import_id)
            ->whereIn('import_row_id', $rowIds)
            ->with(['originFacility:facility_id,facility_name', 'destinationFacility:facility_id,facility_name'])
            ->get()
            ->map(fn (DowntimeMatrixImportRow $row) => $this->rowPayload($row, $groupDisplayCache));

        return response()->json(['applied' => $applied, 'rows' => $rows->values()]);
    }

    /**
     * jQuery DataTables server-side processing endpoint for one import's
     * staged rows, scoped by rule_type (one of the three Preview tabs) plus
     * the Status/Search filters. This is a sibling to data() above, not a
     * reuse of it - the underlying model, columns, and filters are entirely
     * different (one import's rows vs. the list of imports).
     */
    public function rowsData(DowntimeMatrixImport $downtime_matrix_import): JsonResponse
    {
        $ruleType = request()->query('rule_type', 'FARM_TO_FARM');

        $base = DowntimeMatrixImportRow::query()
            ->where('import_id', $downtime_matrix_import->import_id)
            ->where('rule_type', $ruleType)
            ->with([
                'originFacility:facility_id,facility_name',
                'destinationFacility:facility_id,facility_name',
            ]);

        $recordsTotal = (clone $base)->count();

        $filtered = clone $base;

        $status = request()->query('status');
        if ($status !== null && $status !== '' && $status !== 'ALL') {
            $filtered->where('resolution_status', $status);
        }

        // Origin/Destination are dropdowns of the raw labels actually present
        // in this import (see showResponse()'s *Origins/*Destinations
        // queries) - exact match, not a "contains" search, since the admin
        // is picking one of a known, enumerated set rather than typing text.
        $origin = request()->query('origin_raw_label');
        if ($origin !== null && $origin !== '' && $origin !== 'ALL') {
            $filtered->where('origin_raw_label', $origin);
        }

        $destination = request()->query('destination_raw_label');
        if ($destination !== null && $destination !== '' && $destination !== 'ALL') {
            $filtered->where('destination_raw_label', $destination);
        }

        // Named label_search, not search: DataTables.js always sends its own
        // reserved search[value]/search[regex] object in every request (even
        // with the search box hidden via `searching: false`) - a custom
        // param literally named "search" collides with that and arrives
        // here as an array, not a string, once nothing else in the request
        // happens to overwrite it.
        $labelSearch = trim((string) request()->query('label_search', ''));
        if ($labelSearch !== '') {
            $filtered->where(function ($q) use ($labelSearch) {
                $q->where('origin_raw_label', 'like', '%' . $labelSearch . '%')
                    ->orWhere('destination_raw_label', 'like', '%' . $labelSearch . '%');
            });
        }

        $recordsFiltered = (clone $filtered)->count();

        // The Preview has 3 tabs sharing this one endpoint (never a separate
        // endpoint per tab - same table, same columns, just a different
        // rule_type filter), but Stationary's JS table omits the Origin
        // column, shifting every later column's real position left by one -
        // so the index->column-name map depends on which tab is asking.
        $orderableColumns = $ruleType === 'STATIONARY'
            ? [1 => 'minimum_downtime', 2 => 'maximum_downtime', 3 => 'resolution_status']
            : [2 => 'minimum_downtime', 3 => 'maximum_downtime', 4 => 'resolution_status'];
        $orderColumn = $this->dtOrderColumn($orderableColumns, 'minimum_downtime');

        $rows = $filtered
            ->orderBy($orderColumn, $this->dtOrderDir())
            ->offset($this->dtStart())
            ->limit($this->dtLength())
            ->get();

        $groupDisplayCache = [];

        return response()->json([
            'draw' => $this->dtDraw(),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $rows->map(fn (DowntimeMatrixImportRow $row) => $this->rowPayload($row, $groupDisplayCache))->all(),
        ]);
    }

    /**
     * The JSON shape sent for one staged row - used both by the DataTables
     * rows-data endpoint (rendering the Preview's tables) and by
     * updateRows() (reporting back what a save actually resulted in). The
     * *_display fields are pre-formatted for read-only display; the raw
     * *_facility_id/*_raw_label/rule_type fields are what the per-row Edit
     * modal needs to pre-fill its form with the row's actual editable
     * state, not just its rendered text.
     */
    private function rowPayload(DowntimeMatrixImportRow $row, array &$groupDisplayCache): array
    {
        return [
            'import_row_id' => $row->import_row_id,
            'rule_type' => $row->rule_type,
            'origin_raw_label' => $row->origin_raw_label,
            'destination_raw_label' => $row->destination_raw_label,
            'origin_facility_id' => $row->origin_facility_id,
            'destination_facility_id' => $row->destination_facility_id,
            'origin_display' => $this->sideDisplay($row, 'origin', $groupDisplayCache),
            'destination_display' => $this->sideDisplay($row, 'destination', $groupDisplayCache),
            'minimum_downtime' => $row->minimum_downtime !== null ? (float) $row->minimum_downtime : null,
            'maximum_downtime' => $row->maximum_downtime !== null ? (float) $row->maximum_downtime : null,
            'resolution_status' => $row->resolution_status,
            'validation_message' => $row->validation_message,
        ];
    }

    private function showResponse(DowntimeMatrixImport $downtime_matrix_import, bool $promotionMode = false)
    {
        $downtime_matrix_import->load(['uploadedBy', 'verifiedBy', 'cancelledBy']);

        $categorySummary = [
            'FARM_TO_FARM' => ['rows' => 0, 'VALID' => 0, 'WARNING' => 0, 'UNMATCHED' => 0, 'AMBIGUOUS' => 0, 'INVALID' => 0],
            'STATIONARY' => ['rows' => 0, 'VALID' => 0, 'WARNING' => 0, 'UNMATCHED' => 0, 'AMBIGUOUS' => 0, 'INVALID' => 0],
            'OTHERS' => ['rows' => 0, 'VALID' => 0, 'WARNING' => 0, 'UNMATCHED' => 0, 'AMBIGUOUS' => 0, 'INVALID' => 0],
        ];
        $farmToFarmOrigins = collect();
        $farmToFarmDestinations = collect();
        $stationaryDestinations = collect();

        // For the per-row Edit modal's Origin/Destination dropdowns - every
        // active facility, not just ones referenced by this import (unlike
        // the filter dropdowns below), since an admin correcting a row may
        // need to pick a facility this import's own labels never matched.
        $facilities = FacilityList::where('is_active', true)->orderBy('facility_name')->get(['facility_id', 'facility_name']);

        if (!$downtime_matrix_import->hasParseError()) {
            // A single GROUP BY aggregate, not a load-every-row-and-tally in
            // PHP - the summary must never be the reason this page loads all
            // of an import's rows.
            $counts = DowntimeMatrixImportRow::query()
                ->where('import_id', $downtime_matrix_import->import_id)
                ->select('rule_type', 'resolution_status', DB::raw('count(*) as cnt'))
                ->groupBy('rule_type', 'resolution_status')
                ->get();

            foreach ($counts as $count) {
                if (!isset($categorySummary[$count->rule_type])) {
                    continue;
                }
                $categorySummary[$count->rule_type]['rows'] += $count->cnt;
                $categorySummary[$count->rule_type][$count->resolution_status] = $count->cnt;
            }

            // Origin/Destination filter dropdown options: the distinct raw
            // labels actually present in this import's rows for that
            // category - small lookup queries (bounded by how many unique
            // labels the PDF has, not by row count), same reasoning as every
            // other admin Data Table's filter-dropdown lookup query. Never
            // the rows themselves.
            $farmToFarmOrigins = DowntimeMatrixImportRow::query()
                ->where('import_id', $downtime_matrix_import->import_id)
                ->where('rule_type', 'FARM_TO_FARM')
                ->distinct()
                ->orderBy('origin_raw_label')
                ->pluck('origin_raw_label');

            $farmToFarmDestinations = DowntimeMatrixImportRow::query()
                ->where('import_id', $downtime_matrix_import->import_id)
                ->where('rule_type', 'FARM_TO_FARM')
                ->distinct()
                ->orderBy('destination_raw_label')
                ->pluck('destination_raw_label');

            $stationaryDestinations = DowntimeMatrixImportRow::query()
                ->where('import_id', $downtime_matrix_import->import_id)
                ->where('rule_type', 'STATIONARY')
                ->distinct()
                ->orderBy('destination_raw_label')
                ->pluck('destination_raw_label');
        }

        return $this->view(
            'admin.downtime-matrix-import._show',
            compact('downtime_matrix_import', 'categorySummary', 'farmToFarmOrigins', 'farmToFarmDestinations', 'stationaryDestinations', 'facilities', 'promotionMode'),
            'admin.downtime-matrix-import.show'
        );
    }

    /**
     * Mirrors the original Blade $sideDisplay closure, moved server-side now
     * that rows are fetched a page at a time via JSON instead of all at once
     * in the view. $groupDisplayCache memoizes by facility-group category so
     * a page of many rows in the same group only resolves it once (though
     * FacilityImportResolver::resolveGroupMembers() already caches its own
     * underlying facility query internally regardless).
     */
    private function sideDisplay(DowntimeMatrixImportRow $row, string $side, array &$groupDisplayCache): string
    {
        $groupCategory = $row->{$side . '_facility_group_category'};
        $facility = $row->{$side . 'Facility'};
        $rawLabel = $row->{$side . '_raw_label'};

        if ($groupCategory !== null) {
            if (!isset($groupDisplayCache[$groupCategory])) {
                $members = $this->facilityResolver->resolveGroupMembers($groupCategory)->pluck('facility_name')->all();
                $displayName = collect(config('downtime_matrix_import.facility_groups', []))
                    ->firstWhere('category', $groupCategory)['display_name'] ?? $groupCategory;
                $memberText = count($members) ? implode(', ', $members) : 'none currently active';
                $groupDisplayCache[$groupCategory] = "{$displayName} ({$memberText})";
            }

            return $groupDisplayCache[$groupCategory];
        }

        if ($facility) {
            return $facility->facility_name;
        }

        return "{$rawLabel} (unresolved)";
    }

    /**
     * Unlike every other Admin controller's partial/full-page split (where
     * the "full page" fallback is always the index listing, since every
     * other action is only ever reached via an .ajax-link/.ajax-form), the
     * upload form here is deliberately a plain, non-ajax browser POST (file
     * inputs don't survive admin.js's jQuery serialize()) - so store()'s
     * response is genuinely, routinely rendered as a full page, not just a
     * rare direct-URL edge case. $fullPageView lets each call site pick the
     * correct full-page wrapper instead of assuming it's always the index.
     */
    private function view($view, $data = [], $fullPageView = 'admin.downtime-matrix-import.index')
    {
        if (request()->ajax()) {
            return view($view, $data);
        }

        return view($fullPageView, $data);
    }
}
