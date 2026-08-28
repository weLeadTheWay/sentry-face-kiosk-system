<?php

use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check() ? redirect()->route('dashboard') : redirect()->route('login');
});

Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'store'])->name('login.store');
Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

Route::get('/register/visitor', [\App\Http\Controllers\Visitor\RegistrationController::class, 'show'])->name('visitor.register');
Route::get('/register/visitor/search', [\App\Http\Controllers\Visitor\RegistrationController::class, 'showSearch'])->name('visitor.search');
Route::get('/register/visitor/search/query', [\App\Http\Controllers\Visitor\RegistrationController::class, 'searchName'])->name('visitor.search.query');
Route::get('/register/visitor/capture', [\App\Http\Controllers\Visitor\RegistrationController::class, 'showCapture'])->name('visitor.capture.show');
Route::post('/register/visitor/capture', [\App\Http\Controllers\Visitor\RegistrationController::class, 'captureFace'])->name('visitor.capture');
Route::post('/register/visitor/verify', [\App\Http\Controllers\Visitor\RegistrationController::class, 'verifyFace'])->name('visitor.verify');
Route::post('/register/visitor/confirm', [\App\Http\Controllers\Visitor\RegistrationController::class, 'confirmMatch'])->name('visitor.confirm');
Route::get('/register/visitor/success', [\App\Http\Controllers\Visitor\RegistrationController::class, 'success'])->name('visitor.success');
Route::get('/register/visitor/qr', [\App\Http\Controllers\Visitor\RegistrationController::class, 'qrCode'])->name('visitor.qr');

Route::get('/kiosk/{kiosk}', [\App\Http\Controllers\Kiosk\KioskController::class, 'show'])->name('kiosk.show');
Route::middleware('kiosk.auth')->group(function () {
    Route::get('/kiosk/{kiosk}/verify-token', [\App\Http\Controllers\Kiosk\KioskController::class, 'verifyToken'])->name('kiosk.verify-token');
    Route::post('/kiosk/{kiosk}/recognize', [\App\Http\Controllers\Kiosk\KioskController::class, 'recognize'])->name('kiosk.recognize');
    Route::post('/kiosk/{kiosk}/entry', [\App\Http\Controllers\Kiosk\KioskController::class, 'entry'])->name('kiosk.entry');
    Route::post('/kiosk/{kiosk}/gatesale/update-details', [\App\Http\Controllers\Kiosk\KioskController::class, 'gatesaleUpdateDetails'])->name('kiosk.gatesale.update-details');
    Route::post('/kiosk/{kiosk}/gatesale/create-visit', [\App\Http\Controllers\Kiosk\KioskController::class, 'gatesaleCreateVisit'])->name('kiosk.gatesale.create-visit');
    Route::post('/kiosk/{kiosk}/gatesale/register-identity', [\App\Http\Controllers\Kiosk\KioskController::class, 'gatesaleRegisterIdentity'])->name('kiosk.gatesale.register-identity');
});

