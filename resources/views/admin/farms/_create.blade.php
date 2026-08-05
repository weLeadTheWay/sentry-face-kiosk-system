<div class="content-header">
    <h2 class="content-title">Add Farm</h2>
</div>

<div class="table-wrapper" style="max-width: 600px;">
    <form method="POST" action="{{ route('farms.store') }}" class="ajax-form" style="padding: 1.5rem;">
        @csrf

        <div class="form-group @error('farm_code') has-error @enderror">
            <label for="farm_code">Farm Code *</label>
            <input type="text" id="farm_code" name="farm_code" value="{{ old('farm_code') }}" required>
            @error('farm_code')
                <div class="error-message">{{ $message }}</div>
            @enderror
        </div>

        <div class="form-group @error('farm_name') has-error @enderror">
            <label for="farm_name">Farm Name *</label>
            <input type="text" id="farm_name" name="farm_name" value="{{ old('farm_name') }}" required>
            @error('farm_name')
                <div class="error-message">{{ $message }}</div>
            @enderror
        </div>

        <div class="form-group">
            <label for="location">Location</label>
            <input type="text" id="location" name="location" value="{{ old('location') }}">
        </div>

        <div class="form-group">
            <label>
                <input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" {{ old('is_active', true) ? 'checked' : '' }}>
                Active
            </label>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn">Create</button>
            <a href="{{ route('farms.index') }}" class="btn btn-secondary ajax-link">Cancel</a>
        </div>
    </form>
</div>
