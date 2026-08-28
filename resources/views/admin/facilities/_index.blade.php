<div class="content-header">
    <h2 class="content-title">Facilities</h2>
    <a href="{{ route('facilities.create') }}" class="btn ajax-link">+ Add Facility</a>
</div>

<div class="table-wrapper" style="padding: 1rem 1.5rem;">
    <div style="display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap;">
        <div class="form-group" style="margin-bottom: 0; min-width: 220px;">
            <label for="fl-filter-search">Code / Name</label>
            <input type="text" id="fl-filter-search" placeholder="Contains...">
        </div>
        <div class="form-group" style="margin-bottom: 0; min-width: 180px;">
            <label for="fl-filter-type">Type</label>
            <select id="fl-filter-type">
                <option value="ALL">All Types</option>
                @foreach($facility_types as $type)
                    <option value="{{ $type->facility_type_id }}">{{ $type->facility_type_name }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-group" style="margin-bottom: 0; min-width: 180px;">
            <label for="fl-filter-category">Category</label>
            <select id="fl-filter-category">
                <option value="ALL">All Categories</option>
                @foreach($facility_categories as $category)
                    <option value="{{ $category->facility_category_id }}">{{ $category->facility_category_name }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-group" style="margin-bottom: 0; min-width: 150px;">
            <label for="fl-filter-status">Status</label>
            <select id="fl-filter-status">
                <option value="ALL">All</option>
                <option value="ACTIVE">Active</option>
                <option value="INACTIVE">Inactive</option>
            </select>
        </div>
        <div style="display: flex; gap: 0.5rem;">
            <button type="button" id="fl-filter-btn" class="btn">Filter</button>
            <button type="button" id="fl-reset-btn" class="btn btn-secondary">Reset</button>
        </div>
    </div>
</div>

<div class="table-wrapper">
    <table id="fl-table" class="display" style="width: 100%;">
        <thead>
            <tr>
                <th>Code</th>
                <th>Name</th>
                <th>Type</th>
                <th>Category</th>
                <th>RTL</th>
                <th>Location</th>
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
        var basePath = '{{ route('facilities.index') }}';
        var endpoint = '{{ route('facilities.data') }}';
        var dtInstance = null;
        var appliedFilters = {};

        function renderActions(data, type, row) {
            return '<a href="' + basePath + '/' + row.facility_id + '/edit" class="btn ajax-link" style="background: #17a2b8; font-size: 12px;">Edit</a>'
                + '<form method="POST" action="' + basePath + '/' + row.facility_id + '" class="ajax-form" style="display: inline;" onsubmit="return confirm(\'Are you sure?\');">'
                +   '<input type="hidden" name="_token" value="' + csrfToken + '">'
                +   '<input type="hidden" name="_method" value="DELETE">'
                +   '<button type="submit" class="btn btn-danger" style="font-size: 12px;">Delete</button>'
                + '</form>';
        }

        function renderStatus(data, type, row) {
            var color = row.is_active ? '#28a745' : '#dc3545';
            var label = row.is_active ? 'Active' : 'Inactive';
            return '<span style="background: ' + color + '; color: white; padding: 4px 8px; border-radius: 3px; font-size: 12px;">' + label + '</span>';
        }

        function initTable() {
            dtInstance = jQuery('#fl-table').DataTable({
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
                        d.facility_type_id = appliedFilters.facility_type_id || 'ALL';
                        d.facility_category_id = appliedFilters.facility_category_id || 'ALL';
                        d.status = appliedFilters.status || 'ALL';
                    }
                },
                columns: [
                    { data: 'facility_code' },
                    { data: 'facility_name' },
                    { data: 'facility_type', orderable: false },
                    { data: 'facility_category', orderable: false },
                    { data: 'is_rtl', orderable: false, render: function (d) { return d ? 'Yes' : 'No'; } },
                    { data: 'location', orderable: false, render: function (d) { return d ? escapeHtml(d) : '-'; } },
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
            jQuery('#fl-table tbody').empty();
        }

        jQuery('#fl-filter-btn').on('click', function () {
            appliedFilters = {
                search: jQuery('#fl-filter-search').val(),
                facility_type_id: jQuery('#fl-filter-type').val(),
                facility_category_id: jQuery('#fl-filter-category').val(),
                status: jQuery('#fl-filter-status').val()
            };

            if (dtInstance) {
                dtInstance.ajax.reload();
            } else {
                initTable();
            }
        });

        jQuery('#fl-reset-btn').on('click', function () {
            jQuery('#fl-filter-search').val('');
            jQuery('#fl-filter-type').val('ALL');
            jQuery('#fl-filter-category').val('ALL');
            jQuery('#fl-filter-status').val('ALL');
            appliedFilters = {};
            destroyTable();
        });
    })();
</script>
