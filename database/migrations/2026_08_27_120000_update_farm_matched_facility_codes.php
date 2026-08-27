<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Real farm_code/location values for the 8 facility_list rows (seeded in
     * Phase 1) that correspond 1:1, by name, to existing farm_list records -
     * replaces their placeholder TYPE-SLUG codes now that the real codes are
     * known. See CLAUDE.md's "Facility Master Data" module for the approved
     * farm_id -> facility_id mapping this was derived from.
     */
    private array $updates = [
        'Saturn' => ['facility_code' => 'SLF'],
        'Venus' => ['facility_code' => 'VENUS'],
        'Cinnamon' => ['facility_code' => 'CLF'],
        'Mars' => ['facility_code' => 'MARS'],
        'Madera' => ['facility_code' => 'MLF', 'location' => 'Tarlac City'],
        'Rosemary' => ['facility_code' => 'RLF'],
        'San Pascual' => ['facility_code' => 'SPLF'],
        'Victory' => ['facility_code' => 'VLF'],
    ];

    private array $placeholders = [
        'Saturn' => 'BVA-SATURN',
        'Venus' => 'BVA-VENUS',
        'Cinnamon' => 'BVA-CINNAMON',
        'Mars' => 'BVA-MARS',
        'Madera' => 'BVA-MADERA',
        'Rosemary' => 'BVA-ROSEMARY',
        'San Pascual' => 'BVA-SANPASCUAL',
        'Victory' => 'BVA-VICTORY',
    ];

    public function up(): void
    {
        foreach ($this->updates as $name => $values) {
            DB::table('facility_list')->where('facility_name', $name)->update($values);
        }
    }

    public function down(): void
    {
        foreach ($this->placeholders as $name => $code) {
            DB::table('facility_list')->where('facility_name', $name)->update([
                'facility_code' => $code,
                'location' => null,
            ]);
        }
    }
};
