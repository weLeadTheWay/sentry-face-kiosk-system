<p><a href="{{ route('biosecurity-rules.index') }}" class="ajax-link">&larr; Biosecurity Rules</a></p>
<div class="content-header">
    <h2 class="content-title">Edit Downtime Matrix Rule</h2>
</div>

<div class="table-wrapper" style="max-width: 600px;">
    <form method="POST" action="{{ route('downtime-matrix.update', $downtime_matrix) }}" class="ajax-form" style="padding: 1.5rem;">
        @csrf
        @method('PUT')

        <div class="form-group @error('origin_facility_id') has-error @enderror">
            <label for="origin_facility_id">Origin Facility *</label>
            <select id="origin_facility_id" name="origin_facility_id" required>
                @foreach($facilities as $f)
                    <option value="{{ $f->facility_id }}" {{ $f->facility_id == $downtime_matrix->origin_facility_id ? 'selected' : '' }}>{{ $f->facility_name }}</option>
                @endforeach
            </select>
            @error('origin_facility_id')
                <div class="error-message">{{ $message }}</div>
            @enderror
        </div>

        <div class="form-group @error('destination_facility_id') has-error @enderror">
            <label for="destination_facility_id">Destination Facility *</label>
            <select id="destination_facility_id" name="destination_facility_id" required>
                @foreach($facilities as $f)
                    <option value="{{ $f->facility_id }}" {{ $f->facility_id == $downtime_matrix->destination_facility_id ? 'selected' : '' }}>{{ $f->facility_name }}</option>
                @endforeach
            </select>
            @error('destination_facility_id')
                <div class="error-message">{{ $message }}</div>
            @enderror
        </div>

        <div class="form-group @error('minimum_downtime') has-error @enderror">
            <label for="minimum_downtime">Minimum Downtime (hours)</label>
            <input type="number" step="0.01" id="minimum_downtime" name="minimum_downtime" value="{{ old('minimum_downtime', $downtime_matrix->minimum_downtime) }}">
            @error('minimum_downtime')
                <div class="error-message">{{ $message }}</div>
            @enderror
        </div>

        <div class="form-group @error('maximum_downtime') has-error @enderror">
            <label for="maximum_downtime">Maximum Downtime (hours)</label>
            <input type="number" step="0.01" id="maximum_downtime" name="maximum_downtime" value="{{ old('maximum_downtime', $downtime_matrix->maximum_downtime) }}">
            @error('maximum_downtime')
                <div class="error-message">{{ $message }}</div>
            @enderror
        </div>

        <div class="form-group">
            <label>
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" {{ old('is_active', $downtime_matrix->is_active) ? 'checked' : '' }}>
                Active
            </label>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn">Update</button>
            <a href="{{ route('downtime-matrix.index') }}" class="btn btn-secondary ajax-link">Cancel</a>
        </div>
    </form>
</div>
