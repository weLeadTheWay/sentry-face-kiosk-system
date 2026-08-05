<div class="content-header">
    <h2 class="content-title">Add User</h2>
</div>

<div class="table-wrapper" style="max-width: 600px;">
    <form method="POST" action="{{ route('users.store') }}" class="ajax-form" style="padding: 1.5rem;">
        @csrf

        <div class="form-group @error('role_id') has-error @enderror">
            <label for="role_id">Role *</label>
            <select id="role_id" name="role_id" required>
                <option value="">Select a role...</option>
                @foreach($roles as $role)
                    <option value="{{ $role->role_id }}" {{ old('role_id') == $role->role_id ? 'selected' : '' }}>{{ $role->role_name }}</option>
                @endforeach
            </select>
            @error('role_id')
                <div class="error-message">{{ $message }}</div>
            @enderror
        </div>

        <div class="form-group @error('user_name') has-error @enderror">
            <label for="user_name">Name *</label>
            <input type="text" id="user_name" name="user_name" value="{{ old('user_name') }}" required>
            @error('user_name')
                <div class="error-message">{{ $message }}</div>
            @enderror
        </div>

        <div class="form-group @error('user_email') has-error @enderror">
            <label for="user_email">Email *</label>
            <input type="email" id="user_email" name="user_email" value="{{ old('user_email') }}" required>
            @error('user_email')
                <div class="error-message">{{ $message }}</div>
            @enderror
        </div>

        <div class="form-group @error('password') has-error @enderror">
            <label for="password">Password *</label>
            <input type="password" id="password" name="password" required>
            @error('password')
                <div class="error-message">{{ $message }}</div>
            @enderror
        </div>

        <div class="form-group @error('password_confirmation') has-error @enderror">
            <label for="password_confirmation">Confirm Password *</label>
            <input type="password" id="password_confirmation" name="password_confirmation" required>
            @error('password_confirmation')
                <div class="error-message">{{ $message }}</div>
            @enderror
        </div>

        <div class="form-group">
            <label>
                <input type="checkbox" name="is_active" value="1" checked>
                Active
            </label>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn">Create</button>
            <a href="{{ route('users.index') }}" class="btn btn-secondary ajax-link">Cancel</a>
        </div>
    </form>
</div>
