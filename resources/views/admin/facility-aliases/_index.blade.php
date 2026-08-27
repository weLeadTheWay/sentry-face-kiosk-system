<div class="content-header">
    <h2 class="content-title">Facility Aliases</h2>
    <a href="{{ route('facility-aliases.create') }}" class="btn ajax-link">+ Add</a>
</div>

<div class="table-wrapper">
    <table>
        <thead>
            <tr>
                <th>Alias Text</th>
                <th>Facility</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse($facility_aliases as $alias)
                <tr>
                    <td>{{ $alias->alias_text }}</td>
                    <td>{{ $alias->facility->facility_name }}</td>
                    <td>
                        <a href="{{ route('facility-aliases.edit', $alias) }}" class="btn ajax-link" style="background: #17a2b8; font-size: 12px;">Edit</a>
                        <form method="POST" action="{{ route('facility-aliases.destroy', $alias) }}" class="ajax-form" style="display: inline;" onsubmit="return confirm('Sure?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger" style="font-size: 12px;">Delete</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="3" style="text-align: center; padding: 2rem;">No records.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
