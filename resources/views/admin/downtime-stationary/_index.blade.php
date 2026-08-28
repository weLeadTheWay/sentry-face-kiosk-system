<p><a href="{{ route('biosecurity-rules.index') }}" class="ajax-link">&larr; Biosecurity Rules</a></p>
<div class="content-header"><h2 class="content-title">Downtime Stationary</h2><a href="{{ route('downtime-stationary.create') }}" class="btn ajax-link">+ Add</a></div>

<div class="table-wrapper" style="padding: 1rem 1.5rem;">
    <div style="display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap;">
        <div class="form-group" style="margin-bottom: 0; min-width: 220px;">
            <label for="ds-filter-facility">Assigned Facility</label>
            <select id="ds-filter-facility">
                <option value="ALL">All Facilities</option>
                @foreach($facilities as $facility)
                    <option value="{{ $facility->facility_id }}">{{ $facility->facility_name }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-group" style="margin-bottom: 0; min-width: 150px;">
            <label for="ds-filter-status">Status</label>
            <select id="ds-filter-status">
                <option value="ALL">All</option>
                <option value="ACTIVE">Active</option>
                <option value="INACTIVE">Inactive</option>
            </select>
        </div>
        <div style="display: flex; gap: 0.5rem;">
            <button type="button" id="ds-filter-btn" class="btn">Filter</button>
            <button type="button" id="ds-reset-btn" class="btn btn-secondary">Reset</button>
        </div>
    </div>
</div>

<div class="table-wrapper">
    <table id="ds-table" class="display" style="width: 100%;">
        <thead>
            <tr>
                <th>Assigned Facility</th>
                <th>Min Down (hrs)</th>
                <th>Max Down (hrs)</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
</div>

<script>
    (function () {
        var csrfToken = '{{ csrf_token() }}';
        var basePath = '{{ route('downtime-stationary.index') }}';
        var endpoint = '{{ route('downtime-stationary.data') }}';
        var dtInstance = null;
        var appliedFilters = {};

        function renderActions(data, type, row) {
            return '<a href="' + basePath + '/' + row.rule_id + '/edit" class="btn ajax-link" style="background: #17a2b8; font-size: 12px;">Edit</a>'
                + '<form method="POST" action="' + basePath + '/' + row.rule_id + '" class="ajax-form" style="display: inline;" onsubmit="return confirm(\'Sure?\');">'
                +   '<input type="hidden" name="_token" value="' + csrfToken + '">'
                +   '<input type="hidden" name="_method" value="DELETE">'
                +   '<button type="submit" class="btn btn-danger" style="font-size: 12px;">Delete</button>'
                + '</form>';
        }

        function renderStatus(data, type, row) {
            var color = row.is_active ? '#28a745' : '#dc3545';
            var label = row.is_active ? 'Active' : 'Inactive';
            return '<span style="background: ' + color + '; color: white; padding: 0.25rem 0.5rem; border-radius: 3px; font-size: 12px;">' + label + '</span>';
        }

        function initTable() {
            dtInstance = jQuery('#ds-table').DataTable({
                serverSide: true,
                processing: true,
                searching: false,
                lengthMenu: [10, 25, 50, 100],
                pageLength: 25,
                order: [[1, 'asc']],
                ajax: {
                    url: endpoint,
                    data: function (d) {
                        d.assigned_facility_id = appliedFilters.assigned_facility_id || 'ALL';
                        d.status = appliedFilters.status || 'ALL';
                    }
                },
                columns: [
                    { data: 'assigned_facility', orderable: false, render: function (d) { return d ? escapeHtml(d) : '-'; } },
                    { data: 'minimum_downtime', render: function (d) { return d !== null ? d : '-'; } },
                    { data: 'maximum_downtime', render: function (d) { return d !== null ? d : '-'; } },
                    { data: null, orderable: false, render: renderStatus },
                    { data: null, orderable: false, render: renderActions }
                ]
            });
        }

        function destroyTable() {
            if (dtInstance) {
                dtInstance.destroy();
                dtInstance = null;
            }
            jQuery('#ds-table tbody').empty();
        }

        jQuery('#ds-filter-btn').on('click', function () {
            appliedFilters = {
                assigned_facility_id: jQuery('#ds-filter-facility').val(),
                status: jQuery('#ds-filter-status').val()
            };

            if (dtInstance) {
                dtInstance.ajax.reload();
            } else {
                initTable();
            }
        });

        jQuery('#ds-reset-btn').on('click', function () {
            jQuery('#ds-filter-facility').val('ALL');
            jQuery('#ds-filter-status').val('ALL');
            appliedFilters = {};
            destroyTable();
        });
    })();
</script>
