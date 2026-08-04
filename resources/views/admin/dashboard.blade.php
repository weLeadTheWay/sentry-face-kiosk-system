<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - {{ config('app.name') }}</title>
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
        .navbar {
            background: white;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .navbar h1 {
            font-size: 20px;
            color: #667eea;
        }
        .navbar-actions {
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
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        .logout-btn:hover {
            background: #c82333;
        }
        .container {
            padding: 2rem;
            max-width: 1200px;
            margin: 0 auto;
        }
        .welcome-box {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }
        .welcome-box h2 {
            color: #667eea;
            margin-bottom: 1rem;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-top: 2rem;
        }
        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            text-align: center;
        }
        .stat-card h3 {
            font-size: 28px;
            color: #667eea;
            margin-bottom: 0.5rem;
        }
        .stat-card p {
            color: #666;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="navbar">
        <h1>{{ config('app.name') }}</h1>
        <div class="navbar-actions">
            <div class="user-info">
                Welcome, <strong>{{ auth()->user()->user_name }}</strong>
            </div>
            <form method="POST" action="{{ route('logout') }}" style="display: inline;">
                @csrf
                <button type="submit" class="logout-btn">Logout</button>
            </form>
        </div>
    </div>

    <div class="container">
        <div class="welcome-box">
            <h2>Welcome to {{ config('app.name') }}</h2>
            <p>Phase 1 foundation is now operational. Core infrastructure includes:</p>
            <ul style="margin-top: 1rem; margin-left: 2rem; line-height: 1.8;">
                <li>Authentication & authorization system</li>
                <li>Role-based permission management</li>
                <li>Audit logging for all changes</li>
                <li>Master data tables (Farms, Kiosk Devices, Biosecurity Rules, etc.)</li>
            </ul>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <h3>{{ \App\Models\FarmList::count() }}</h3>
                <p>Farms</p>
            </div>
            <div class="stat-card">
                <h3>{{ \App\Models\KioskDevice::count() }}</h3>
                <p>Kiosk Devices</p>
            </div>
            <div class="stat-card">
                <h3>{{ \App\Models\User::count() }}</h3>
                <p>System Users</p>
            </div>
            <div class="stat-card">
                <h3>{{ \App\Models\Role::count() }}</h3>
                <p>Roles</p>
            </div>
        </div>
    </div>
</body>
</html>
