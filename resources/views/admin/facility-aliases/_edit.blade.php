<div class="content-header">
    <h2 class="content-title">Edit Facility Alias</h2>
</div>

<div class="table-wrapper" style="max-width: 600px;">
    <form method="POST" action="{{ route('facility-aliases.update', $facility_alias) }}" class="ajax-form" style="padding: 1.5rem;">
        @csrf
        @method('PUT')

        <div class="form-group @error('alias_text') has-error @enderror">
            <label for="alias_text">Alias Text *</label>
            <input type="text" id="alias_text" name="alias_text" value="{{ old('alias_text', $facility_alias->alias_text) }}" required>
            @error('alias_text')
                <div class="error-message">{{ $message }}</div>
            @enderror
        </div>

        <div class="form-group @error('facility_id') has-error @enderror">
            <label for="facility_id">Facility *</label>
            <select id="facility_id" name="facility_id" required>
                @foreach($facilities as $f)
                    <option value="{{ $f->facility_id }}" {{ $f->facility_id == $facility_alias->facility_id ? 'selected' : '' }}>{{ $f->facility_name }}</option>
                @endforeach
            </select>
            @error('facility_id')
                <div class="error-message">{{ $message }}</div>
            @enderror
        </div>

        <div class="form-actions">
            <button type="submit" class="btn">Update</button>
            <a href="{{ route('facility-aliases.index') }}" class="btn btn-secondary ajax-link">Cancel</a>
        </div>
    </form>
</div>
