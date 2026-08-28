<p><a href="{{ route('biosecurity-rules.index') }}" class="ajax-link">&larr; Biosecurity Rules</a></p>
<div class="content-header"><h2 class="content-title">Downtime Matrix Import</h2><a href="{{ route('downtime-matrix-import.create') }}" class="btn ajax-link">+ Import</a></div>
<div class="table-wrapper"><table><thead><tr><th>File</th><th>Matrix Type</th><th>Uploaded By</th><th>Uploaded At</th><th>Status</th><th>Valid</th><th>Warning</th><th>Unmatched</th><th>Ambiguous</th><th>Invalid</th><th>Actions</th></tr></thead><tbody>
@forelse($downtime_matrix_imports as $import)
    <tr>
        <td>{{ $import->original_filename }}</td>
        <td>{{ $import->matrix_type }}</td>
        <td>{{ $import->uploadedBy->user_name ?? '-' }}</td>
        <td>{{ $import->created_at?->format('n/d/Y H:i') }}</td>
        <td>
            @php
                $statusColor = match($import->status) {
                    'VERIFIED' => '#28a745',
                    'CANCELLED' => '#6c757d',
                    default => '#ffc107',
                };
            @endphp
            <span style="background: {{ $statusColor }}; color: white; padding: 0.25rem 0.5rem; border-radius: 3px; font-size: 12px;">{{ $import->status }}</span>
        </td>
        <td>{{ $import->valid_rows_count }}</td>
        <td>{{ $import->warning_rows_count }}</td>
        <td>{{ $import->unmatched_rows_count }}</td>
        <td>{{ $import->ambiguous_rows_count }}</td>
        <td>{{ $import->invalid_rows_count }}</td>
        <td><a href="{{ route('downtime-matrix-import.show', $import) }}" class="btn ajax-link" style="background: #17a2b8; font-size: 12px;">View</a></td>
    </tr>
@empty
    <tr><td colspan="11" style="text-align: center; padding: 2rem;">No imports yet.</td></tr>
@endforelse
</tbody></table></div>

{{ $downtime_matrix_imports->links() }}
