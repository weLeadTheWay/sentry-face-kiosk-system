<div style="padding: 2rem; max-width: 1200px;">
    <div style="background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1); margin-bottom: 2rem;">
        <h2 style="color: #667eea; margin-bottom: 1rem;">Welcome to {{ config('app.name') }}</h2>
        <p>Phase 1 foundation is now operational. Core infrastructure includes:</p>
        <ul style="margin-top: 1rem; margin-left: 2rem; line-height: 1.8;">
            <li>Authentication & authorization system</li>
            <li>Role-based permission management</li>
            <li>Audit logging for all changes</li>
            <li>Master data tables (Farms, Kiosk Devices, Biosecurity Rules, etc.)</li>
        </ul>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; margin-top: 2rem;">
        <div style="background: white; padding: 1.5rem; border-radius: 8px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1); text-align: center;">
            <h3 style="font-size: 28px; color: #667eea; margin-bottom: 0.5rem;">{{ \App\Models\FarmList::count() }}</h3>
            <p style="color: #666; font-size: 14px;">Farms</p>
        </div>
        <div style="background: white; padding: 1.5rem; border-radius: 8px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1); text-align: center;">
            <h3 style="font-size: 28px; color: #667eea; margin-bottom: 0.5rem;">{{ \App\Models\KioskDevice::count() }}</h3>
            <p style="color: #666; font-size: 14px;">Kiosk Devices</p>
        </div>
        <div style="background: white; padding: 1.5rem; border-radius: 8px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1); text-align: center;">
            <h3 style="font-size: 28px; color: #667eea; margin-bottom: 0.5rem;">{{ \App\Models\User::count() }}</h3>
            <p style="color: #666; font-size: 14px;">System Users</p>
        </div>
        <div style="background: white; padding: 1.5rem; border-radius: 8px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1); text-align: center;">
            <h3 style="font-size: 28px; color: #667eea; margin-bottom: 0.5rem;">{{ \App\Models\Role::count() }}</h3>
            <p style="color: #666; font-size: 14px;">Roles</p>
        </div>
    </div>
</div>
