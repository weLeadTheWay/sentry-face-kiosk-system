<div class="content-header"><h2 class="content-title">Add Role</h2></div>

        <div class="table-wrapper" style="max-width: 600px;"><form method="POST" action="{{ route('roles.store') }}" class="ajax-form" style="padding: 1.5rem;">@csrf<div class="form-group @error('role_name') has-error @enderror"><label for="role_name">Name *</label><input type="text" id="role_name" name="role_name" required>@error('role_name')<div class="error-message">{{ $message }}</div>@enderror</div>

        <div class="form-group @error('description') has-error @enderror"><label for="description">Description</label><textarea id="description" name="description" rows="3"></textarea>@error('description')<div class="error-message">{{ $message }}</div>@enderror</div>

        <div class="form-actions"><button type="submit" class="btn">Create</button><a href="{{ route('roles.index') }}" class="btn btn-secondary ajax-link">Cancel</a></div></form>
</div>
