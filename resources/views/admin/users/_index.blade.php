<div class="content-header">
    <h2 class="content-title">Users</h2>
    <a href="{{ route('users.create') }}" class="btn ajax-link">+ Add User</a>
</div>

<div class="table-wrapper" style="padding: 1rem 1.5rem;">
    <div style="display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap;">
        <div class="form-group" style="margin-bottom: 0; min-width: 220px;">
            <label for="us-filter-search">Name / Email</label>
            <input type="text" id="us-filter-search" placeholder="Contains...">
        </div>
        <div class="form-group" style="margin-bottom: 0; min-width: 180px;">
            <label for="us-filter-role">Role</label>
            <select id="us-filter-role">
                <option value="ALL">All Roles</option>
                @foreach($roles as $role)
                    <option value="{{ $role->role_id }}">{{ $role->role_name }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-group" style="margin-bottom: 0; min-width: 150px;">
            <label for="us-filter-status">Status</label>
            <select id="us-filter-status">
                <option value="ALL">All</option>
                <option value="ACTIVE">Active</option>
                <option value="INACTIVE">Inactive</option>
            </select>
        </div>
        <div style="display: flex; gap: 0.5rem;">
            <button type="button" id="us-filter-btn" class="btn">Filter</button>
            <button type="button" id="us-reset-btn" class="btn btn-secondary">Reset</button>
        </div>
    </div>
</div>

<div class="table-wrapper">
    <table id="us-table" class="display" style="width: 100%;">
        <thead>
            <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Role</th>
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
        var basePath = '{{ route('users.index') }}';
        var endpoint = '{{ route('users.data') }}';
        var currentUserId = {{ auth()->id() }};
        var dtInstance = null;
        var appliedFilters = {};

        function renderName(data, type, row) {
            return '<strong>' + escapeHtml(row.user_name) + '</strong>';
        }

        function renderStatus(data, type, row) {
            var color = row.is_active ? '#28a745' : '#dc3545';
            var label = row.is_active ? 'Active' : 'Inactive';
            return '<span style="background: ' + color + '; color: white; padding: 4px 8px; border-radius: 3px; font-size: 12px;">' + label + '</span>';
        }

        function renderActions(data, type, row) {
            var html = '<a href="' + basePath + '/' + row.user_id + '/edit" class="btn ajax-link" style="background: #17a2b8; font-size: 12px;">Edit</a>';

            if (row.user_id !== currentUserId) {
                html += '<form method="POST" action="' + basePath + '/' + row.user_id + '" class="ajax-form" style="display: inline;" onsubmit="return confirm(\'Are you sure?\');">'
                    + '<input type="hidden" name="_token" value="' + csrfToken + '">'
                    + '<input type="hidden" name="_method" value="DELETE">'
                    + '<button type="submit" class="btn btn-danger" style="font-size: 12px;">Delete</button>'
                    + '</form>';
            }

            return html;
        }

        function initTable() {
            dtInstance = jQuery('#us-table').DataTable({
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
                        d.role_id = appliedFilters.role_id || 'ALL';
                        d.status = appliedFilters.status || 'ALL';
                    }
                },
                columns: [
                    { data: null, render: renderName },
                    { data: 'user_email' },
                    { data: 'role_name', orderable: false, render: function (d) { return d ? escapeHtml(d) : '-'; } },
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
            jQuery('#us-table tbody').empty();
        }

        jQuery('#us-filter-btn').on('click', function () {
            appliedFilters = {
                search: jQuery('#us-filter-search').val(),
                role_id: jQuery('#us-filter-role').val(),
                status: jQuery('#us-filter-status').val()
            };

            if (dtInstance) {
                dtInstance.ajax.reload();
            } else {
                initTable();
            }
        });

        jQuery('#us-reset-btn').on('click', function () {
            jQuery('#us-filter-search').val('');
            jQuery('#us-filter-role').val('ALL');
            jQuery('#us-filter-status').val('ALL');
            appliedFilters = {};
            destroyTable();
        });
    })();
</script>
