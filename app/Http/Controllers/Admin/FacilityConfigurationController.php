<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\HandlesDataTablesRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateFacilityConfigurationRequest;
use App\Models\FacilityList;
use Illuminate\Http\JsonResponse;

/**
 * Centralized checkbox-grid editor for facility_list's per-facility runtime
 * toggles (is_gs, is_break_enabled, is_truck) - lets an admin flip these
 * without opening each facility's own edit form individually. Reuses
 * facilities.manage since this edits the same underlying facility_list
 * rows FacilityController already manages, just through a different UI.
 * Not a full CRUD resource - only index/data (listing) and update
 * (toggling one field on one existing facility) exist; there is no
 * create/store/destroy here.
 */
class FacilityConfigurationController extends Controller
{
    use HandlesDataTablesRequest;

    public function index()
    {
        return $this->view('admin.facility-configuration._index');
    }

    public function data(): JsonResponse
    {
        $base = FacilityList::query()
            ->select(['facility_id', 'facility_code', 'facility_name', 'is_gs', 'is_break_enabled', 'is_truck']);

        $recordsTotal = (clone $base)->count();

        $filtered = clone $base;

        $search = trim((string) request()->query('search', ''));
        if ($search !== '') {
            $filtered->where(function ($q) use ($search) {
                $q->where('facility_code', 'like', '%' . $search . '%')
                    ->orWhere('facility_name', 'like', '%' . $search . '%');
            });
        }

        $recordsFiltered = (clone $filtered)->count();

        // JS column positions: 0 => facility_code, 1 => facility_name, then
        // three non-orderable toggle columns - keep this map in sync with
        // the columns config in _index.blade.php if it's ever reordered.
        $orderableColumns = [0 => 'facility_code', 1 => 'facility_name'];
        $orderColumn = $this->dtOrderColumn($orderableColumns, $orderableColumns[1]);

        $rows = $filtered
            ->orderBy($orderColumn, $this->dtOrderDir())
            ->offset($this->dtStart())
            ->limit($this->dtLength())
            ->get();

        return response()->json([
            'draw' => $this->dtDraw(),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $rows->map(fn (FacilityList $facility) => [
                'facility_id' => $facility->facility_id,
                'facility_code' => $facility->facility_code,
                'facility_name' => $facility->facility_name,
                'is_gs' => (bool) $facility->is_gs,
                'is_break_enabled' => (bool) $facility->is_break_enabled,
                'is_truck' => (bool) $facility->is_truck,
            ])->all(),
        ]);
    }

    /**
     * Toggles exactly one boolean configuration field on one facility.
     * $field is restricted to a fixed allow-list by the FormRequest's own
     * validation rule (in:is_gs,is_break_enabled,is_truck) - this endpoint
     * can never be used to write an arbitrary column.
     */
    public function update(UpdateFacilityConfigurationRequest $request, FacilityList $facility): JsonResponse
    {
        $validated = $request->validated();

        $facility->update([$validated['field'] => $validated['value']]);

        return response()->json([
            'success' => true,
            'facility_id' => $facility->facility_id,
            'field' => $validated['field'],
            'value' => (bool) $facility->{$validated['field']},
        ]);
    }

    private function view($view, $data = [])
    {
        if (request()->ajax()) {
            return view($view, $data);
        }
        return view('admin.facility-configuration.index', $data);
    }
}
