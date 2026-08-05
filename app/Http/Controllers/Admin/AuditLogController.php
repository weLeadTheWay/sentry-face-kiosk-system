<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;

class AuditLogController extends Controller
{
    public function __construct(private AuditLogService $service) {}

    public function index()
    {
        $module = request()->query('module');
        $user_id = request()->query('user_id');
        $action = request()->query('action');

        $logs = $this->service->getFilteredLogs($module, $user_id, $action);

        return $this->view('admin.audit-logs._index', compact('logs'));
    }

    private function view($view, $data = [])
    {
        if (request()->ajax()) {
            return view($view, $data);
        }
        return view('admin.audit-logs.index', $data);
    }
}
