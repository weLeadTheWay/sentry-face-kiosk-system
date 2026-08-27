<div class="content-header">
    <h2 class="content-title">Facilities</h2>
    <a href="{{ route('facilities.create') }}" class="btn ajax-link">+ Add Facility</a>
</div>

<div class="table-wrapper">
    <table>
        <thead>
            <tr>
                <th>Code</th>
                <th>Name</th>
                <th>Type</th>
                <th>Category</th>
                <th>RTL</th>
                <th>Location</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse($facilities as $facility)
                <tr>
                    <td><strong>{{ $facility->facility_code }}</strong></td>
                    <td>{{ $facility->facility_name }}</td>
                    <td>{{ $facility->facilityType->facility_type_name }}</td>
                    <td>{{ $facility->facilityCategory->facility_category_name }}</td>
                    <td>{{ $facility->is_rtl ? 'Yes' : 'No' }}</td>
                    <td>{{ $facility->location ?? '-' }}</td>
                    <td><span style="background: {{ $facility->is_active ? '#28a745' : '#dc3545' }}; color: white; padding: 4px 8px; border-radius: 3px; font-size: 12px;">{{ $facility->is_active ? 'Active' : 'Inactive' }}</span></td>
                    <td>
                        <a href="{{ route('facilities.edit', $facility) }}" class="btn ajax-link" style="background: #17a2b8; font-size: 12px;">Edit</a>
                        <form method="POST" action="{{ route('facilities.destroy', $facility) }}" class="ajax-form" style="display: inline;" onsubmit="return confirm('Are you sure?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger" style="font-size: 12px;">Delete</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" style="text-align: center; padding: 2rem;">No facilities found.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

@if($facilities->hasPages())
    <div class="pagination">
        @if($facilities->onFirstPage())
            <span style="opacity: 0.5;">« Previous</span>
        @else
            <a href="{{ $facilities->previousPageUrl() }}" class="ajax-link">« Previous</a>
        @endif

        @foreach($facilities->getUrlRange(1, $facilities->lastPage()) as $page => $url)
            @if($page == $facilities->currentPage())
                <span class="active">{{ $page }}</span>
            @else
                <a href="{{ $url }}" class="ajax-link">{{ $page }}</a>
            @endif
        @endforeach

        @if($facilities->hasMorePages())
            <a href="{{ $facilities->nextPageUrl() }}" class="ajax-link">Next »</a>
        @else
            <span style="opacity: 0.5;">Next »</span>
        @endif
    </div>
@endif
