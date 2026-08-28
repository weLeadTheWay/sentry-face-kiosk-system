<div class="content-header"><h2 class="content-title">Kiosk Devices</h2><a href="{{ route('kiosks.create') }}" class="btn ajax-link">+ Add Kiosk</a></div>

<div class="table-wrapper" style="padding: 1rem 1.5rem;">
    <div style="display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap;">
        <div class="form-group" style="margin-bottom: 0; min-width: 220px;">
            <label for="kd-filter-search">Device Name / Serial</label>
            <input type="text" id="kd-filter-search" placeholder="Contains...">
        </div>
        <div class="form-group" style="margin-bottom: 0; min-width: 220px;">
            <label for="kd-filter-facility">Facility</label>
            <select id="kd-filter-facility">
                <option value="ALL">All Facilities</option>
                @foreach($facilities as $facility)
                    <option value="{{ $facility->facility_id }}">{{ $facility->facility_name }}</option>
                @endforeach
            </select>
        </div>
        <div style="display: flex; gap: 0.5rem;">
            <button type="button" id="kd-filter-btn" class="btn">Filter</button>
            <button type="button" id="kd-reset-btn" class="btn btn-secondary">Reset</button>
        </div>
    </div>
</div>

<div class="table-wrapper">
    <table id="kd-table" class="display" style="width: 100%;">
        <thead>
            <tr>
                <th>Device Name</th>
                <th>Facility</th>
                <th>Serial</th>
                <th>IP</th>
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
        var basePath = '{{ route('kiosks.index') }}';
        var endpoint = '{{ route('kiosks.data') }}';
        var dtInstance = null;
        var appliedFilters = {};

        function renderActions(data, type, row) {
            return '<a href="' + basePath + '/' + row.kiosk_id + '/edit" class="btn ajax-link" style="background: #17a2b8; font-size: 12px;">Edit</a>'
                + '<form method="POST" action="' + basePath + '/' + row.kiosk_id + '" class="ajax-form" style="display: inline;" onsubmit="return confirm(\'Sure?\');">'
                +   '<input type="hidden" name="_token" value="' + csrfToken + '">'
                +   '<input type="hidden" name="_method" value="DELETE">'
                +   '<button type="submit" class="btn btn-danger" style="font-size: 12px;">Delete</button>'
                + '</form>';
        }

        function initTable() {
            dtInstance = jQuery('#kd-table').DataTable({
                serverSide: true,
                processing: true,
                searching: false,
                lengthMenu: [10, 25, 50, 100],
                pageLength: 25,
                order: [[0, 'asc']],
                ajax: {
                    url: endpoint,
                    data: function (d) {
                        d.search = appliedFilters.search || '';
                        d.facility_id = appliedFilters.facility_id || 'ALL';
                    }
                },
                columns: [
                    { data: 'device_name' },
                    { data: 'facility_name', orderable: false },
                    { data: 'serial_number' },
                    { data: 'public_ip', orderable: false, render: function (d) { return d ? escapeHtml(d) : '-'; } },
                    { data: 'status', orderable: false, render: function (d) { return d ? escapeHtml(d) : '-'; } },
                    { data: null, orderable: false, render: renderActions }
                ]
            });
        }

        function destroyTable() {
            if (dtInstance) {
                dtInstance.destroy();
                dtInstance = null;
            }
            jQuery('#kd-table tbody').empty();
        }

        jQuery('#kd-filter-btn').on('click', function () {
            appliedFilters = {
                search: jQuery('#kd-filter-search').val(),
                facility_id: jQuery('#kd-filter-facility').val()
            };

            if (dtInstance) {
                dtInstance.ajax.reload();
            } else {
                initTable();
            }
        });

        jQuery('#kd-reset-btn').on('click', function () {
            jQuery('#kd-filter-search').val('');
            jQuery('#kd-filter-facility').val('ALL');
            appliedFilters = {};
            destroyTable();
        });
    })();
</script>
