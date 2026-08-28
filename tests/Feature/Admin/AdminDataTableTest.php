<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\EmployeeType;
use App\Models\IdentityType;
use App\Models\KioskDevice;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesFacilities;
use Tests\TestCase;

/**
 * Covers the admin Data Table migration (server-rendered rows -> Data Table
 * Shell + JSON data endpoint + jQuery rendering) for the modules that had no
 * prior admin CRUD test coverage: Kiosk Devices, Identity Types, Employee
 * Types, Roles, Users, Audit Logs. Facilities, Facility Aliases, Downtime
 * Matrix, Downtime Stationary, and Downtime Matrix Import are covered by
 * their own existing test files.
 */
class AdminDataTableTest extends TestCase
{
    use RefreshDatabase;
    use CreatesFacilities;

    private function actingAdminWith(string $permissionKey): User
    {
        $role = Role::create(['role_name' => 'Admin-' . $permissionKey]);
        $permission = Permission::create(['permission_key' => $permissionKey, 'permission_name' => $permissionKey]);
        $role->permissions()->attach($permission->permission_id);

        $user = User::create([
            'role_id' => $role->role_id,
            'user_name' => 'admin',
            'user_email' => 'admin+' . $permissionKey . '@example.com',
            'hash_password' => bcrypt('password'),
            'is_active' => true,
        ]);

        $this->actingAs($user);

        return $user;
    }

    // --- Kiosk Devices ---------------------------------------------------

    public function test_kiosks_index_is_an_empty_shell_and_data_endpoint_returns_the_record(): void
    {
        $this->actingAdminWith('kiosks.manage');
        $facility = $this->createFacility('ALPHA');
        KioskDevice::create([
            'facility_id' => $facility->facility_id,
            'device_name' => 'Gate Kiosk 1',
            'serial_number' => 'SN-001',
            'public_ip' => '10.0.0.5',
            'status' => 'online',
        ]);

        $shell = $this->get(route('kiosks.index'));
        $shell->assertOk();
        $shell->assertSee('id="kd-table"', false);
        $shell->assertSee('id="kd-filter-btn"', false);
        $shell->assertDontSee('Gate Kiosk 1');

        $data = $this->getJson(route('kiosks.data'));
        $data->assertOk();
        $data->assertJsonPath('data.0.device_name', 'Gate Kiosk 1');
        $data->assertJsonPath('data.0.facility_name', 'ALPHA');
        $data->assertJsonPath('data.0.serial_number', 'SN-001');
    }

    // --- Identity Types ----------------------------------------------------

    public function test_identity_types_index_is_an_empty_shell_and_data_endpoint_returns_the_record(): void
    {
        $this->actingAdminWith('identity_types.manage');
        IdentityType::create(['identity_type_name' => 'Contractor']);

        $shell = $this->get(route('identity-types.index'));
        $shell->assertOk();
        $shell->assertSee('id="it-table"', false);
        $shell->assertSee('id="it-filter-btn"', false);
        $shell->assertDontSee('Contractor');

        $data = $this->getJson(route('identity-types.data'));
        $data->assertOk();
        $data->assertJsonPath('data.0.identity_type_name', 'Contractor');
    }

    // --- Employee Types ------------------------------------------------

    public function test_employee_types_index_is_an_empty_shell_and_data_endpoint_returns_the_record(): void
    {
        $this->actingAdminWith('employee_types.manage');
        EmployeeType::create(['employee_type_name' => 'Full Time']);

        $shell = $this->get(route('employee-types.index'));
        $shell->assertOk();
        $shell->assertSee('id="et-table"', false);
        $shell->assertSee('id="et-filter-btn"', false);
        $shell->assertDontSee('Full Time');

        $data = $this->getJson(route('employee-types.data'));
        $data->assertOk();
        $data->assertJsonPath('data.0.employee_type_name', 'Full Time');
    }

