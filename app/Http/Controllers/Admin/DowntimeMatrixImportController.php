<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDowntimeMatrixImportRequest;
use App\Models\DowntimeMatrixImport;
use App\Services\DowntimeMatrixImport\DowntimeMatrixImportService;
use App\Services\DowntimeMatrixImport\FacilityImportResolver;

class DowntimeMatrixImportController extends Controller
{
    public function __construct(
        private readonly DowntimeMatrixImportService $service,
        private readonly FacilityImportResolver $facilityResolver,
    ) {
    }

    public function index()
    {
        $downtime_matrix_imports = DowntimeMatrixImport::with('uploadedBy')
            ->orderByDesc('import_id')
            ->paginate(config('sentry.pagination'));

        return $this->view('admin.downtime-matrix-import._index', compact('downtime_matrix_imports'));
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

    private function showResponse(DowntimeMatrixImport $downtime_matrix_import)
    {
        $downtime_matrix_import->load(['rows.originFacility', 'rows.destinationFacility', 'uploadedBy', 'verifiedBy', 'cancelledBy']);

        $farmToFarmRows = $downtime_matrix_import->rows->where('rule_type', 'FARM_TO_FARM')->values();
        $stationaryRows = $downtime_matrix_import->rows->where('rule_type', 'STATIONARY')->values();
        $othersRows = $downtime_matrix_import->rows->where('rule_type', 'OTHERS')->values();

        $groupMembers = [];
        $groupDisplayNames = [];
        foreach ($downtime_matrix_import->rows as $row) {
            foreach (['origin_facility_group_category', 'destination_facility_group_category'] as $field) {
                $category = $row->{$field};
                if ($category !== null && !isset($groupMembers[$category])) {
                    $groupMembers[$category] = $this->facilityResolver->resolveGroupMembers($category)
                        ->pluck('facility_name')
                        ->all();
                    $groupDisplayNames[$category] = collect(config('downtime_matrix_import.facility_groups', []))
                        ->firstWhere('category', $category)['display_name'] ?? $category;
                }
            }
        }

        $categorySummary = [
            'FARM_TO_FARM' => $this->summarizeByStatus($farmToFarmRows),
            'STATIONARY' => $this->summarizeByStatus($stationaryRows),
            'OTHERS' => $this->summarizeByStatus($othersRows),
        ];

        return $this->view(
            'admin.downtime-matrix-import._show',
            compact('downtime_matrix_import', 'farmToFarmRows', 'stationaryRows', 'othersRows', 'groupMembers', 'groupDisplayNames', 'categorySummary'),
            'admin.downtime-matrix-import.show'
        );
    }

    /**
     * @param \Illuminate\Support\Collection $rows
     * @return array{rows: int, VALID: int, WARNING: int, UNMATCHED: int, AMBIGUOUS: int, INVALID: int}
     */
    private function summarizeByStatus($rows): array
    {
        $counts = ['VALID' => 0, 'WARNING' => 0, 'UNMATCHED' => 0, 'AMBIGUOUS' => 0, 'INVALID' => 0];

        foreach ($rows as $row) {
            $counts[$row->resolution_status] = ($counts[$row->resolution_status] ?? 0) + 1;
        }

        return array_merge(['rows' => $rows->count()], $counts);
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
