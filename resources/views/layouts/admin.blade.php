<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - {{ config('app.name') }}</title>
    @vite(['resources/js/app.js', 'resources/js/admin.js'])
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: #f5f7fa;
            color: #333;
        }
        .admin-container {
            display: flex;
            height: 100vh;
        }
        .sidebar {
            width: 250px;
            background: white;
            border-right: 1px solid #e0e0e0;
            overflow-y: auto;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .sidebar-header {
            padding: 1.5rem;
            border-bottom: 1px solid #f0f0f0;
            font-size: 20px;
            font-weight: bold;
            color: #667eea;
        }
        .sidebar-menu {
            list-style: none;
            padding: 1rem 0;
        }
        .sidebar-menu li {
            margin: 0;
        }
        .sidebar-menu a {
            display: block;
            padding: 0.75rem 1.5rem;
            color: #333;
            text-decoration: none;
            border-left: 3px solid transparent;
            transition: all 0.3s;
        }
        .sidebar-menu a:hover {
            background: #f9f9f9;
            border-left-color: #667eea;
            padding-left: 1.25rem;
        }
        .sidebar-menu a.ajax-link.active {
            background: #f9f9f9;
            border-left-color: #667eea;
            color: #667eea;
            font-weight: 500;
        }
        .top-bar {
            background: white;
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .top-bar-left {
            font-size: 18px;
            font-weight: 600;
            color: #667eea;
        }
        .top-bar-right {
            display: flex;
            gap: 1rem;
            align-items: center;
        }
        .user-info {
            font-size: 14px;
            color: #666;
        }
        .logout-btn {
            background: #dc3545;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        .logout-btn:hover {
            background: #c82333;
        }
        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        #content {
            flex: 1;
            overflow-y: auto;
            padding: 1.5rem;
        }
        .content-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        .content-title {
            font-size: 24px;
            font-weight: 600;
            color: #333;
        }
        .btn {
            background: #667eea;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
        }
        .btn:hover {
            background: #5568d3;
        }
        .btn-danger {
            background: #dc3545;
        }
        .btn-danger:hover {
            background: #c82333;
        }
        .btn-secondary {
            background: #6c757d;
        }
        .btn-secondary:hover {
            background: #5a6268;
        }
        .table-wrapper {
            background: white;
            border-radius: 4px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            overflow: hidden;
            margin-bottom: 1.5rem;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #f0f0f0;
        }
        th {
            background: #f9f9f9;
            font-weight: 600;
            color: #333;
        }
        tr:hover {
            background: #fafafa;
        }
        .pagination {
            display: flex;
            gap: 0.5rem;
            justify-content: center;
            padding: 1rem;
        }
        .pagination a {
            padding: 6px 10px;
            background: white;
            border: 1px solid #ddd;
            color: #667eea;
            text-decoration: none;
            border-radius: 4px;
        }
        .pagination a:hover, .pagination span.active {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        .form-group {
            margin-bottom: 1rem;
        }
        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #333;
        }
        input, select, textarea {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .form-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
        }
        .error-message {
            color: #dc3545;
            font-size: 13px;
            margin-top: 4px;
        }
        .has-error input, .has-error select {
            border-color: #dc3545;
        }
        .alert {
            padding: 12px 15px;
            margin-bottom: 1rem;
            border-radius: 4px;
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <aside class="sidebar">
            <div class="sidebar-header">{{ config('app.name') }}</div>
            <ul class="sidebar-menu">
                <li><a href="{{ route('dashboard') }}" class="ajax-link">Dashboard</a></li>
                @if(auth()->user()->hasPermission('farms.manage'))
                    <li><a href="{{ route('farms.index') }}" class="ajax-link">Farms</a></li>
                    <li><a href="{{ route('farm-aliases.index') }}" class="ajax-link">Farm Aliases</a></li>
                @endif
                @if(auth()->user()->hasPermission('kiosks.manage'))
                    <li><a href="{{ route('kiosks.index') }}" class="ajax-link">Kiosks</a></li>
                @endif
                @if(auth()->user()->hasPermission('identity_types.manage'))
                    <li><a href="{{ route('identity-types.index') }}" class="ajax-link">Identity Types</a></li>
                @endif
                @if(auth()->user()->hasPermission('employee_types.manage'))
                    <li><a href="{{ route('employee-types.index') }}" class="ajax-link">Employee Types</a></li>
                @endif
                @if(auth()->user()->hasPermission('biosecurity.manage'))
                    <li><a href="{{ route('biosecurity-rules.index') }}" class="ajax-link">Biosecurity Rules</a></li>
                @endif
                @if(auth()->user()->hasPermission('roles.manage'))
                    <li><a href="{{ route('roles.index') }}" class="ajax-link">Roles</a></li>
                @endif
                @if(auth()->user()->hasPermission('users.manage'))
                    <li><a href="{{ route('users.index') }}" class="ajax-link">Users</a></li>
                @endif
                @if(auth()->user()->hasPermission('audit_logs.view'))
                    <li><a href="{{ route('audit-logs.index') }}" class="ajax-link">Audit Logs</a></li>
                @endif
            </ul>
        </aside>
        <div class="main-content">
            <div class="top-bar">
                <div class="top-bar-left">Admin Panel</div>
                <div class="top-bar-right">
                    <div class="user-info">{{ auth()->user()->user_name }}</div>
                    <form method="POST" action="{{ route('logout') }}" style="display: inline;">
                        @csrf
                        <button type="submit" class="logout-btn">Logout</button>
                    </form>
                </div>
            </div>
            <div id="content">
                @yield('content')
            </div>
        </div>
    </div>
</body>
</html>
