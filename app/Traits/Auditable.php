<?php

namespace App\Traits;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (Model $model) {
            $model->logAuditEvent('create', null, $model->getAttributes());
        });

        static::updated(function (Model $model) {
            $model->logAuditEvent('update', $model->getOriginal(), $model->getChanges());
        });

        static::deleted(function (Model $model) {
            $model->logAuditEvent('delete', $model->getAttributes(), null);
        });
    }

    private function logAuditEvent(string $action, ?array $oldValues, ?array $newValues): void
    {
        AuditLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'module' => class_basename($this),
            'record_id' => $this->getKey(),
            'old_value_json' => $oldValues ? json_encode($oldValues) : null,
            'new_value_json' => $newValues ? json_encode($newValues) : null,
            'ip_address' => request()?->ip(),
            'created_at' => now(),
        ]);
    }
}
