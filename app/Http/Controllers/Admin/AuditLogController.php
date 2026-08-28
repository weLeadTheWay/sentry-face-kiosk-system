<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\HandlesDataTablesRequest;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class AuditLogController extends Controller
{
    use HandlesDataTablesRequest;

    /**
     * The Module/Action filter dropdowns are populated from the distinct
     * values that already exist in audit_logs - not from a fixed enum
     * (there isn't one; "module" is an Auditable model's class basename,
     * "action" is created/updated/deleted, both open-ended). This is a
     * small aggregate lookup query, not the audit trail's own records - the
     * Data Table itself stays empty until Filter is clicked.
     */
    public function index()
    {
        $modules = AuditLog::query()->select('module')->distinct()->orderBy('module')->pluck('module');
        $actions = AuditLog::query()->select('action')->distinct()->orderBy('action')->pluck('action');
        $users = User::query()->select(['user_id', 'user_name'])->orderBy('user_name')->get();

        return $this->view('admin.audit-logs._index', compact('modules', 'actions', 'users'));
    }

    public function data(): JsonResponse
    {
        $base = AuditLog::query()
            ->select(['audit_log_id', 'module', 'action', 'user_id', 'created_at'])
            ->with('user:user_id,user_name');

        $recordsTotal = (clone $base)->count();

        $filtered = clone $base;

        $module = request()->query('module');
        if ($module !== null && $module !== '' && $module !== 'ALL') {
            $filtered->where('module', $module);
        }

        $action = request()->query('action');
        if ($action !== null && $action !== '' && $action !== 'ALL') {
            $filtered->where('action', $action);
        }

        $userId = request()->query('user_id');
        if ($userId !== null && $userId !== '' && $userId !== 'ALL') {
            $filtered->where('user_id', $userId);
        }

        $recordsFiltered = (clone $filtered)->count();

        // JS column positions: 0=module, 1=action, 2=user[non-orderable relation], 3=date, 4=details[non].
        $orderableColumns = [0 => 'module', 1 => 'action', 3 => 'created_at'];
        $orderColumn = $this->dtOrderColumn($orderableColumns, 'created_at');

        $rows = $filtered
            ->orderBy($orderColumn, $this->dtOrderDir())
            ->offset($this->dtStart())
            ->limit($this->dtLength())
            ->get();

        return response()->json([
            'draw' => $this->dtDraw(),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $rows->map(fn (AuditLog $log) => [
                'audit_log_id' => $log->audit_log_id,
                'module' => $log->module,
                'action' => $log->action,
                'user_name' => $log->user->user_name ?? null,
                'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
                // audit_logs has no change_log column - the pre-existing
                // Blade view referenced one anyway and always rendered
                // blank; this preserves that same (already-empty) "Details"
                // column rather than fixing an unrelated gap in this pass.
                'change_log' => null,
            ])->all(),
        ]);
    }

    private function view($view, $data = [])
    {
        if (request()->ajax()) {
            return view($view, $data);
        }
        return view('admin.audit-logs.index', $data);
    }
}
