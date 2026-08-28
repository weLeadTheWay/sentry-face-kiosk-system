<p><a href="{{ route('downtime-matrix-import.index') }}" class="ajax-link">&larr; Downtime Matrix Import</a></p>
<div class="content-header"><h2 class="content-title">Import Downtime Matrix PDF</h2></div>

<div class="table-wrapper" style="max-width: 600px;">
    {{-- Deliberately NOT an .ajax-form: admin.js submits ajax-forms via
         jQuery's form.serialize(), which silently drops file inputs. This
         form does a plain browser POST instead; every controller in this
         app renders the resulting view directly rather than redirecting,
         so the Preview page still renders correctly - the only difference
         is a full page navigation instead of an in-place #content swap. --}}
    <form method="POST" action="{{ route('downtime-matrix-import.store') }}" enctype="multipart/form-data" style="padding: 1.5rem;">
        @csrf
        <div class="form-group @error('matrix_type') has-error @enderror">
            <label for="matrix_type">Matrix Type *</label>
            <select id="matrix_type" name="matrix_type" required>
                <option value="BFI_BVA">BFI/BVA</option>
                <option value="HOGS" disabled>Hogs (Coming Soon)</option>
            </select>
            @error('matrix_type')<div class="error-message">{{ $message }}</div>@enderror
        </div>

        <div class="form-group @error('pdf_file') has-error @enderror">
            <label for="pdf_file">Downtime Matrix PDF *</label>
            <input type="file" id="pdf_file" name="pdf_file" accept="application/pdf" required>
            @error('pdf_file')<div class="error-message">{{ $message }}</div>@enderror
        </div>

        <div class="form-actions">
            <button type="submit" class="btn">Upload &amp; Parse</button>
            <a href="{{ route('downtime-matrix-import.index') }}" class="btn btn-secondary ajax-link">Cancel</a>
        </div>
    </form>
</div>
