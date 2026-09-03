<div class="content-header">
    <h2 class="content-title">Facility Configuration</h2>
</div>

<div class="table-wrapper" style="padding: 1rem 1.5rem;">
    <div style="display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap;">
        <div class="form-group" style="margin-bottom: 0; min-width: 220px;">
            <label for="fc-filter-search">Code / Name</label>
            <input type="text" id="fc-filter-search" placeholder="Contains...">
        </div>
        <div style="display: flex; gap: 0.5rem;">
            <button type="button" id="fc-filter-btn" class="btn">Filter</button>
            <button type="button" id="fc-reset-btn" class="btn btn-secondary">Reset</button>
        </div>
    </div>
</div>

<div class="table-wrapper">
    <table id="fc-table" class="display" style="width: 100%;">
        <thead>
            <tr>
                <th>Code</th>
                <th>Facility</th>
                <th>Gatesale</th>
                <th>Break Enabled</th>
                <th>Truck</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
</div>

<script>
    (function () {
        var csrfToken = '{{ csrf_token() }}';
        var basePath = '{{ route('facility-configuration.index') }}';
        var endpoint = '{{ route('facility-configuration.data') }}';
        var dtInstance = null;
        var appliedFilters = {};

        // field is always one of a fixed literal set we pass in ourselves
        // (never user-supplied), and facility_id is numeric - neither needs
        // escapeHtml. facility_code/facility_name are plain {data: '...'}
        // columns with no custom render, so DataTables auto-escapes those.
        function renderToggle(field) {
            return function (data, type, row) {
                var checked = row[field] ? 'checked' : '';
                return '<input type="checkbox" class="fc-toggle" data-facility-id="' + row.facility_id + '" data-field="' + field + '" ' + checked + '>';
            };
        }

        function initTable() {
            dtInstance = jQuery('#fc-table').DataTable({
                serverSide: true,
                processing: true,
                searching: false,
                lengthMenu: [10, 25, 50, 100],
                pageLength: 25,
                order: [[1, 'asc']],
                ajax: {
                    url: endpoint,
                    data: function (d) {
                        d.search = appliedFilters.search || '';
                    }
                },
                columns: [
                    { data: 'facility_code' },
                    { data: 'facility_name' },
                    { data: null, orderable: false, render: renderToggle('is_gs') },
                    { data: null, orderable: false, render: renderToggle('is_break_enabled') },
                    { data: null, orderable: false, render: renderToggle('is_truck') }
                ]
            });
        }

        function destroyTable() {
            if (dtInstance) {
                dtInstance.destroy();
                dtInstance = null;
            }
            jQuery('#fc-table tbody').empty();
        }

        // Only a Filter click ever triggers a /data request - not page
        // load, not typing into the filter box.
        jQuery('#fc-filter-btn').on('click', function () {
            appliedFilters = {
                search: jQuery('#fc-filter-search').val()
            };

            if (dtInstance) {
                dtInstance.ajax.reload();
            } else {
                initTable();
            }
        });

        jQuery('#fc-reset-btn').on('click', function () {
            jQuery('#fc-filter-search').val('');
            appliedFilters = {};
            destroyTable();
        });

        // Each checkbox saves immediately on change - no separate Save
        // button, matching the "check/uncheck directly" requirement.
        // Reverts the checkbox and alerts on failure (permission lost
        // mid-session, validation error, etc).
        jQuery('#fc-table tbody').on('change', '.fc-toggle', function () {
            var checkbox = jQuery(this);
            var facilityId = checkbox.data('facility-id');
            var field = checkbox.data('field');
            var value = checkbox.is(':checked');

            checkbox.prop('disabled', true);

            jQuery.ajax({
                url: basePath + '/' + facilityId,
                type: 'PATCH',
                data: {
                    field: field,
                    value: value ? 1 : 0,
                    _token: csrfToken
                },
                success: function () {
                    checkbox.prop('disabled', false);
                },
                error: function (xhr) {
                    checkbox.prop('checked', !value);
                    checkbox.prop('disabled', false);
                    var message = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : xhr.statusText;
                    alert('Failed to update: ' + message);
                }
            });
        });
    })();
</script>
