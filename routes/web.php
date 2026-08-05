<?php

use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check() ? redirect()->route('dashboard') : redirect()->route('login');
});

Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'store'])->name('login.store');
Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/admin/dashboard', function () {
        if (request()->ajax()) {
            return view('admin.dashboard-content');
        }
        return view('admin.dashboard');
    })->name('dashboard');

    Route::resource('admin/farms', \App\Http\Controllers\Admin\FarmController::class)->middleware('permission:farms.manage');
    Route::resource('admin/kiosks', \App\Http\Controllers\Admin\KioskDeviceController::class)->middleware('permission:kiosks.manage')->parameter('kiosk', 'kiosk_device');
    Route::resource('admin/identity-types', \App\Http\Controllers\Admin\IdentityTypeController::class)->middleware('permission:identity_types.manage')->parameter('identity_type', 'identity_type');
    Route::resource('admin/employee-types', \App\Http\Controllers\Admin\EmployeeTypeController::class)->middleware('permission:employee_types.manage')->parameter('employee_type', 'employee_type');
    Route::resource('admin/biosecurity-rules', \App\Http\Controllers\Admin\BiosecurityRuleController::class)->middleware('permission:biosecurity.manage')->parameter('biosecurity_rule', 'biosecurity_rule');
    Route::resource('admin/roles', \App\Http\Controllers\Admin\RoleController::class)->middleware('permission:roles.manage');
    Route::get('admin/roles/{role}/permissions', [\App\Http\Controllers\Admin\RoleController::class, 'permissions'])->name('roles.permissions')->middleware('permission:roles.manage');
    Route::post('admin/roles/{role}/permissions', [\App\Http\Controllers\Admin\RoleController::class, 'updatePermissions'])->name('roles.updatePermissions')->middleware('permission:roles.manage');
    Route::resource('admin/users', \App\Http\Controllers\Admin\UserController::class)->middleware('permission:users.manage');
    Route::resource('admin/audit-logs', \App\Http\Controllers\Admin\AuditLogController::class)->middleware('permission:audit_logs.view')->only('index');
    Route::resource('admin/farm-aliases', \App\Http\Controllers\Admin\FarmAliasController::class)->middleware('permission:farms.manage')->parameter('farm_alias', 'farm_alias');
});
