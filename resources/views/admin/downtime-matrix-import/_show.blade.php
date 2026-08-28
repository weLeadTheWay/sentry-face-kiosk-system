<p><a href="{{ route('downtime-matrix-import.index') }}" class="ajax-link">&larr; Downtime Matrix Import</a></p>
<div class="content-header"><h2 class="content-title">BFI/BVA Downtime Matrix Preview</h2></div>

<div class="table-wrapper" style="padding: 1.5rem; margin-bottom: 1.5rem;">
    <p><strong>File:</strong> {{ $downtime_matrix_import->original_filename }}
        &mdash; <a href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($downtime_matrix_import->stored_file_path) }}" target="_blank" rel="noopener">View original PDF</a></p>
    <p><strong>Matrix Type:</strong> {{ $downtime_matrix_import->matrix_type }}</p>
    <p><strong>Status:</strong>
        @php
            $statusColor = match($downtime_matrix_import->status) {
                'VERIFIED' => '#28a745',
                'CANCELLED' => '#6c757d',
                default => '#ffc107',
            };
        @endphp
        <span style="background: {{ $statusColor }}; color: white; padding: 0.25rem 0.5rem; border-radius: 3px; font-size: 12px;">{{ $downtime_matrix_import->status }}</span>
    </p>
    <p><strong>Uploaded By:</strong> {{ $downtime_matrix_import->uploadedBy->user_name ?? '-' }} on {{ $downtime_matrix_import->created_at?->format('n/d/Y H:i') }}</p>
    @if($downtime_matrix_import->isVerified())
        <p><strong>Verified By:</strong> {{ $downtime_matrix_import->verifiedBy->user_name ?? '-' }} on {{ $downtime_matrix_import->verified_at?->format('n/d/Y H:i') }}</p>
    @endif
    @if($downtime_matrix_import->isCancelled())
        <p><strong>Cancelled By:</strong> {{ $downtime_matrix_import->cancelledBy->user_name ?? '-' }} on {{ $downtime_matrix_import->cancelled_at?->format('n/d/Y H:i') }}</p>
    @endif
</div>

@if($downtime_matrix_import->hasParseError())
    <div class="table-wrapper" style="padding: 1.5rem; border-left: 4px solid #dc3545; margin-bottom: 1.5rem;">
        <strong>Parsing failed:</strong> {{ $downtime_matrix_import->parse_error_message }}
        <p style="color: #666; font-size: 13px;">This import has no staged rows. It can only be cancelled - correct the PDF and upload it again.</p>
    </div>
