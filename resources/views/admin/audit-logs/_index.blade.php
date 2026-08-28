<div class="content-header">
    <h2 class="content-title">Audit Logs</h2>
</div>

<div class="table-wrapper" style="padding: 1rem 1.5rem;">
    <div style="display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap;">
        <div class="form-group" style="margin-bottom: 0; min-width: 180px;">
            <label for="al-filter-module">Module</label>
            <select id="al-filter-module">
                <option value="ALL">All Modules</option>
                @foreach($modules as $module)
                    <option value="{{ $module }}">{{ $module }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-group" style="margin-bottom: 0; min-width: 150px;">
            <label for="al-filter-action">Action</label>
            <select id="al-filter-action">
                <option value="ALL">All Actions</option>
                @foreach($actions as $action)
                    <option value="{{ $action }}">{{ ucfirst($action) }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-group" style="margin-bottom: 0; min-width: 200px;">
            <label for="al-filter-user">User</label>
            <select id="al-filter-user">
                <option value="ALL">All Users</option>
                @foreach($users as $user)
                    <option value="{{ $user->user_id }}">{{ $user->user_name }}</option>
                @endforeach
            </select>
        </div>
        <div style="display: flex; gap: 0.5rem;">
            <button type="button" id="al-filter-btn" class="btn">Filter</button>
            <button type="button" id="al-reset-btn" class="btn btn-secondary">Reset</button>
        </div>
    </div>
</div>

<div class="table-wrapper">
    <table id="al-table" class="display" style="width: 100%;">
        <thead>
            <tr>
                <th>Module</th>
                <th>Action</th>
                <th>User</th>
                <th>Date</th>
                <th>Details</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
</div>

<script>
    (function () {
        var endpoint = '{{ route('audit-logs.data') }}';
        var dtInstance = null;
        var appliedFilters = {};

        function renderDetails(data, type, row) {
            var details = row.change_log ? String(row.change_log).substring(0, 50) : '';
            return '<small style="color: #666;">' + escapeHtml(details) + '</small>';
        }

        function initTable() {
            dtInstance = jQuery('#al-table').DataTable({
                serverSide: true,
                processing: true,
                searching: false,
                lengthMenu: [10, 25, 50, 100],
                pageLength: 25,
                order: [[3, 'desc']],
                ajax: {
                    url: endpoint,
                    data: function (d) {
                        d.module = appliedFilters.module || 'ALL';
                        d.action = appliedFilters.action || 'ALL';
                        d.user_id = appliedFilters.user_id || 'ALL';
                    }
                },
                columns: [
                    { data: 'module' },
                    { data: 'action' },
                    { data: 'user_name', orderable: false, render: function (d) { return d ? escapeHtml(d) : '-'; } },
                    { data: 'created_at' },
                    { data: null, orderable: false, render: renderDetails }
                ]
            });
        }

        function destroyTable() {
            if (dtInstance) {
                dtInstance.destroy();
                dtInstance = null;
            }
            jQuery('#al-table tbody').empty();
        }

        jQuery('#al-filter-btn').on('click', function () {
            appliedFilters = {
                module: jQuery('#al-filter-module').val(),
                action: jQuery('#al-filter-action').val(),
                user_id: jQuery('#al-filter-user').val()
            };

            if (dtInstance) {
                dtInstance.ajax.reload();
            } else {
                initTable();
            }
        });

        jQuery('#al-reset-btn').on('click', function () {
            jQuery('#al-filter-module').val('ALL');
            jQuery('#al-filter-action').val('ALL');
            jQuery('#al-filter-user').val('ALL');
            appliedFilters = {};
            destroyTable();
        });
    })();
</script>
