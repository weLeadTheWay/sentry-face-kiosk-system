<p><a href="{{ route('biosecurity-rules.index') }}" class="ajax-link">&larr; Biosecurity Rules</a></p>
<div class="content-header"><h2 class="content-title">Downtime Matrix Import</h2><a href="{{ route('downtime-matrix-import.create') }}" class="btn ajax-link">+ Import</a></div>

<div class="table-wrapper" style="padding: 1rem 1.5rem;">
    <div style="display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap;">
        <div class="form-group" style="margin-bottom: 0; min-width: 180px;">
            <label for="dmi-filter-status">Status</label>
            <select id="dmi-filter-status">
                <option value="ALL">All</option>
                <option value="PENDING_VERIFICATION">Pending Verification</option>
                <option value="VERIFIED">Verified</option>
                <option value="CANCELLED">Cancelled</option>
            </select>
        </div>
        <div class="form-group" style="margin-bottom: 0; min-width: 180px;">
            <label for="dmi-filter-matrix-type">Matrix Type</label>
            <select id="dmi-filter-matrix-type">
                <option value="ALL">All</option>
                <option value="BFI_BVA">BFI/BVA</option>
                <option value="HOGS">Hogs</option>
            </select>
        </div>
        <div style="display: flex; gap: 0.5rem;">
            <button type="button" id="dmi-filter-btn" class="btn">Filter</button>
            <button type="button" id="dmi-reset-btn" class="btn btn-secondary">Reset</button>
        </div>
    </div>
</div>

<div class="table-wrapper">
    <table id="dmi-table" class="display" style="width: 100%;">
        <thead>
            <tr>
                <th>File</th>
                <th>Matrix Type</th>
                <th>Uploaded By</th>
                <th>Uploaded At</th>
                <th>Status</th>
                <th>Valid</th>
                <th>Warning</th>
                <th>Unmatched</th>
                <th>Ambiguous</th>
                <th>Invalid</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
</div>

<script>
    (function () {
        var basePath = '{{ route('downtime-matrix-import.index') }}';
        var endpoint = '{{ route('downtime-matrix-import.data') }}';
        var dtInstance = null;
        var appliedFilters = {};
        var statusColors = { VERIFIED: '#28a745', CANCELLED: '#6c757d' };

        function renderStatus(data, type, row) {
            var color = statusColors[row.status] || '#ffc107';
            return '<span style="background: ' + color + '; color: white; padding: 0.25rem 0.5rem; border-radius: 3px; font-size: 12px;">' + escapeHtml(row.status) + '</span>';
        }

        function renderActions(data, type, row) {
            return '<a href="' + basePath + '/' + row.import_id + '" class="btn ajax-link" style="background: #17a2b8; font-size: 12px;">View</a>';
        }

        function initTable() {
            dtInstance = jQuery('#dmi-table').DataTable({
                serverSide: true,
                processing: true,
                searching: false,
                lengthMenu: [10, 25, 50, 100],
                pageLength: 25,
                order: [[3, 'desc']],
                ajax: {
                    url: endpoint,
                    data: function (d) {
                        d.status = appliedFilters.status || 'ALL';
                        d.matrix_type = appliedFilters.matrix_type || 'ALL';
                    }
                },
                columns: [
                    { data: 'original_filename' },
                    { data: 'matrix_type', orderable: false },
                    { data: 'uploaded_by', orderable: false, render: function (d) { return d ? escapeHtml(d) : '-'; } },
                    { data: 'created_at' },
                    { data: null, orderable: false, render: renderStatus },
                    { data: 'valid_rows_count', orderable: false },
                    { data: 'warning_rows_count', orderable: false },
                    { data: 'unmatched_rows_count', orderable: false },
                    { data: 'ambiguous_rows_count', orderable: false },
                    { data: 'invalid_rows_count', orderable: false },
                    { data: null, orderable: false, render: renderActions }
                ]
            });
        }

        function destroyTable() {
            if (dtInstance) {
                dtInstance.destroy();
                dtInstance = null;
            }
            jQuery('#dmi-table tbody').empty();
        }

        jQuery('#dmi-filter-btn').on('click', function () {
            appliedFilters = {
                status: jQuery('#dmi-filter-status').val(),
                matrix_type: jQuery('#dmi-filter-matrix-type').val()
            };

            if (dtInstance) {
                dtInstance.ajax.reload();
            } else {
                initTable();
            }
        });

        jQuery('#dmi-reset-btn').on('click', function () {
            jQuery('#dmi-filter-status').val('ALL');
            jQuery('#dmi-filter-matrix-type').val('ALL');
            appliedFilters = {};
            destroyTable();
        });
    })();
</script>