Route::middleware('auth')->group(function () {
    Route::get('/admin/dashboard', function () {
        if (request()->ajax()) {
            return view('admin.dashboard-content');
        }
        return view('admin.dashboard');
    })->name('dashboard');

    // Data Table JSON endpoints are registered before their matching
    // Route::resource() calls: a resource's implicit "show" route
    // (GET admin/{module}/{id}) would otherwise swallow "admin/{module}/data"
    // as if "data" were the {id} parameter, since Laravel matches routes in
    // registration order. Each resource below also drops 'show' explicitly
    // (no controller implements it - it was already dead/unused) as a second,
    // order-independent guard against that same collision.
    Route::get('admin/kiosks/data', [\App\Http\Controllers\Admin\KioskDeviceController::class, 'data'])->name('kiosks.data')->middleware('permission:kiosks.manage');
    Route::resource('admin/kiosks', \App\Http\Controllers\Admin\KioskDeviceController::class)->except('show')->middleware('permission:kiosks.manage');
    Route::post('admin/kiosks/{kiosk}/regenerate-token', [\App\Http\Controllers\Admin\KioskDeviceController::class, 'regenerateToken'])->name('kiosks.regenerate-token')->middleware('permission:kiosks.manage');

    Route::get('admin/identity-types/data', [\App\Http\Controllers\Admin\IdentityTypeController::class, 'data'])->name('identity-types.data')->middleware('permission:identity_types.manage');
    Route::resource('admin/identity-types', \App\Http\Controllers\Admin\IdentityTypeController::class)->except('show')->middleware('permission:identity_types.manage')->parameter('identity_type', 'identity_type');

    Route::get('admin/employee-types/data', [\App\Http\Controllers\Admin\EmployeeTypeController::class, 'data'])->name('employee-types.data')->middleware('permission:employee_types.manage');
    Route::resource('admin/employee-types', \App\Http\Controllers\Admin\EmployeeTypeController::class)->except('show')->middleware('permission:employee_types.manage')->parameter('employee_type', 'employee_type');

    Route::get('admin/biosecurity-rules', [\App\Http\Controllers\Admin\BiosecurityRuleController::class, 'index'])->name('biosecurity-rules.index')->middleware('permission:biosecurity.manage,downtime_matrix_import.manage');

    Route::get('admin/biosecurity-rules/downtime-matrix/data', [\App\Http\Controllers\Admin\DowntimeMatrixController::class, 'data'])->name('downtime-matrix.data')->middleware('permission:biosecurity.manage');
    Route::resource('admin/biosecurity-rules/downtime-matrix', \App\Http\Controllers\Admin\DowntimeMatrixController::class)->except('show')->middleware('permission:biosecurity.manage')->parameter('downtime_matrix', 'downtime_matrix');

    Route::get('admin/biosecurity-rules/downtime-stationary/data', [\App\Http\Controllers\Admin\DowntimeStationaryController::class, 'data'])->name('downtime-stationary.data')->middleware('permission:biosecurity.manage');
    Route::resource('admin/biosecurity-rules/downtime-stationary', \App\Http\Controllers\Admin\DowntimeStationaryController::class)->except('show')->middleware('permission:biosecurity.manage')->parameter('downtime_stationary', 'downtime_stationary');

    Route::middleware('permission:downtime_matrix_import.manage')->group(function () {
        Route::get('admin/biosecurity-rules/downtime-matrix-import/data', [\App\Http\Controllers\Admin\DowntimeMatrixImportController::class, 'data'])->name('downtime-matrix-import.data');
        Route::get('admin/biosecurity-rules/downtime-matrix-import', [\App\Http\Controllers\Admin\DowntimeMatrixImportController::class, 'index'])->name('downtime-matrix-import.index');
        Route::get('admin/biosecurity-rules/downtime-matrix-import/create', [\App\Http\Controllers\Admin\DowntimeMatrixImportController::class, 'create'])->name('downtime-matrix-import.create');
        Route::post('admin/biosecurity-rules/downtime-matrix-import', [\App\Http\Controllers\Admin\DowntimeMatrixImportController::class, 'store'])->name('downtime-matrix-import.store');
        Route::get('admin/biosecurity-rules/downtime-matrix-import/{downtime_matrix_import}', [\App\Http\Controllers\Admin\DowntimeMatrixImportController::class, 'show'])->name('downtime-matrix-import.show');
        Route::get('admin/biosecurity-rules/downtime-matrix-import/{downtime_matrix_import}/rows-data', [\App\Http\Controllers\Admin\DowntimeMatrixImportController::class, 'rowsData'])->name('downtime-matrix-import.rows-data');
        Route::post('admin/biosecurity-rules/downtime-matrix-import/{downtime_matrix_import}/verify', [\App\Http\Controllers\Admin\DowntimeMatrixImportController::class, 'verify'])->name('downtime-matrix-import.verify');
        Route::post('admin/biosecurity-rules/downtime-matrix-import/{downtime_matrix_import}/cancel', [\App\Http\Controllers\Admin\DowntimeMatrixImportController::class, 'cancel'])->name('downtime-matrix-import.cancel');
    });

    Route::get('admin/roles/data', [\App\Http\Controllers\Admin\RoleController::class, 'data'])->name('roles.data')->middleware('permission:roles.manage');
    Route::resource('admin/roles', \App\Http\Controllers\Admin\RoleController::class)->except('show')->middleware('permission:roles.manage');
    Route::get('admin/roles/{role}/permissions', [\App\Http\Controllers\Admin\RoleController::class, 'permissions'])->name('roles.permissions')->middleware('permission:roles.manage');
    Route::post('admin/roles/{role}/permissions', [\App\Http\Controllers\Admin\RoleController::class, 'updatePermissions'])->name('roles.updatePermissions')->middleware('permission:roles.manage');

    Route::get('admin/users/data', [\App\Http\Controllers\Admin\UserController::class, 'data'])->name('users.data')->middleware('permission:users.manage');
    Route::resource('admin/users', \App\Http\Controllers\Admin\UserController::class)->except('show')->middleware('permission:users.manage');

    Route::get('admin/audit-logs/data', [\App\Http\Controllers\Admin\AuditLogController::class, 'data'])->name('audit-logs.data')->middleware('permission:audit_logs.view');
    Route::resource('admin/audit-logs', \App\Http\Controllers\Admin\AuditLogController::class)->middleware('permission:audit_logs.view')->only('index');

    Route::get('admin/facilities/data', [\App\Http\Controllers\Admin\FacilityController::class, 'data'])->name('facilities.data')->middleware('permission:facilities.manage');
    Route::resource('admin/facilities', \App\Http\Controllers\Admin\FacilityController::class)->except('show')->middleware('permission:facilities.manage');

    Route::get('admin/facility-aliases/data', [\App\Http\Controllers\Admin\FacilityAliasController::class, 'data'])->name('facility-aliases.data')->middleware('permission:facilities.manage');
    Route::resource('admin/facility-aliases', \App\Http\Controllers\Admin\FacilityAliasController::class)->except('show')->middleware('permission:facilities.manage')->parameter('facility_alias', 'facility_alias');
});
