<div class="content-header"><h2 class="content-title">Add Identity Type</h2></div>

        <div class="table-wrapper" style="max-width: 600px;"><form method="POST" action="{{ route('identity-types.store') }}" class="ajax-form" style="padding: 1.5rem;">@csrf<div class="form-group @error('identity_type_name') has-error @enderror"><label for="identity_type_name">Name *</label><input type="text" id="identity_type_name" name="identity_type_name" required>@error('identity_type_name')<div class="error-message">{{ $message }}</div>@enderror</div>

        <div class="form-actions"><button type="submit" class="btn">Create</button><a href="{{ route('identity-types.index') }}" class="btn btn-secondary ajax-link">Cancel</a></div></form>
</div>
