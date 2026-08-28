<div class="content-header">
    <h2 class="content-title">Facility Aliases</h2>
    <a href="{{ route('facility-aliases.create') }}" class="btn ajax-link">+ Add</a>
</div>

<div class="table-wrapper" style="padding: 1rem 1.5rem;">
    <div style="display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap;">
        <div class="form-group" style="margin-bottom: 0; min-width: 220px;">
            <label for="fa-filter-alias-text">Alias Text</label>
            <input type="text" id="fa-filter-alias-text" placeholder="Contains...">
        </div>
        <div class="form-group" style="margin-bottom: 0; min-width: 220px;">
            <label for="fa-filter-facility">Facility</label>
            <select id="fa-filter-facility">
                <option value="ALL">All Facilities</option>
                @foreach($facilities as $facility)
                    <option value="{{ $facility->facility_id }}">{{ $facility->facility_name }}</option>
                @endforeach
            </select>
        </div>
        <div style="display: flex; gap: 0.5rem;">
            <button type="button" id="fa-filter-btn" class="btn">Filter</button>
            <button type="button" id="fa-reset-btn" class="btn btn-secondary">Reset</button>
        </div>
    </div>
</div>

<div class="table-wrapper">
    <table id="fa-table" class="display" style="width: 100%;">
        <thead>
            <tr>
                <th>Alias Text</th>
                <th>Facility</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
</div>

<script>
    (function () {
        var csrfToken = '{{ csrf_token() }}';
        var basePath = '{{ route('facility-aliases.index') }}';
        var endpoint = '{{ route('facility-aliases.data') }}';
        var dtInstance = null;
        var appliedFilters = {};

        function renderActions(data, type, row) {
            return '<a href="' + basePath + '/' + row.alias_id + '/edit" class="btn ajax-link" style="background: #17a2b8; font-size: 12px;">Edit</a>'
                + '<form method="POST" action="' + basePath + '/' + row.alias_id + '" class="ajax-form" style="display: inline;" onsubmit="return confirm(\'Sure?\');">'
                +   '<input type="hidden" name="_token" value="' + csrfToken + '">'
                +   '<input type="hidden" name="_method" value="DELETE">'
                +   '<button type="submit" class="btn btn-danger" style="font-size: 12px;">Delete</button>'
                + '</form>';
        }

        function initTable() {
            dtInstance = jQuery('#fa-table').DataTable({
                serverSide: true,
                processing: true,
                searching: false,
                lengthMenu: [10, 25, 50, 100],
                pageLength: 25,
                order: [[0, 'asc']],
                ajax: {
                    url: endpoint,
                    data: function (d) {
                        d.alias_text = appliedFilters.alias_text || '';
                        d.facility_id = appliedFilters.facility_id || 'ALL';
                    }
                },
                columns: [
                    { data: 'alias_text' },
                    { data: 'facility_name' },
                    { data: null, orderable: false, render: renderActions }
                ]
            });
        }

        function destroyTable() {
            if (dtInstance) {
                dtInstance.destroy();
                dtInstance = null;
            }
            jQuery('#fa-table tbody').empty();
        }

        // Only a Filter click ever triggers a /data request - not page load,
        // not typing into a filter, not opening the Facility dropdown.
        jQuery('#fa-filter-btn').on('click', function () {
            appliedFilters = {
                alias_text: jQuery('#fa-filter-alias-text').val(),
                facility_id: jQuery('#fa-filter-facility').val()
            };

            if (dtInstance) {
                dtInstance.ajax.reload();
            } else {
                initTable();
            }
        });

        jQuery('#fa-reset-btn').on('click', function () {
            jQuery('#fa-filter-alias-text').val('');
            jQuery('#fa-filter-facility').val('ALL');
            appliedFilters = {};
            destroyTable();
        });
    })();
</script>
