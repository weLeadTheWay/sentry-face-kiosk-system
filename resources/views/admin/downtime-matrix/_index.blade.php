<p><a href="{{ route('biosecurity-rules.index') }}" class="ajax-link">&larr; Biosecurity Rules</a></p>
<div class="content-header"><h2 class="content-title">Downtime Matrix</h2><a href="{{ route('downtime-matrix.create') }}" class="btn ajax-link">+ Add</a></div>

<div class="table-wrapper" style="padding: 1rem 1.5rem;">
    <div style="display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap;">
        <div class="form-group" style="margin-bottom: 0; min-width: 200px;">
            <label for="dm-filter-origin">Origin Facility</label>
            <select id="dm-filter-origin">
                <option value="ALL">All Facilities</option>
                @foreach($facilities as $facility)
                    <option value="{{ $facility->facility_id }}">{{ $facility->facility_name }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-group" style="margin-bottom: 0; min-width: 200px;">
            <label for="dm-filter-destination">Destination Facility</label>
            <select id="dm-filter-destination">
                <option value="ALL">All Facilities</option>
                @foreach($facilities as $facility)
                    <option value="{{ $facility->facility_id }}">{{ $facility->facility_name }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-group" style="margin-bottom: 0; min-width: 150px;">
            <label for="dm-filter-status">Status</label>
            <select id="dm-filter-status">
                <option value="ALL">All</option>
                <option value="ACTIVE">Active</option>
                <option value="INACTIVE">Inactive</option>
            </select>
        </div>
        <div style="display: flex; gap: 0.5rem;">
            <button type="button" id="dm-filter-btn" class="btn">Filter</button>
            <button type="button" id="dm-reset-btn" class="btn btn-secondary">Reset</button>
        </div>
    </div>
</div>

<div class="table-wrapper">
    <table id="dm-table" class="display" style="width: 100%;">
        <thead>
            <tr>
                <th>Origin</th>
                <th>Destination</th>
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
        var basePath = '{{ route('downtime-matrix.index') }}';
        var endpoint = '{{ route('downtime-matrix.data') }}';
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
            dtInstance = jQuery('#dm-table').DataTable({
                serverSide: true,
                processing: true,
                searching: false,
                lengthMenu: [10, 25, 50, 100],
                pageLength: 25,
                order: [[2, 'asc']],
                ajax: {
                    url: endpoint,
                    data: function (d) {
                        d.origin_facility_id = appliedFilters.origin_facility_id || 'ALL';
                        d.destination_facility_id = appliedFilters.destination_facility_id || 'ALL';
                        d.status = appliedFilters.status || 'ALL';
                    }
                },
                columns: [
                    { data: 'origin_facility', orderable: false, render: function (d) { return d ? escapeHtml(d) : '-'; } },
                    { data: 'destination_facility', orderable: false, render: function (d) { return d ? escapeHtml(d) : '-'; } },
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
            jQuery('#dm-table tbody').empty();
        }

        jQuery('#dm-filter-btn').on('click', function () {
            appliedFilters = {
                origin_facility_id: jQuery('#dm-filter-origin').val(),
                destination_facility_id: jQuery('#dm-filter-destination').val(),
                status: jQuery('#dm-filter-status').val()
            };

            if (dtInstance) {
                dtInstance.ajax.reload();
            } else {
                initTable();
            }
        });

        jQuery('#dm-reset-btn').on('click', function () {
            jQuery('#dm-filter-origin').val('ALL');
            jQuery('#dm-filter-destination').val('ALL');
            jQuery('#dm-filter-status').val('ALL');
            appliedFilters = {};
            destroyTable();
        });
    })();
</script>
