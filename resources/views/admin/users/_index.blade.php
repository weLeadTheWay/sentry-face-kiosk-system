<div class="content-header">
    <h2 class="content-title">Users</h2>
    <a href="{{ route('users.create') }}" class="btn ajax-link">+ Add User</a>
</div>

<div class="table-wrapper">
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse($users as $user)
                <tr>
                    <td><strong>{{ $user->user_name }}</strong></td>
                    <td>{{ $user->user_email }}</td>
                    <td>{{ $user->role->role_name ?? '-' }}</td>
                    <td><span style="background: {{ $user->is_active ? '#28a745' : '#dc3545' }}; color: white; padding: 4px 8px; border-radius: 3px; font-size: 12px;">{{ $user->is_active ? 'Active' : 'Inactive' }}</span></td>
                    <td>
                        <a href="{{ route('users.edit', $user) }}" class="btn ajax-link" style="background: #17a2b8; font-size: 12px;">Edit</a>
                        @if($user->user_id !== auth()->user()->user_id)
                            <form method="POST" action="{{ route('users.destroy', $user) }}" class="ajax-form" style="display: inline;" onsubmit="return confirm('Are you sure?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-danger" style="font-size: 12px;">Delete</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" style="text-align: center; padding: 2rem;">No users found.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

@if($users->hasPages())
    <div class="pagination">
        @if($users->onFirstPage())
            <span style="opacity: 0.5;">« Previous</span>
        @else
            <a href="{{ $users->previousPageUrl() }}" class="ajax-link">« Previous</a>
        @endif

        @foreach($users->getUrlRange(1, $users->lastPage()) as $page => $url)
            @if($page == $users->currentPage())
                <span class="active">{{ $page }}</span>
            @else
                <a href="{{ $url }}" class="ajax-link">{{ $page }}</a>
            @endif
        @endforeach

        @if($users->hasMorePages())
            <a href="{{ $users->nextPageUrl() }}" class="ajax-link">Next »</a>
        @else
            <span style="opacity: 0.5;">Next »</span>
        @endif
    </div>
@endif