    // --- Roles -------------------------------------------------------------

    public function test_roles_index_is_an_empty_shell_and_data_endpoint_returns_the_record(): void
    {
        $this->actingAdminWith('roles.manage');
        Role::create(['role_name' => 'Auditor', 'description' => 'Read only']);

        $shell = $this->get(route('roles.index'));
        $shell->assertOk();
        $shell->assertSee('id="rl-table"', false);
        $shell->assertSee('id="rl-filter-btn"', false);
        $shell->assertDontSee('Auditor');

        $data = $this->getJson(route('roles.data'));
        $data->assertOk();
        $roleRow = collect($data->json('data'))->firstWhere('role_name', 'Auditor');
        $this->assertNotNull($roleRow);
        $this->assertSame('Read only', $roleRow['description']);
    }

    // --- Users ---------------------------------------------------------

    public function test_users_index_is_an_empty_shell_and_data_endpoint_returns_the_record(): void
    {
        $admin = $this->actingAdminWith('users.manage');

        $shell = $this->get(route('users.index'));
        $shell->assertOk();
        $shell->assertSee('id="us-table"', false);
        $shell->assertSee('id="us-filter-btn"', false);
        $shell->assertDontSee($admin->user_email);

        $data = $this->getJson(route('users.data'));
        $data->assertOk();
        $userRow = collect($data->json('data'))->firstWhere('user_id', $admin->user_id);
        $this->assertNotNull($userRow);
        $this->assertSame($admin->user_email, $userRow['user_email']);
        $this->assertSame('Admin-users.manage', $userRow['role_name']);
    }

    // --- Audit Logs (read-only) -----------------------------------------

    public function test_audit_logs_index_is_an_empty_shell_and_data_endpoint_returns_the_record(): void
    {
        $admin = $this->actingAdminWith('audit_logs.view');
        AuditLog::create([
            'user_id' => $admin->user_id,
            'action' => 'created',
            'module' => 'FacilityList',
            'record_id' => 1,
            'created_at' => now(),
        ]);

        $shell = $this->get(route('audit-logs.index'));
        $shell->assertOk();
        $shell->assertSee('id="al-table"', false);
        $shell->assertSee('id="al-filter-btn"', false);
        // The Module filter dropdown legitimately lists "FacilityList" as a
        // selectable option (it's a distinct-value lookup, same reasoning as
        // the Facility dropdown elsewhere) - what must NOT appear is the
        // actual log row, i.e. an empty <tbody>.
        $shell->assertSee('<tbody></tbody>', false);

        $data = $this->getJson(route('audit-logs.data'));
        $data->assertOk();
        // actingAdminWith() itself creates Role/Permission/User rows, each
        // Auditable, so several audit_log rows exist before this test's own
        // explicit entry - find it by module rather than assuming index 0.
        $logRow = collect($data->json('data'))->firstWhere('module', 'FacilityList');
        $this->assertNotNull($logRow);
        $this->assertSame('created', $logRow['action']);
        $this->assertSame('admin', $logRow['user_name']);
    }

    // --- Cross-cutting: every data endpoint enforces its permission -----

    public function test_data_endpoints_require_their_module_permission(): void
    {
        $role = Role::create(['role_name' => 'NoPermissions']);
        $user = User::create([
            'role_id' => $role->role_id,
            'user_name' => 'nobody',
            'user_email' => 'nobody@example.com',
            'hash_password' => bcrypt('password'),
            'is_active' => true,
        ]);
        $this->actingAs($user);

        $this->getJson(route('kiosks.data'))->assertForbidden();
        $this->getJson(route('identity-types.data'))->assertForbidden();
        $this->getJson(route('employee-types.data'))->assertForbidden();
        $this->getJson(route('roles.data'))->assertForbidden();
        $this->getJson(route('users.data'))->assertForbidden();
        $this->getJson(route('audit-logs.data'))->assertForbidden();
    }
}
