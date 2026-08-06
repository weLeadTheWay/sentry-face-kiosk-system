<?php

namespace Tests\Feature\Kiosk;

use App\Models\FarmList;
use App\Models\KioskDevice;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KioskDeviceAdminTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): User
    {
        $role = Role::create(['role_name' => 'Admin']);
        $permission = Permission::create(['permission_key' => 'kiosks.manage', 'permission_name' => 'Manage Kiosks']);
        $role->permissions()->attach($permission->permission_id);

        $user = User::create([
            'role_id' => $role->role_id,
            'user_name' => 'admin',
            'user_email' => 'admin@example.com',
            'hash_password' => bcrypt('password'),
            'is_active' => true,
        ]);

        $this->actingAs($user);

        return $user;
    }

    public function test_regenerating_token_invalidates_the_old_one(): void
    {
        $this->actingAdmin();
        $farm = FarmList::create(['farm_code' => 'ALPHA', 'farm_name' => 'ALPHA']);
        $kiosk = KioskDevice::create([
            'farm_id' => $farm->farm_id,
            'device_name' => 'Test Kiosk',
            'serial_number' => 'SN-' . uniqid(),
        ]);
        $oldToken = $kiosk->kiosk_token;

        $response = $this->post(route('kiosks.regenerate-token', $kiosk));
        $response->assertOk();

        $kiosk->refresh();
        $this->assertNotEquals($oldToken, $kiosk->kiosk_token);

        // Old token must no longer authenticate against kiosk endpoints.
        $recognizeWithOldToken = $this->postJson("/kiosk/{$kiosk->kiosk_id}/recognize", [
            'descriptor' => array_fill(0, 128, 0.1),
        ], ['X-KIOSK-TOKEN' => $oldToken]);
        $recognizeWithOldToken->assertStatus(401);

        // New token authenticates successfully.
        $recognizeWithNewToken = $this->postJson("/kiosk/{$kiosk->kiosk_id}/recognize", [
            'descriptor' => array_fill(0, 128, 0.1),
        ], ['X-KIOSK-TOKEN' => $kiosk->kiosk_token]);
        $this->assertNotEquals(401, $recognizeWithNewToken->getStatusCode());
    }
}
