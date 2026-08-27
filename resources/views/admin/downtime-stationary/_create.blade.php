<p><a href="{{ route('biosecurity-rules.index') }}" class="ajax-link">&larr; Biosecurity Rules</a></p>
<div class="content-header"><h2 class="content-title">Add Downtime Stationary Rule</h2></div>

        <div class="table-wrapper" style="max-width: 600px;"><form method="POST" action="{{ route('downtime-stationary.store') }}" class="ajax-form" style="padding: 1.5rem;">@csrf<div class="form-group @error('assigned_farm_id') has-error @enderror"><label for="assigned_farm_id">Assigned Farm *</label><select id="assigned_farm_id" name="assigned_farm_id" required>@foreach($farms as $f)<option value="{{ $f->farm_id }}">{{ $f->farm_name }}</option>@endforeach</select>@error('assigned_farm_id')<div class="error-message">{{ $message }}</div>@enderror</div>

        <div class="form-group @error('minimum_downtime') has-error @enderror"><label for="minimum_downtime">Minimum Downtime (hours)</label><input type="number" step="0.01" id="minimum_downtime" name="minimum_downtime">@error('minimum_downtime')<div class="error-message">{{ $message }}</div>@enderror</div>

        <div class="form-group @error('maximum_downtime') has-error @enderror"><label for="maximum_downtime">Maximum Downtime (hours)</label><input type="number" step="0.01" id="maximum_downtime" name="maximum_downtime">@error('maximum_downtime')<div class="error-message">{{ $message }}</div>@enderror</div>

        <div class="form-group"><label><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" checked> Active</label></div>

        <div class="form-actions"><button type="submit" class="btn">Create</button><a href="{{ route('downtime-stationary.index') }}" class="btn btn-secondary ajax-link">Cancel</a></div></form>
</div>
