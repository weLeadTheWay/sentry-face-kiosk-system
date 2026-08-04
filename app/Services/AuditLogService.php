<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class AuditLogService
{
    public function getFilteredLogs(
        ?string $module = null,
        ?int $userId = null,
        ?string $action = null,
        int $perPage = 50
    ): LengthAwarePaginator {
        $query = AuditLog::query();

        if ($module) {
            $query->where('module', $module);
        }

        if ($userId) {
            $query->where('user_id', $userId);
        }

        if ($action) {
            $query->where('action', $action);
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    public function getRecordLogs(string $module, int $recordId): \Illuminate\Support\Collection
    {
        return AuditLog::where('module', $module)
            ->where('record_id', $recordId)
            ->orderBy('created_at', 'desc')
            ->get();
    }
}
