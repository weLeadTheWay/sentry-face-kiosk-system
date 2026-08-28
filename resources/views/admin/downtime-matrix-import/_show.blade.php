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
        $categoryMeta = [
            'FARM_TO_FARM' => ['label' => 'Farm-to-Farm', 'tab' => 'farm-to-farm'],
            'STATIONARY' => ['label' => 'Stationary', 'tab' => 'stationary'],
            'OTHERS' => ['label' => 'Others', 'tab' => 'others'],
        ];
        $totalRow = ['rows' => 0, 'VALID' => 0, 'WARNING' => 0, 'UNMATCHED' => 0, 'AMBIGUOUS' => 0, 'INVALID' => 0];
        foreach ($categorySummary as $counts) {
            foreach ($totalRow as $key => $value) {
                $totalRow[$key] += $counts[$key];
            }
        }
    @endphp

    {{-- Import Summary: shown first, before any row-level table, so the
         admin understands what was imported before choosing what to
         inspect - per category, not one combined count. This is a cheap
         aggregate query (GROUP BY rule_type, resolution_status), never a
         load of every row. --}}
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

    {{-- Category tabs: only the selected category's Data Table is shown, so
         the admin isn't confronted with every parsed row at once. Switching
         tabs is a pure display toggle - it does not load anything; each
         tab's own Data Table only loads once its own Filter button is
         clicked, same as every other admin Data Table in this app. "Show
         All" reveals all three shells at once AND ensures each one that
         hasn't been loaded yet gets loaded (with whatever is currently in
         its own filter fields, default "All" if untouched) - it's a bundle
         of three explicit loads triggered by one click, not an automatic
         load on page open. --}}
    <div style="margin-bottom: 1rem;">
        <button type="button" class="btn btn-secondary dmi-tab-btn" data-dmi-tab="all" onclick="dmiShowTab('all')">Show All</button>
        @foreach($categoryMeta as $key => $meta)
            <button type="button" class="btn btn-secondary dmi-tab-btn" data-dmi-tab="{{ $meta['tab'] }}" onclick="dmiShowTab('{{ $meta['tab'] }}')">{{ $meta['label'] }} ({{ $categorySummary[$key]['rows'] }} rows)</button>
        @endforeach
    </div>

    <p id="dmi-tab-hint" style="color: #666; font-size: 13px;">Select a category above to view its rows, or click "Show All" to view every category at once.</p>

    <div id="dmi-tab-farm-to-farm" style="display: none;">
        <div class="table-wrapper" style="padding: 1rem 1.5rem;">
            <div style="display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap;">
                <div class="form-group" style="margin-bottom: 0; min-width: 200px;">
                    <label for="dmi-ftf-filter-origin">Origin</label>
                    <select id="dmi-ftf-filter-origin">
                        <option value="ALL">All Origins</option>
                        @foreach($farmToFarmOrigins as $origin)
                            <option value="{{ $origin }}">{{ $origin }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group" style="margin-bottom: 0; min-width: 200px;">
                    <label for="dmi-ftf-filter-destination">Destination</label>
                    <select id="dmi-ftf-filter-destination">
                        <option value="ALL">All Destinations</option>
                        @foreach($farmToFarmDestinations as $destination)
                            <option value="{{ $destination }}">{{ $destination }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group" style="margin-bottom: 0; min-width: 180px;">
                    <label for="dmi-ftf-filter-status">Status</label>
                    <select id="dmi-ftf-filter-status">
                        <option value="ALL">All</option>
                        <option value="VALID">Valid</option>
                        <option value="WARNING">Warning</option>
                        <option value="UNMATCHED">Unmatched</option>
                        <option value="AMBIGUOUS">Ambiguous</option>
                        <option value="INVALID">Invalid</option>
                    </select>
                </div>
                <div style="display: flex; gap: 0.5rem;">
                    <button type="button" id="dmi-ftf-filter-btn" class="btn">Filter</button>
                    <button type="button" id="dmi-ftf-reset-btn" class="btn btn-secondary">Reset</button>
                </div>
            </div>
        </div>
        <div class="table-wrapper">
            <table id="dmi-ftf-table" class="display" style="width: 100%;">
                <thead><tr><th>Origin Farm</th><th>Destination Farm</th><th>Minimum Downtime</th><th>Maximum Downtime</th><th>Status</th><th>Message</th></tr></thead>
                <tbody></tbody>
            </table>
        </div>
    </div>

    <div id="dmi-tab-stationary" style="display: none;">
        <div class="table-wrapper" style="padding: 1rem 1.5rem;">
            <div style="display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap;">
                <div class="form-group" style="margin-bottom: 0; min-width: 220px;">
                    <label for="dmi-stn-filter-destination">Designated Farm</label>
                    <select id="dmi-stn-filter-destination">
                        <option value="ALL">All Farms</option>
                        @foreach($stationaryDestinations as $destination)
                            <option value="{{ $destination }}">{{ $destination }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group" style="margin-bottom: 0; min-width: 180px;">
                    <label for="dmi-stn-filter-status">Status</label>
                    <select id="dmi-stn-filter-status">
                        <option value="ALL">All</option>
                        <option value="VALID">Valid</option>
                        <option value="WARNING">Warning</option>
                        <option value="UNMATCHED">Unmatched</option>
                        <option value="AMBIGUOUS">Ambiguous</option>
                        <option value="INVALID">Invalid</option>
                    </select>
                </div>
                <div style="display: flex; gap: 0.5rem;">
                    <button type="button" id="dmi-stn-filter-btn" class="btn">Filter</button>
                    <button type="button" id="dmi-stn-reset-btn" class="btn btn-secondary">Reset</button>
                </div>
            </div>
        </div>
        <div class="table-wrapper">
            <table id="dmi-stn-table" class="display" style="width: 100%;">
                <thead><tr><th>Designated Farm</th><th>Minimum Downtime</th><th>Maximum Downtime</th><th>Status</th><th>Message</th></tr></thead>
                <tbody></tbody>
            </table>
        </div>
    </div>

    <div id="dmi-tab-others" style="display: none;">
        <p style="color: #666; font-size: 13px;">Rows that don't fit Farm-to-Farm (a real farm or "LEP, DC" on at least one side, matched to a farm) or Stationary ("Outside" to a farm) - e.g. Organikultura Area / Fabrication.</p>
        <div class="table-wrapper" style="padding: 1rem 1.5rem;">
            <div style="display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap;">
                <div class="form-group" style="margin-bottom: 0; min-width: 220px;">
                    <label for="dmi-oth-filter-search">Origin / Destination</label>
                    <input type="text" id="dmi-oth-filter-search" placeholder="Contains...">
                </div>
                <div class="form-group" style="margin-bottom: 0; min-width: 180px;">
                    <label for="dmi-oth-filter-status">Status</label>
                    <select id="dmi-oth-filter-status">
                        <option value="ALL">All</option>
                        <option value="VALID">Valid</option>
                        <option value="WARNING">Warning</option>
                        <option value="UNMATCHED">Unmatched</option>
                        <option value="AMBIGUOUS">Ambiguous</option>
                        <option value="INVALID">Invalid</option>
                    </select>
                </div>
                <div style="display: flex; gap: 0.5rem;">
                    <button type="button" id="dmi-oth-filter-btn" class="btn">Filter</button>
                    <button type="button" id="dmi-oth-reset-btn" class="btn btn-secondary">Reset</button>
                </div>
            </div>
        </div>
        <div class="table-wrapper">
            <table id="dmi-oth-table" class="display" style="width: 100%;">
                <thead><tr><th>Origin</th><th>Destination</th><th>Minimum Downtime</th><th>Maximum Downtime</th><th>Status</th><th>Message</th></tr></thead>
                <tbody></tbody>
            </table>
        </div>
    </div>

    <script>
        (function () {
            var endpoint = '{{ route('downtime-matrix-import.rows-data', $downtime_matrix_import) }}';
            var statusColors = { VALID: '#28a745', WARNING: '#ffc107', UNMATCHED: '#fd7e14', AMBIGUOUS: '#6f42c1', INVALID: '#dc3545' };
            var tabControllers = {};

            function renderStatus(data, type, row) {
                var color = statusColors[row.resolution_status] || '#6c757d';
                return '<span style="background: ' + color + '; color: white; padding: 0.25rem 0.5rem; border-radius: 3px; font-size: 12px;">' + row.resolution_status + '</span>';
            }

            function renderMessage(data, type, row) {
                return '<span style="font-size: 13px; color: #666;">' + escapeHtml(row.validation_message || '') + '</span>';
            }

            function renderDowntime(d) {
                return d !== null ? d : '-';
            }

            /**
             * One Data Table per Preview tab, all backed by the same
             * rows-data endpoint (filtered server-side by rule_type). Each
             * is only ever initialized by its own Filter button (or by
             * "Show All" calling the same code path for a tab that hasn't
             * loaded yet) - opening the page, or switching tabs on their
             * own, never loads anything. cfg.readFilters()/clearFilters()
             * own each tab's own filter fields (Origin+Destination for
             * Farm-to-Farm/Others, one Designated Farm dropdown for
             * Stationary) - the request-param shape they produce is read by
             * DowntimeMatrixImportController::rowsData().
             */
            function setupTab(cfg) {
                var dtInstance = null;
                var appliedFilters = {};

                function initTable() {
                    dtInstance = jQuery(cfg.table).DataTable({
                        serverSide: true,
                        processing: true,
                        searching: false,
                        lengthMenu: [10, 25, 50, 100],
                        pageLength: 25,
                        order: [[cfg.defaultOrderColumn, 'asc']],
                        ajax: {
                            url: endpoint,
                            data: function (d) {
                                d.rule_type = cfg.ruleType;
                                jQuery.extend(d, appliedFilters);
                            }
                        },
                        columns: cfg.columns
                    });
                }

                function destroyTable() {
                    if (dtInstance) {
                        dtInstance.destroy();
                        dtInstance = null;
                    }
                    jQuery(cfg.table + ' tbody').empty();
                }

                function applyFilters() {
                    appliedFilters = cfg.readFilters();

                    if (dtInstance) {
                        dtInstance.ajax.reload();
                    } else {
                        initTable();
                    }
                }

                jQuery(cfg.filterBtn).on('click', applyFilters);

                jQuery(cfg.resetBtn).on('click', function () {
                    cfg.clearFilters();
                    appliedFilters = {};
                    destroyTable();
                });

                tabControllers[cfg.ruleType] = {
                    ensureLoaded: function () {
                        if (!dtInstance) {
                            applyFilters();
                        }
                    }
                };
            }

            setupTab({
                table: '#dmi-ftf-table',
                filterBtn: '#dmi-ftf-filter-btn',
                resetBtn: '#dmi-ftf-reset-btn',
                ruleType: 'FARM_TO_FARM',
                defaultOrderColumn: 2,
                readFilters: function () {
                    return {
                        origin_raw_label: jQuery('#dmi-ftf-filter-origin').val(),
                        destination_raw_label: jQuery('#dmi-ftf-filter-destination').val(),
                        status: jQuery('#dmi-ftf-filter-status').val()
                    };
                },
                clearFilters: function () {
                    jQuery('#dmi-ftf-filter-origin').val('ALL');
                    jQuery('#dmi-ftf-filter-destination').val('ALL');
                    jQuery('#dmi-ftf-filter-status').val('ALL');
                },
                columns: [
                    { data: 'origin_display', orderable: false, render: function (d) { return escapeHtml(d); } },
                    { data: 'destination_display', orderable: false, render: function (d) { return escapeHtml(d); } },
                    { data: 'minimum_downtime', render: renderDowntime },
                    { data: 'maximum_downtime', render: renderDowntime },
                    { data: null, render: renderStatus },
                    { data: 'validation_message', orderable: false, render: renderMessage }
                ]
            });

            setupTab({
                table: '#dmi-stn-table',
                filterBtn: '#dmi-stn-filter-btn',
                resetBtn: '#dmi-stn-reset-btn',
                ruleType: 'STATIONARY',
                defaultOrderColumn: 1,
                readFilters: function () {
                    return {
                        destination_raw_label: jQuery('#dmi-stn-filter-destination').val(),
                        status: jQuery('#dmi-stn-filter-status').val()
                    };
                },
                clearFilters: function () {
                    jQuery('#dmi-stn-filter-destination').val('ALL');
                    jQuery('#dmi-stn-filter-status').val('ALL');
                },
                columns: [
                    { data: 'destination_display', orderable: false, render: function (d) { return escapeHtml(d); } },
                    { data: 'minimum_downtime', render: renderDowntime },
                    { data: 'maximum_downtime', render: renderDowntime },
                    { data: null, render: renderStatus },
                    { data: 'validation_message', orderable: false, render: renderMessage }
                ]
            });

            setupTab({
                table: '#dmi-oth-table',
                filterBtn: '#dmi-oth-filter-btn',
                resetBtn: '#dmi-oth-reset-btn',
                ruleType: 'OTHERS',
                defaultOrderColumn: 2,
                readFilters: function () {
                    return {
                        label_search: jQuery('#dmi-oth-filter-search').val(),
                        status: jQuery('#dmi-oth-filter-status').val()
                    };
                },
                clearFilters: function () {
                    jQuery('#dmi-oth-filter-search').val('');
                    jQuery('#dmi-oth-filter-status').val('ALL');
                },
                columns: [
                    { data: 'origin_display', orderable: false, render: function (d) { return escapeHtml(d); } },
                    { data: 'destination_display', orderable: false, render: function (d) { return escapeHtml(d); } },
                    { data: 'minimum_downtime', render: renderDowntime },
                    { data: 'maximum_downtime', render: renderDowntime },
                    { data: null, render: renderStatus },
                    { data: 'validation_message', orderable: false, render: renderMessage }
                ]
            });

            window.dmiShowTab = function (tab) {
                document.getElementById('dmi-tab-hint').style.display = 'none';
                document.getElementById('dmi-tab-farm-to-farm').style.display = (tab === 'farm-to-farm' || tab === 'all') ? 'block' : 'none';
                document.getElementById('dmi-tab-stationary').style.display = (tab === 'stationary' || tab === 'all') ? 'block' : 'none';
                document.getElementById('dmi-tab-others').style.display = (tab === 'others' || tab === 'all') ? 'block' : 'none';
                document.querySelectorAll('.dmi-tab-btn').forEach(function (btn) {
                    btn.classList.toggle('btn-secondary', btn.getAttribute('data-dmi-tab') !== tab);
                });

                if (tab === 'all') {
                    tabControllers.FARM_TO_FARM.ensureLoaded();
                    tabControllers.STATIONARY.ensureLoaded();
                    tabControllers.OTHERS.ensureLoaded();
                }
            };
        })();
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
