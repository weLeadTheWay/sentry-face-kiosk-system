<div class="content-header">
    <h2 class="content-title">Edit Facility</h2>
</div>

<div class="table-wrapper" style="max-width: 600px;">
    <form method="POST" action="{{ route('facilities.update', $facility) }}" class="ajax-form" style="padding: 1.5rem;">
        @csrf
        @method('PUT')

        <div class="form-group @error('facility_code') has-error @enderror">
            <label for="facility_code">Facility Code *</label>
            <input type="text" id="facility_code" name="facility_code" value="{{ old('facility_code', $facility->facility_code) }}" required>
            @error('facility_code')
                <div class="error-message">{{ $message }}</div>
            @enderror
        </div>

        <div class="form-group @error('facility_name') has-error @enderror">
            <label for="facility_name">Facility Name *</label>
            <input type="text" id="facility_name" name="facility_name" value="{{ old('facility_name', $facility->facility_name) }}" required>
            @error('facility_name')
                <div class="error-message">{{ $message }}</div>
            @enderror
        </div>

        <div class="form-group @error('facility_type_id') has-error @enderror">
            <label for="facility_type_id">Facility Type *</label>
            <select id="facility_type_id" name="facility_type_id" required>
                <option value="">-- Select --</option>
                @foreach($facility_types as $type)
                    <option value="{{ $type->facility_type_id }}" {{ old('facility_type_id', $facility->facility_type_id) == $type->facility_type_id ? 'selected' : '' }}>{{ $type->facility_type_name }}</option>
                @endforeach
            </select>
            @error('facility_type_id')
                <div class="error-message">{{ $message }}</div>
            @enderror
        </div>

        <div class="form-group @error('facility_category_id') has-error @enderror">
            <label for="facility_category_id">Facility Category *</label>
            <select id="facility_category_id" name="facility_category_id" required>
                <option value="">-- Select --</option>
                @foreach($facility_categories as $category)
                    <option value="{{ $category->facility_category_id }}" {{ old('facility_category_id', $facility->facility_category_id) == $category->facility_category_id ? 'selected' : '' }}>{{ $category->facility_category_name }}</option>
                @endforeach
            </select>
            @error('facility_category_id')
                <div class="error-message">{{ $message }}</div>
            @enderror
        </div>

        <div class="form-group">
            <label for="location">Location</label>
            <input type="text" id="location" name="location" value="{{ old('location', $facility->location) }}">
        </div>

        <div class="form-group">
            <label>
                <input type="hidden" name="is_rtl" value="0"><input type="checkbox" name="is_rtl" value="1" {{ old('is_rtl', $facility->is_rtl) ? 'checked' : '' }}>
                RTL Farm
            </label>
        </div>

        <div class="form-group">
            <label>
                <input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" {{ old('is_active', $facility->is_active) ? 'checked' : '' }}>
                Active
            </label>
        </div>

        <div class="form-group">
            <label>
                <input type="hidden" name="is_gs" value="0"><input type="checkbox" name="is_gs" value="1" {{ old('is_gs', $facility->is_gs) ? 'checked' : '' }}>
                Gatesale Enabled
            </label>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn">Update</button>
            <a href="{{ route('facilities.index') }}" class="btn btn-secondary ajax-link">Cancel</a>
        </div>
    </form>
</div>