@else
    @php
        $statusBadge = fn($status) => match($status) {
            'VALID' => '#28a745',
            'WARNING' => '#ffc107',
            'UNMATCHED' => '#fd7e14',
            'AMBIGUOUS' => '#6f42c1',
            'INVALID' => '#dc3545',
            default => '#6c757d',
        };

        $sideDisplay = function ($row, string $side) use ($groupMembers, $groupDisplayNames) {
            $groupCategory = $row->{$side . '_facility_group_category'};
            $facility = $row->{$side . 'Facility'};
            $rawLabel = $row->{$side . '_raw_label'};

            if ($groupCategory !== null) {
                $displayName = $groupDisplayNames[$groupCategory] ?? $groupCategory;
                $members = $groupMembers[$groupCategory] ?? [];
                $memberText = count($members) ? implode(', ', $members) : 'none currently active';
                return "{$displayName} ({$memberText})";
            }

            if ($facility) {
                return $facility->facility_name;
            }

            return "{$rawLabel} (unresolved)";
        };
    @endphp

    @php
        $categoryMeta = [
            'FARM_TO_FARM' => ['label' => 'Farm-to-Farm', 'tab' => 'farm-to-farm'],
            'STATIONARY' => ['label' => 'Stationary', 'tab' => 'stationary'],
            'OTHERS' => ['label' => 'Others', 'tab' => 'others'],
        ];
        $statusKeys = ['VALID', 'WARNING', 'UNMATCHED', 'AMBIGUOUS', 'INVALID'];
        $totalRow = ['rows' => 0, 'VALID' => 0, 'WARNING' => 0, 'UNMATCHED' => 0, 'AMBIGUOUS' => 0, 'INVALID' => 0];
        foreach ($categorySummary as $counts) {
            foreach ($totalRow as $key => $value) {
                $totalRow[$key] += $counts[$key];
            }
        }
    @endphp

    {{-- Import Summary: shown first, before any row-level table, so the
         admin understands what was imported before choosing what to
         inspect - per category, not one combined count. --}}
    <div class="content-header"><h3 class="content-title">Import Summary</h3></div>
    <div class="table-wrapper" style="margin-bottom: 1.5rem;">
        <table>
            <thead><tr><th>Category</th><th>Rows</th><th>Valid</th><th>Warning</th><th>Unmatched</th><th>Ambiguous</th><th>Invalid</th></tr></thead>
            <tbody>
                @foreach($categoryMeta as $key => $meta)
                    <tr>
                        <td>{{ $meta['label'] }}</td>
                        <td>{{ $categorySummary[$key]['rows'] }}</td>
                        <td>{{ $categorySummary[$key]['VALID'] }}</td>
                        <td>{{ $categorySummary[$key]['WARNING'] }}</td>
                        <td>{{ $categorySummary[$key]['UNMATCHED'] }}</td>
                        <td>{{ $categorySummary[$key]['AMBIGUOUS'] }}</td>
                        <td>{{ $categorySummary[$key]['INVALID'] }}</td>
                    </tr>
                @endforeach
                <tr style="font-weight: bold;">
                    <td>Total</td>
                    <td>{{ $totalRow['rows'] }}</td>
                    <td>{{ $totalRow['VALID'] }}</td>
                    <td>{{ $totalRow['WARNING'] }}</td>
                    <td>{{ $totalRow['UNMATCHED'] }}</td>
                    <td>{{ $totalRow['AMBIGUOUS'] }}</td>
                    <td>{{ $totalRow['INVALID'] }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- Category tabs: only the selected category's table is shown, so the
         admin isn't confronted with every parsed row at once. --}}
    <div style="margin-bottom: 1rem;">
        @foreach($categoryMeta as $key => $meta)
            <button type="button" class="btn btn-secondary dmi-tab-btn" data-dmi-tab="{{ $meta['tab'] }}" onclick="dmiShowTab('{{ $meta['tab'] }}')">{{ $meta['label'] }} ({{ $categorySummary[$key]['rows'] }} rows)</button>
        @endforeach
    </div>

    <p id="dmi-tab-hint" style="color: #666; font-size: 13px;">Select a category above to view its rows.</p>

    <div id="dmi-tab-farm-to-farm" style="display: none;">
        <div class="table-wrapper"><table><thead><tr><th>Origin Farm</th><th>Destination Farm</th><th>Minimum Downtime</th><th>Maximum Downtime</th><th>Status</th><th>Message</th></tr></thead><tbody>
        @forelse($farmToFarmRows as $row)
            <tr>
                <td>{{ $sideDisplay($row, 'origin') }}</td>
                <td>{{ $sideDisplay($row, 'destination') }}</td>
                <td>{{ $row->minimum_downtime ?? '-' }}</td>
                <td>{{ $row->maximum_downtime ?? '-' }}</td>
                <td><span style="background: {{ $statusBadge($row->resolution_status) }}; color: white; padding: 0.25rem 0.5rem; border-radius: 3px; font-size: 12px;">{{ $row->resolution_status }}</span></td>
                <td style="font-size: 13px; color: #666;">{{ $row->validation_message }}</td>
            </tr>
        @empty
            <tr><td colspan="6" style="text-align: center; padding: 2rem;">No farm-to-farm rules parsed.</td></tr>
        @endforelse
        </tbody></table></div>
    </div>

    <div id="dmi-tab-stationary" style="display: none;">
        <div class="table-wrapper"><table><thead><tr><th>Designated Farm</th><th>Minimum Downtime</th><th>Maximum Downtime</th><th>Status</th><th>Message</th></tr></thead><tbody>
        @forelse($stationaryRows as $row)
            <tr>
                <td>{{ $sideDisplay($row, 'destination') }}</td>
                <td>{{ $row->minimum_downtime ?? '-' }}</td>
                <td>{{ $row->maximum_downtime ?? '-' }}</td>
                <td><span style="background: {{ $statusBadge($row->resolution_status) }}; color: white; padding: 0.25rem 0.5rem; border-radius: 3px; font-size: 12px;">{{ $row->resolution_status }}</span></td>
                <td style="font-size: 13px; color: #666;">{{ $row->validation_message }}</td>
            </tr>
        @empty
            <tr><td colspan="5" style="text-align: center; padding: 2rem;">No stationary rules parsed.</td></tr>
        @endforelse
        </tbody></table></div>
    </div>

    <div id="dmi-tab-others" style="display: none;">
        <p style="color: #666; font-size: 13px;">Rows that don't fit Farm-to-Farm (a real farm or "LEP, DC" on at least one side, matched to a farm) or Stationary ("Outside" to a farm) - e.g. Organikultura Area / Fabrication.</p>
        <div class="table-wrapper"><table><thead><tr><th>Origin</th><th>Destination</th><th>Minimum Downtime</th><th>Maximum Downtime</th><th>Status</th><th>Message</th></tr></thead><tbody>
        @forelse($othersRows as $row)
            <tr>
                <td>{{ $sideDisplay($row, 'origin') }}</td>
                <td>{{ $sideDisplay($row, 'destination') }}</td>
                <td>{{ $row->minimum_downtime ?? '-' }}</td>
                <td>{{ $row->maximum_downtime ?? '-' }}</td>
                <td><span style="background: {{ $statusBadge($row->resolution_status) }}; color: white; padding: 0.25rem 0.5rem; border-radius: 3px; font-size: 12px;">{{ $row->resolution_status }}</span></td>
                <td style="font-size: 13px; color: #666;">{{ $row->validation_message }}</td>
            </tr>
        @empty
            <tr><td colspan="6" style="text-align: center; padding: 2rem;">No other rules parsed.</td></tr>
        @endforelse
        </tbody></table></div>
    </div>

    <script>
        function dmiShowTab(tab) {
            document.getElementById('dmi-tab-hint').style.display = 'none';
            document.getElementById('dmi-tab-farm-to-farm').style.display = tab === 'farm-to-farm' ? 'block' : 'none';
            document.getElementById('dmi-tab-stationary').style.display = tab === 'stationary' ? 'block' : 'none';
            document.getElementById('dmi-tab-others').style.display = tab === 'others' ? 'block' : 'none';
            document.querySelectorAll('.dmi-tab-btn').forEach(function (btn) {
                btn.classList.toggle('btn-secondary', btn.getAttribute('data-dmi-tab') !== tab);
            });
        }
    </script>
@endif

@if($downtime_matrix_import->isPendingVerification())
    <div class="form-actions" style="margin-top: 1.5rem;">
        <form method="POST" action="{{ route('downtime-matrix-import.verify', $downtime_matrix_import) }}" class="ajax-form" style="display: inline;">
            @csrf
            <button type="submit" class="btn">Verify / Continue</button>
        </form>
        <form method="POST" action="{{ route('downtime-matrix-import.cancel', $downtime_matrix_import) }}" class="ajax-form" style="display: inline;" onsubmit="return confirm('Cancel this import?');">
            @csrf
            <button type="submit" class="btn btn-danger">Cancel</button>
        </form>
    </div>
@endif
