<div class="content-header"><h2 class="content-title">Add Employee Type</h2></div>

        <div class="table-wrapper" style="max-width: 600px;"><form method="POST" action="{{ route('employee-types.store') }}" class="ajax-form" style="padding: 1.5rem;">@csrf<div class="form-group @error('employee_type_name') has-error @enderror"><label for="employee_type_name">Name *</label><input type="text" id="employee_type_name" name="employee_type_name" required>@error('employee_type_name')<div class="error-message">{{ $message }}</div>@enderror</div>

        <div class="form-actions"><button type="submit" class="btn">Create</button><a href="{{ route('employee-types.index') }}" class="btn btn-secondary ajax-link">Cancel</a></div></form>
</div>
