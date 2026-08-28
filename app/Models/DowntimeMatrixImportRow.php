<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Auditable is included deliberately, but has no effect on the bulk
 * machine-parsed insert: DowntimeMatrixImportService::persistRows() writes
 * via the query builder's insert(), which bypasses Eloquent model events
 * entirely, so the original parse never generates audit_logs rows (avoiding
 * the volume concern that excluded this model from Auditable originally).
 * A row edited later via ->update() (see updateRows()) DOES fire the
 * 'updated' event, so only genuine human corrections are ever audited -
 * exactly the compliance-relevant subset.
 */
class DowntimeMatrixImportRow extends Model
{
    use HasFactory, Auditable;

    protected $table = 'downtime_matrix_import_rows';
    protected $primaryKey = 'import_row_id';
    public $incrementing = true;

    protected $fillable = [
        'import_id',
        'rule_type',
        'origin_raw_label',
        'destination_raw_label',
        'origin_facility_id',
        'destination_facility_id',
        'origin_resolution_method',
        'destination_resolution_method',
        'origin_facility_group_category',
        'destination_facility_group_category',
        'downtime_area_hours',
        'dormitory_hours',
        'minimum_downtime',
        'maximum_downtime',
        'clean_downtime_area_hours',
        'clean_dormitory_hours',
        'restricted_downtime_area_hours',
        'restricted_dormitory_hours',
        'resolution_status',
        'validation_message',
        'page_number',
        'edited_by',
        'edited_at',
    ];

    protected function casts(): array
    {
        return [
            'downtime_area_hours' => 'decimal:2',
            'dormitory_hours' => 'decimal:2',
            'minimum_downtime' => 'decimal:2',
            'maximum_downtime' => 'decimal:2',
            'clean_downtime_area_hours' => 'decimal:2',
            'clean_dormitory_hours' => 'decimal:2',
            'restricted_downtime_area_hours' => 'decimal:2',
            'restricted_dormitory_hours' => 'decimal:2',
            'edited_at' => 'datetime',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(DowntimeMatrixImport::class, 'import_id', 'import_id');
    }

    public function originFacility(): BelongsTo
    {
        return $this->belongsTo(FacilityList::class, 'origin_facility_id', 'facility_id');
    }

    public function destinationFacility(): BelongsTo
    {
        return $this->belongsTo(FacilityList::class, 'destination_facility_id', 'facility_id');
    }

    public function editedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by', 'user_id');
    }

    public function isFacilityGroup(): bool
    {
        return $this->origin_facility_group_category !== null || $this->destination_facility_group_category !== null;
    }

    public function wasManuallyEdited(): bool
    {
        return $this->edited_by !== null;
    }
}
