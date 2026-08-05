<div class="content-header">
    <h2 class="content-title">Edit Role</h2>
</div>

<div class="table-wrapper" style="max-width: 600px;">
    <form method="POST" action="{{ route('roles.update', $role) }}" class="ajax-form" style="padding: 1.5rem;">
        @csrf
        @method('PUT')

        <div class="form-group @error('role_name') has-error @enderror">
            <label for="role_name">Name *</label>
            <input type="text" id="role_name" name="role_name" value="{{ old('role_name', $role->role_name) }}" required>
            @error('role_name')
                <div class="error-message">{{ $message }}</div>
            @enderror
        </div>

        <div class="form-group @error('description') has-error @enderror">
            <label for="description">Description</label>
            <textarea id="description" name="description" rows="3">{{ old('description', $role->description) }}</textarea>
            @error('description')
                <div class="error-message">{{ $message }}</div>
            @enderror
        </div>

        <div class="form-actions">
            <button type="submit" class="btn">Update</button>
            <a href="{{ route('roles.index') }}" class="btn btn-secondary ajax-link">Cancel</a>
        </div>
    </form>
</div>
