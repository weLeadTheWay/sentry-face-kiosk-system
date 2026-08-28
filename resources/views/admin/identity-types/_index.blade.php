<div class="content-header"><h2 class="content-title">Identity Types</h2><a href="{{ route('identity-types.create') }}" class="btn ajax-link">+ Add</a></div>

<div class="table-wrapper" style="padding: 1rem 1.5rem;">
    <div style="display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap;">
        <div class="form-group" style="margin-bottom: 0; min-width: 220px;">
            <label for="it-filter-search">Name</label>
            <input type="text" id="it-filter-search" placeholder="Contains...">
        </div>
        <div style="display: flex; gap: 0.5rem;">
            <button type="button" id="it-filter-btn" class="btn">Filter</button>
            <button type="button" id="it-reset-btn" class="btn btn-secondary">Reset</button>
        </div>
    </div>
</div>

<div class="table-wrapper">
    <table id="it-table" class="display" style="width: 100%;">
        <thead>
            <tr>
                <th>Name</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
</div>

<script>
    (function () {
        var csrfToken = '{{ csrf_token() }}';
        var basePath = '{{ route('identity-types.index') }}';
        var endpoint = '{{ route('identity-types.data') }}';
        var dtInstance = null;
        var appliedFilters = {};

        function renderActions(data, type, row) {
            return '<a href="' + basePath + '/' + row.identity_type_id + '/edit" class="btn ajax-link" style="background: #17a2b8; font-size: 12px;">Edit</a>'
                + '<form method="POST" action="' + basePath + '/' + row.identity_type_id + '" class="ajax-form" style="display: inline;" onsubmit="return confirm(\'Sure?\');">'
                +   '<input type="hidden" name="_token" value="' + csrfToken + '">'
                +   '<input type="hidden" name="_method" value="DELETE">'
                +   '<button type="submit" class="btn btn-danger" style="font-size: 12px;">Delete</button>'
                + '</form>';
        }

        function initTable() {
            dtInstance = jQuery('#it-table').DataTable({
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
                    }
                },
                columns: [
                    { data: 'identity_type_name' },
                    { data: null, orderable: false, render: renderActions }
                ]
            });
        }

        function destroyTable() {
            if (dtInstance) {
                dtInstance.destroy();
                dtInstance = null;
            }
            jQuery('#it-table tbody').empty();
        }

        jQuery('#it-filter-btn').on('click', function () {
            appliedFilters = { search: jQuery('#it-filter-search').val() };

            if (dtInstance) {
                dtInstance.ajax.reload();
            } else {
                initTable();
            }
        });

        jQuery('#it-reset-btn').on('click', function () {
            jQuery('#it-filter-search').val('');
            appliedFilters = {};
            destroyTable();
        });
    })();
</script>
