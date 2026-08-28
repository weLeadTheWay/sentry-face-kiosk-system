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
                'PRODUCED' => '#6f42c1',
                default => '#ffc107',
            };
        @endphp
        <span style="background: {{ $statusColor }}; color: white; padding: 0.25rem 0.5rem; border-radius: 3px; font-size: 12px;">{{ $downtime_matrix_import->status }}</span>
    </p>
    <p><strong>Uploaded By:</strong> {{ $downtime_matrix_import->uploadedBy->user_name ?? '-' }} on {{ $downtime_matrix_import->created_at?->format('n/d/Y H:i') }}</p>
    {{-- Checked by whether each timestamp is set, not by the current status -
         once a VERIFIED import is later PRODUCED, its Verified By history
         must still be visible, not hidden just because the status moved on. --}}
    @if($downtime_matrix_import->verified_at)
        <p><strong>Verified By:</strong> {{ $downtime_matrix_import->verifiedBy->user_name ?? '-' }} on {{ $downtime_matrix_import->verified_at?->format('n/d/Y H:i') }}</p>
    @endif
    @if($downtime_matrix_import->isCancelled())
        <p><strong>Cancelled By:</strong> {{ $downtime_matrix_import->cancelledBy->user_name ?? '-' }} on {{ $downtime_matrix_import->cancelled_at?->format('n/d/Y H:i') }}</p>
    @endif
    @if($downtime_matrix_import->produced_at)
        <p><strong>Produced By:</strong> {{ $downtime_matrix_import->producedBy->user_name ?? '-' }} on {{ $downtime_matrix_import->produced_at?->format('n/d/Y H:i') }}</p>
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
                <thead><tr><th>Origin Farm</th><th>Destination Farm</th><th>Minimum Downtime</th><th>Maximum Downtime</th><th>Status</th><th>Message</th><th>Actions</th></tr></thead>
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
                <thead><tr><th>Designated Farm</th><th>Minimum Downtime</th><th>Maximum Downtime</th><th>Status</th><th>Message</th><th>Actions</th></tr></thead>
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
                <thead><tr><th>Origin</th><th>Destination</th><th>Minimum Downtime</th><th>Maximum Downtime</th><th>Status</th><th>Message</th><th>Actions</th></tr></thead>
                <tbody></tbody>
            </table>
        </div>
    </div>

    {{-- Per-row Edit modal, shared by all three tabs. Origin/Destination are
         populated from $facilities (every active facility - an admin
         correcting an UNMATCHED/WARNING row may need to pick a facility this
         import's own raw labels never matched, not just the ones already
         referenced in this import). The Origin field is hidden for
         STATIONARY rows, since a Stationary row's origin is always the
         recognized "Outside" sentinel by construction, not a resolvable
         facility - saving one never sends an origin_facility_id. --}}
    <div id="dmi-edit-modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
        <div class="table-wrapper" style="padding: 1.5rem; max-width: 480px; width: 90%;">
            <h3 style="margin-top: 0;">Edit Row</h3>
            <p id="dmi-edit-modal-labels" style="color: #666; font-size: 13px;"></p>
            <form id="dmi-edit-form">
                <div class="form-group" id="dmi-edit-origin-group">
                    <label for="dmi-edit-origin">Origin Facility</label>
                    <select id="dmi-edit-origin">
                        <option value="">-- Unresolved --</option>
                        @foreach($facilities as $facility)
                            <option value="{{ $facility->facility_id }}">{{ $facility->facility_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label for="dmi-edit-destination">Destination Facility</label>
                    <select id="dmi-edit-destination">
                        <option value="">-- Unresolved --</option>
                        @foreach($facilities as $facility)
                            <option value="{{ $facility->facility_id }}">{{ $facility->facility_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label for="dmi-edit-minimum">Minimum Downtime</label>
                    <input type="number" step="0.01" min="0" id="dmi-edit-minimum">
                </div>
                <div class="form-group">
                    <label for="dmi-edit-maximum">Maximum Downtime</label>
                    <input type="number" step="0.01" min="0" id="dmi-edit-maximum">
                </div>
                <p id="dmi-edit-error" style="color: #dc3545; font-size: 13px; display: none;"></p>
                <div class="form-actions">
                    <button type="submit" class="btn">Save</button>
                    <button type="button" class="btn btn-secondary" id="dmi-edit-cancel-btn">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        (function () {
            var endpoint = '{{ route('downtime-matrix-import.rows-data', $downtime_matrix_import) }}';
            var updateEndpoint = '{{ route('downtime-matrix-import.rows.update', $downtime_matrix_import) }}';
            var canEdit = {{ $downtime_matrix_import->isPendingVerification() ? 'true' : 'false' }};
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

            function renderActions(data, type, row) {
                if (!canEdit) {
                    return '';
                }
                return '<button type="button" class="btn btn-secondary dmi-edit-btn" data-row="' + escapeHtml(JSON.stringify(row)) + '">Edit</button>';
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
                        processing: false,
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
                    },
                    reload: function () {
                        if (dtInstance) {
                            // false = keep the current page/paging position -
                            // an edit shouldn't bounce the admin back to page 1.
                            dtInstance.ajax.reload(null, false);
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
                    { data: 'validation_message', orderable: false, render: renderMessage },
                    { data: null, orderable: false, render: renderActions }
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
                    { data: 'validation_message', orderable: false, render: renderMessage },
                    { data: null, orderable: false, render: renderActions }
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
                    { data: 'validation_message', orderable: false, render: renderMessage },
                    { data: null, orderable: false, render: renderActions }
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

            /**
             * Per-row Edit modal. Opened from any of the three tabs' Edit
             * button (renderActions embeds the full row payload - including
             * the raw origin_facility_id/destination_facility_id/rule_type
             * fields DowntimeMatrixImportController::rowPayload() now
             * returns specifically for this - as a data-row attribute, so
             * no extra request is needed to populate the form). Saving PUTs
             * a single-row payload to the same rows.update endpoint the old
             * page-based bulk editor used, then reloads only the tab the
             * edited row belongs to, on its current page, so the change is
             * visibly applied without losing the admin's place.
             */
            var editModal = jQuery('#dmi-edit-modal');
            var editForm = jQuery('#dmi-edit-form');
            var editError = jQuery('#dmi-edit-error');
            var currentEditRowId = null;
            var currentEditRuleType = null;

            function openEditModal(row) {
                currentEditRowId = row.import_row_id;
                currentEditRuleType = row.rule_type;

                var labels = 'Destination: ' + (row.destination_raw_label || '-');
                if (row.rule_type !== 'STATIONARY') {
                    labels = 'Origin: ' + (row.origin_raw_label || '-') + ' | ' + labels;
                }
                jQuery('#dmi-edit-modal-labels').text(labels);

                jQuery('#dmi-edit-origin').val(row.origin_facility_id || '');
                jQuery('#dmi-edit-destination').val(row.destination_facility_id || '');
                jQuery('#dmi-edit-minimum').val(row.minimum_downtime !== null && row.minimum_downtime !== undefined ? row.minimum_downtime : '');
                jQuery('#dmi-edit-maximum').val(row.maximum_downtime !== null && row.maximum_downtime !== undefined ? row.maximum_downtime : '');
                editError.hide().text('');

                // STATIONARY rows have no real origin to pick - "Outside" is
                // the recognized sentinel origin by construction, not a
                // resolvable facility (see RuleClassifier) - so the field is
                // hidden rather than shown-but-meaningless.
                jQuery('#dmi-edit-origin-group').toggle(row.rule_type !== 'STATIONARY');

                editModal.css('display', 'flex');
            }

            function closeEditModal() {
                editModal.css('display', 'none');
                currentEditRowId = null;
                currentEditRuleType = null;
            }

            jQuery(document).on('click', '.dmi-edit-btn', function () {
                openEditModal(JSON.parse(jQuery(this).attr('data-row')));
            });

            jQuery('#dmi-edit-cancel-btn').on('click', closeEditModal);

            editForm.on('submit', function (e) {
                e.preventDefault();

                if (currentEditRowId === null) {
                    return;
                }

                var minimumVal = jQuery('#dmi-edit-minimum').val();
                var maximumVal = jQuery('#dmi-edit-maximum').val();
                var rowPayload = {
                    origin_facility_id: currentEditRuleType === 'STATIONARY' ? null : (jQuery('#dmi-edit-origin').val() || null),
                    destination_facility_id: jQuery('#dmi-edit-destination').val() || null,
                    minimum_downtime: minimumVal !== '' ? minimumVal : null,
                    maximum_downtime: maximumVal !== '' ? maximumVal : null
                };
                var payload = { rows: {} };
                payload.rows[currentEditRowId] = rowPayload;

                var reloadRuleType = currentEditRuleType;

                jQuery.ajax({
                    url: updateEndpoint,
                    method: 'PUT',
                    data: payload,
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    }
                }).done(function () {
                    closeEditModal();
                    if (tabControllers[reloadRuleType]) {
                        tabControllers[reloadRuleType].reload();
                    }
                }).fail(function (xhr) {
                    var message = 'Unable to save changes.';
                    if (xhr.responseJSON && xhr.responseJSON.errors) {
                        message = Object.values(xhr.responseJSON.errors).flat().join(' ');
                    } else if (xhr.responseJSON && xhr.responseJSON.message) {
                        message = xhr.responseJSON.message;
                    }
                    editError.text(message).show();
                });
            });
        })();
    </script>
@endif

@if($productionResult ?? null)
    {{-- The result of an actual Save to Production attempt (success or
         failure) - shown once, on the same response that performed it. A
         successful mapping's counts come straight back from
         DowntimeMatrixImportService::produce(); "records created" can
         differ from "rows processed" since a facility-group row (e.g.
         "LEP, DC") expands into more than one downtime_matrix row. A
         failed attempt was fully rolled back by produce()'s own DB
         transaction - the import is still VERIFIED, not PRODUCED, and
         nothing in downtime_matrix/downtime_stationary changed. --}}
    <div class="table-wrapper" style="padding: 1.5rem; margin-top: 1.5rem; border-left: 4px solid {{ ($productionResult['success'] ?? false) ? '#28a745' : '#dc3545' }};">
        @if($productionResult['success'] ?? false)
            <p style="margin-top: 0;"><strong>Production mapping completed.</strong></p>
            <table style="margin-bottom: 0;">
                <tbody>
                    <tr><td><strong>Staging Rows Processed</strong></td><td>{{ $productionResult['staging_rows_processed'] }}</td></tr>
                    <tr><td><strong>Production Records Created</strong></td><td>{{ $productionResult['production_records_created'] }}</td></tr>
                    <tr><td colspan="2" style="padding-top: 0.75rem;"><strong>Mapped</strong></td></tr>
                    <tr><td>&nbsp;&nbsp;VALID</td><td>{{ $productionResult['mapped']['VALID'] }}</td></tr>
                    <tr><td>&nbsp;&nbsp;WARNING</td><td>{{ $productionResult['mapped']['WARNING'] }}</td></tr>
                    <tr><td colspan="2" style="padding-top: 0.75rem;"><strong>Skipped</strong></td></tr>
                    <tr><td>&nbsp;&nbsp;UNMATCHED</td><td>{{ $productionResult['skipped']['UNMATCHED'] }}</td></tr>
                    <tr><td>&nbsp;&nbsp;AMBIGUOUS</td><td>{{ $productionResult['skipped']['AMBIGUOUS'] }}</td></tr>
                    <tr><td>&nbsp;&nbsp;INVALID</td><td>{{ $productionResult['skipped']['INVALID'] }}</td></tr>
                </tbody>
            </table>
            @if(!empty($productionResult['reverted_imports']))
                {{-- Only one import can be PRODUCED at a time - this one just
                     took over as the live production source, so whichever
                     import(s) held that status before have been reverted
                     back to VERIFIED (their state immediately before being
                     produced). --}}
                <p style="color: #666; font-size: 13px; margin-top: 1rem; margin-bottom: 0;">Note: the following previously-produced import(s) reverted to Verified, since this import is now the active production source: {{ implode(', ', $productionResult['reverted_imports']) }}.</p>
            @endif
        @else
            <p style="margin-top: 0;"><strong>Production mapping failed.</strong></p>
            <p style="color: #666; font-size: 13px;">{{ $productionResult['error'] }} No changes were made - the previous production configuration remains active, and this import is still VERIFIED.</p>
        @endif
    </div>
@elseif($downtime_matrix_import->isPendingVerification())
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
@elseif($productionMode ?? false)
    {{-- The confirmation step reached from the import list's "Production"
         action (visible only for VERIFIED rows). This page IS the
         confirmation - no extra JS confirm() popup - so the admin can
         review the same staged rows/tabs above one more time before
         choosing. Save to Production maps every eligible (VALID/WARNING)
         staging row into downtime_matrix/downtime_stationary and marks the
         import PRODUCED - see DowntimeMatrixImportService::produce() for
         the mapping rules. Cancel here is a plain link back to the
         read-only Preview, not a state change - it must never be confused
         with the PENDING_VERIFICATION "Cancel" above, which actually
         cancels the import. --}}
    @php
        // Read from the import's own denormalized counters, not $totalRow -
        // that variable is only computed inside the hasParseError() ==
        // false branch above, and a VERIFIED-but-failed-to-parse import
        // (zero rows, still technically reachable here) would leave it
        // undefined.
        $mappedCount = $downtime_matrix_import->valid_rows_count + $downtime_matrix_import->warning_rows_count;
        $skippedCount = $downtime_matrix_import->unmatched_rows_count + $downtime_matrix_import->ambiguous_rows_count + $downtime_matrix_import->invalid_rows_count;
    @endphp
    <div class="table-wrapper" style="padding: 1.5rem; margin-top: 1.5rem; border-left: 4px solid #6f42c1;">
        <p style="margin-top: 0;"><strong>Confirm Save to Production</strong></p>
        <table style="margin-bottom: 1rem;">
            <tbody>
                <tr><td><strong>File</strong></td><td>{{ $downtime_matrix_import->original_filename }}</td></tr>
                <tr><td><strong>Import Date</strong></td><td>{{ $downtime_matrix_import->created_at?->format('n/d/Y H:i') }}</td></tr>
                <tr><td><strong>Total Rows Parsed</strong></td><td>{{ $downtime_matrix_import->total_rows_parsed }}</td></tr>
                <tr><td><strong>Valid</strong></td><td>{{ $downtime_matrix_import->valid_rows_count }}</td></tr>
                <tr><td><strong>Warning</strong></td><td>{{ $downtime_matrix_import->warning_rows_count }}</td></tr>
                <tr><td><strong>Unmatched</strong></td><td>{{ $downtime_matrix_import->unmatched_rows_count }}</td></tr>
                <tr><td><strong>Ambiguous</strong></td><td>{{ $downtime_matrix_import->ambiguous_rows_count }}</td></tr>
                <tr><td><strong>Invalid</strong></td><td>{{ $downtime_matrix_import->invalid_rows_count }}</td></tr>
                <tr style="font-weight: bold;"><td>Rows to be Mapped</td><td>{{ $mappedCount }}</td></tr>
                <tr style="font-weight: bold;"><td>Rows to be Skipped</td><td>{{ $skippedCount }}</td></tr>
            </tbody>
        </table>
        <p style="color: #666; font-size: 13px;">Review the staged rows above, then choose Save to Production to map the Valid/Warning rows into the live Downtime Matrix / Downtime Stationary configuration, or Cancel to go back without making any change. Existing active production rules will be deactivated (not deleted, kept as history) and replaced by this import's mapped rules.</p>
        <div class="form-actions">
            <form method="POST" action="{{ route('downtime-matrix-import.produce', $downtime_matrix_import) }}" class="ajax-form" style="display: inline;">
                @csrf
                <button type="submit" class="btn">Save to Production</button>
            </form>
            <a href="{{ route('downtime-matrix-import.show', $downtime_matrix_import) }}" class="btn btn-secondary ajax-link">Cancel</a>
        </div>
    </div>
@elseif($downtime_matrix_import->isVerified())
    {{-- Reached right after clicking Verify / Continue above (or by
         revisiting a VERIFIED import's Preview directly) - previously the
         only way to reach Save to Production from here was to navigate back
         to the import list and click its "Production" action there. This
         is the same action, just reachable without leaving the page - it
         still only links to the confirmation step (produce.confirm), never
         straight to produce() itself, so Save to Production still always
         requires that explicit confirmation. --}}
    <div class="form-actions" style="margin-top: 1.5rem;">
        <a href="{{ route('downtime-matrix-import.produce.confirm', $downtime_matrix_import) }}" class="btn ajax-link" style="background: #6f42c1;">Production</a>
    </div>
@endif
