<div class="content-header">
    <h2 class="content-title">Farms</h2>
    <a href="{{ route('farms.create') }}" class="btn ajax-link">+ Add Farm</a>
</div>

<div class="table-wrapper">
    <table>
        <thead>
            <tr>
                <th>Code</th>
                <th>Name</th>
                <th>Location</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse($farms as $farm)
                <tr>
                    <td><strong>{{ $farm->farm_code }}</strong></td>
                    <td>{{ $farm->farm_name }}</td>
                    <td>{{ $farm->location ?? '-' }}</td>
                    <td><span style="background: {{ $farm->is_active ? '#28a745' : '#dc3545' }}; color: white; padding: 4px 8px; border-radius: 3px; font-size: 12px;">{{ $farm->is_active ? 'Active' : 'Inactive' }}</span></td>
                    <td>
                        <a href="{{ route('farms.edit', $farm) }}" class="btn ajax-link" style="background: #17a2b8; font-size: 12px;">Edit</a>
                        <form method="POST" action="{{ route('farms.destroy', $farm) }}" class="ajax-form" style="display: inline;" onsubmit="return confirm('Are you sure?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger" style="font-size: 12px;">Delete</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" style="text-align: center; padding: 2rem;">No farms found.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

@if($farms->hasPages())
    <div class="pagination">
        @if($farms->onFirstPage())
            <span style="opacity: 0.5;">« Previous</span>
        @else
            <a href="{{ $farms->previousPageUrl() }}" class="ajax-link">« Previous</a>
        @endif

        @foreach($farms->getUrlRange(1, $farms->lastPage()) as $page => $url)
            @if($page == $farms->currentPage())
                <span class="active">{{ $page }}</span>
            @else
                <a href="{{ $url }}" class="ajax-link">{{ $page }}</a>
            @endif
        @endforeach

        @if($farms->hasMorePages())
            <a href="{{ $farms->nextPageUrl() }}" class="ajax-link">Next »</a>
        @else
            <span style="opacity: 0.5;">Next »</span>
        @endif
    </div>
@endif
