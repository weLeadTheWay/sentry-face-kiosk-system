<?php

namespace App\Services;

use App\Models\AuditLog;

class AuditLogService
{
    public function getRecordLogs(string $module, int $recordId): \Illuminate\Support\Collection
    {
        return AuditLog::where('module', $module)
            ->where('record_id', $recordId)
            ->orderBy('created_at', 'desc')
            ->get();
    }
}
