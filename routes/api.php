<?php

use App\Http\Controllers\AccessLogController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardStatsController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\HolidayController;
use App\Http\Controllers\HttpWebhookController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\PayrollExportController;
use App\Http\Controllers\PersonnelController;
use App\Http\Controllers\RegularizationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\StrangerSnapController;
use App\Http\Controllers\SyncTaskController;
use App\Http\Controllers\VisitorController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tier 1: Hardware Webhooks (UNAUTHENTICATED)
|--------------------------------------------------------------------------
| AI Cameras push real-time events without HTTP Bearer tokens.
*/
Route::middleware(['throttle:60,1'])->group(function () {
    Route::post('/Subscribe/heartbeat', [HttpWebhookController::class, 'handleHeartbeat']);
    Route::post('/Subscribe/Verify', [HttpWebhookController::class, 'handleVerify']);
    Route::post('/Subscribe/Snap', [HttpWebhookController::class, 'handleSnap']);
});

/*
|--------------------------------------------------------------------------
| Tier 2: Public Endpoints (UNAUTHENTICATED)
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
});

Route::get('settings/public', [SettingController::class, 'publicSettings']);

/*
|--------------------------------------------------------------------------
| Tier 3: Guarded Domain Endpoints (AUTHENTICATED via auth:sanctum & active)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function () {

    // Current user session & profile
    Route::prefix('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'profile']);
        Route::get('/profile', [AuthController::class, 'profile']);
        Route::get('/user', [AuthController::class, 'profile']);
        Route::put('/profile', [AuthController::class, 'updateProfile']);
        Route::put('/password', [AuthController::class, 'changePassword']);
        Route::get('/permissions', [AuthController::class, 'permissions']);
    });

    // Dashboard Metrics
    Route::get('/stats', [DashboardStatsController::class, 'index']);

    // RBAC Administration
    Route::apiResource('roles', RoleController::class)->middleware('permission:roles.manage');
    Route::get('permissions', [RoleController::class, 'permissions'])->middleware('permission:roles.manage');

    // Organization Hierarchy
    Route::get('organizations', [OrganizationController::class, 'index'])->middleware('permission:organizations.view,organizations.manage');
    Route::get('organizations/{organization}', [OrganizationController::class, 'show'])->middleware('permission:organizations.view,organizations.manage');
    Route::post('organizations', [OrganizationController::class, 'store'])->middleware('permission:organizations.manage');
    Route::put('organizations/{organization}', [OrganizationController::class, 'update'])->middleware('permission:organizations.manage');
    Route::delete('organizations/{organization}', [OrganizationController::class, 'destroy'])->middleware('permission:organizations.manage');

    // Locations
    Route::get('locations', [OrganizationController::class, 'listLocations'])->middleware('permission:organizations.view,organizations.manage');
    Route::post('locations', [OrganizationController::class, 'storeLocation'])->middleware('permission:organizations.manage');
    Route::get('locations/{location}', [OrganizationController::class, 'showLocation'])->middleware('permission:organizations.view,organizations.manage');
    Route::put('locations/{location}', [OrganizationController::class, 'updateLocation'])->middleware('permission:organizations.manage');
    Route::delete('locations/{location}', [OrganizationController::class, 'destroyLocation'])->middleware('permission:organizations.manage');

    // Departments
    Route::get('departments/tree', [OrganizationController::class, 'departmentTree'])->middleware('permission:organizations.view,organizations.manage');
    Route::get('departments', [OrganizationController::class, 'listDepartments'])->middleware('permission:organizations.view,organizations.manage');
    Route::post('departments', [OrganizationController::class, 'storeDepartment'])->middleware('permission:organizations.manage');
    Route::get('departments/{department}', [OrganizationController::class, 'showDepartment'])->middleware('permission:organizations.view,organizations.manage');
    Route::put('departments/{department}', [OrganizationController::class, 'updateDepartment'])->middleware('permission:organizations.manage');
    Route::delete('departments/{department}', [OrganizationController::class, 'destroyDepartment'])->middleware('permission:organizations.manage');

    // Designations
    Route::get('designations', [OrganizationController::class, 'listDesignations'])->middleware('permission:organizations.view,organizations.manage');
    Route::post('designations', [OrganizationController::class, 'storeDesignation'])->middleware('permission:organizations.manage');
    Route::get('designations/{designation}', [OrganizationController::class, 'showDesignation'])->middleware('permission:organizations.view,organizations.manage');
    Route::put('designations/{designation}', [OrganizationController::class, 'updateDesignation'])->middleware('permission:organizations.manage');
    Route::delete('designations/{designation}', [OrganizationController::class, 'destroyDesignation'])->middleware('permission:organizations.manage');

    // Global Settings
    Route::get('settings', [SettingController::class, 'index'])->middleware('permission:settings.view,settings.manage');
    Route::put('settings', [SettingController::class, 'update'])->middleware('permission:settings.manage');
    Route::put('settings/bulk', [SettingController::class, 'update'])->middleware('permission:settings.manage');
    Route::post('settings/bulk', [SettingController::class, 'update'])->middleware('permission:settings.manage');
    Route::post('settings/reset', [SettingController::class, 'reset'])->middleware('permission:settings.manage');

    // Audit Trail
    Route::get('audit-logs', [SettingController::class, 'auditLogs'])->middleware('permission:settings.view,settings.manage');
    Route::get('audit-logs/{auditLog}', [SettingController::class, 'showAuditLog'])->middleware('permission:settings.view,settings.manage');

    // Device Management
    Route::get('devices', [DeviceController::class, 'index'])->middleware('permission:devices.view,devices.manage');
    Route::post('devices', [DeviceController::class, 'store'])->middleware('permission:devices.manage');
    Route::get('devices/{device}', [DeviceController::class, 'show'])->middleware('permission:devices.view,devices.manage');
    Route::put('devices/{device}', [DeviceController::class, 'update'])->middleware('permission:devices.manage');
    Route::delete('devices/{device}', [DeviceController::class, 'destroy'])->middleware('permission:devices.delete');

    Route::post('devices/{device}/test-connection', [DeviceController::class, 'testConnection'])->middleware('permission:devices.manage');
    Route::post('devices/{device}/reboot', [DeviceController::class, 'reboot'])->middleware('permission:devices.manage');
    Route::get('devices/{device}/sys-param', [DeviceController::class, 'getSysParam'])->middleware('permission:devices.manage');
    Route::post('devices/{device}/sys-param', [DeviceController::class, 'setSysParam'])->middleware('permission:devices.manage');
    Route::get('devices/{device}/mqtt-param', [DeviceController::class, 'getMqttParam'])->middleware('permission:devices.manage');
    Route::post('devices/{device}/sync-mqtt', [DeviceController::class, 'syncMqtt'])->middleware('permission:devices.manage');
    Route::post('devices/{device}/sync-time', [DeviceController::class, 'setSysTime'])->middleware('permission:devices.manage');
    Route::post('devices/{device}/manual-push-records', [DeviceController::class, 'manualPushRecords'])->middleware('permission:devices.manage');
    Route::post('devices/{device}/manual-push-snaps', [DeviceController::class, 'manualPushSnaps'])->middleware('permission:devices.manage');
    Route::post('devices/{device}/factory-reset', [DeviceController::class, 'factoryReset'])->middleware('permission:devices.manage');
    Route::post('devices/{device}/clear-face-database', [DeviceController::class, 'deleteAllPersons'])->middleware('permission:devices.manage');
    Route::post('devices/{device}/delete-person', [DeviceController::class, 'deletePerson'])->middleware('permission:devices.manage');
    Route::post('devices/{device}/import-personnel', [DeviceController::class, 'importPersonnel'])->middleware('permission:devices.manage');
    Route::get('devices/{device}/search-camera-list', [DeviceController::class, 'searchCameraList'])->middleware('permission:devices.manage');
    Route::post('devices/{device}/subscribe', [DeviceController::class, 'subscribe'])->middleware('permission:devices.manage');
    Route::post('devices/{device}/unsubscribe', [DeviceController::class, 'unsubscribe'])->middleware('permission:devices.manage');
    Route::get('devices/{device}/subscribe', [DeviceController::class, 'getSubscribe'])->middleware('permission:devices.manage');
    Route::get('devices/{device}/device-info', [DeviceController::class, 'getDeviceInformation'])->middleware('permission:devices.manage');
    Route::post('devices/{device}/search-person', [DeviceController::class, 'searchPerson'])->middleware('permission:devices.manage');
    Route::get('devices/{device}/search-person-num', [DeviceController::class, 'searchPersonNum'])->middleware('permission:devices.manage');
    Route::get('devices/{device}/handshake-data', [DeviceController::class, 'getHandSharkData'])->middleware('permission:devices.manage');
    Route::post('devices/{device}/handshake-data', [DeviceController::class, 'setHandSharkData'])->middleware('permission:devices.manage');
    Route::get('devices/{device}/flow-count', [DeviceController::class, 'getCount'])->middleware('permission:devices.manage');
    Route::post('devices/{device}/upgrade', [DeviceController::class, 'upgradeFirmware'])->middleware('permission:devices.manage');
    Route::get('devices/{device}/audit', [DeviceController::class, 'audit'])->middleware('permission:devices.view,devices.audit,devices.manage');

    // Personnel / Face Library
    Route::get('personnel', [PersonnelController::class, 'index'])->middleware('permission:personnel.view');
    Route::post('personnel', [PersonnelController::class, 'store'])->middleware('permission:personnel.create');
    Route::get('personnel/{personnel}', [PersonnelController::class, 'show'])->middleware('permission:personnel.view');
    Route::put('personnel/{personnel}', [PersonnelController::class, 'update'])->middleware('permission:personnel.edit');
    Route::post('personnel/{personnel}', [PersonnelController::class, 'update'])->middleware('permission:personnel.edit');
    Route::delete('personnel/{personnel}', [PersonnelController::class, 'destroy'])->middleware('permission:personnel.delete');
    Route::post('personnel/{personnel}/sync-now', [PersonnelController::class, 'syncNow'])->middleware('permission:personnel.sync');
    Route::post('personnel/{personnel}/convert-to-employee', [PersonnelController::class, 'convertToEmployee'])->middleware('permission:personnel.edit,employees.create');

    // Verification & Stranger Logs
    Route::get('access-logs', [AccessLogController::class, 'index']);
    Route::get('access-logs/{accessLog}', [AccessLogController::class, 'show']);

    Route::get('stranger-snaps', [StrangerSnapController::class, 'index']);
    Route::get('stranger-snaps/{strangerSnap}', [StrangerSnapController::class, 'show']);

    // AI Safety & Security Device Alerts
    Route::get('device-alerts', [\App\Http\Controllers\DeviceAlertController::class, 'index'])
        ->middleware('permission:devices.view,devices.manage');
    Route::get('device-alerts/stats', [\App\Http\Controllers\DeviceAlertController::class, 'stats'])
        ->middleware('permission:devices.view,devices.manage');
    Route::get('device-alerts/{deviceAlert}', [\App\Http\Controllers\DeviceAlertController::class, 'show'])
        ->middleware('permission:devices.view,devices.manage');
    Route::patch('device-alerts/{deviceAlert}/status', [\App\Http\Controllers\DeviceAlertController::class, 'updateStatus'])
        ->middleware('permission:devices.manage');
    Route::post('device-alerts/bulk-status', [\App\Http\Controllers\DeviceAlertController::class, 'bulkUpdateStatus'])
        ->middleware('permission:devices.manage');

    // Employees & Directory
    Route::get('employees', [EmployeeController::class, 'index'])
        ->middleware('permission:employees.view,employees.manage');
    Route::post('employees', [EmployeeController::class, 'store'])
        ->middleware('permission:employees.create,employees.manage');
    Route::get('employees/export', [EmployeeController::class, 'export'])
        ->middleware('permission:employees.export,employees.view,employees.manage');
    Route::post('employees/import', [EmployeeController::class, 'import'])
        ->middleware('permission:employees.import,employees.create,employees.manage');
    Route::get('employees/{id}', [EmployeeController::class, 'show'])
        ->middleware('permission:employees.view,employees.manage');
    Route::put('employees/{id}', [EmployeeController::class, 'update'])
        ->middleware('permission:employees.edit,employees.manage');
    Route::delete('employees/{id}', [EmployeeController::class, 'destroy'])
        ->middleware('permission:employees.delete,employees.manage');
    Route::get('employees/{id}/attendance-summary', [EmployeeController::class, 'attendanceSummary'])
        ->middleware('permission:employees.view,attendance.view,selfservice.view,employees.manage');
    Route::post('employees/{id}/assign-shift', [EmployeeController::class, 'assignShift'])
        ->middleware('permission:shifts.manage,schedules.manage,employees.manage');
    Route::post('employees/{id}/shift-assignments', [EmployeeController::class, 'assignShift'])
        ->middleware('permission:shifts.manage,schedules.manage,employees.manage');

    // Shifts & Schedules
    Route::get('shifts', [ShiftController::class, 'index'])
        ->middleware('permission:schedules.view,schedules.manage,shifts.view,shifts.manage');
    Route::post('shifts', [ShiftController::class, 'store'])
        ->middleware('permission:schedules.manage,shifts.manage');
    Route::post('shifts/bulk-assign', [ShiftController::class, 'bulkAssign'])
        ->middleware('permission:schedules.manage,shifts.manage');
    Route::get('shifts/{id}', [ShiftController::class, 'show'])
        ->middleware('permission:schedules.view,schedules.manage,shifts.view,shifts.manage');
    Route::put('shifts/{id}', [ShiftController::class, 'update'])
        ->middleware('permission:schedules.manage,shifts.manage');
    Route::delete('shifts/{id}', [ShiftController::class, 'destroy'])
        ->middleware('permission:schedules.manage,shifts.manage');
    Route::post('shifts/{id}/assign', [ShiftController::class, 'assign'])
        ->middleware('permission:schedules.manage,shifts.manage');

    // Holiday Calendar
    Route::get('holidays', [HolidayController::class, 'index'])
        ->middleware('permission:schedules.view,schedules.manage,shifts.view,shifts.manage');
    Route::post('holidays', [HolidayController::class, 'store'])
        ->middleware('permission:schedules.manage,shifts.manage');
    Route::get('holidays/{id}', [HolidayController::class, 'show'])
        ->middleware('permission:schedules.view,schedules.manage,shifts.view,shifts.manage');
    Route::put('holidays/{id}', [HolidayController::class, 'update'])
        ->middleware('permission:schedules.manage,shifts.manage');
    Route::delete('holidays/{id}', [HolidayController::class, 'destroy'])
        ->middleware('permission:schedules.manage,shifts.manage');

    // Attendance Processing & Records
    Route::get('attendance/daily', [AttendanceController::class, 'daily'])->middleware('permission:attendance.view');
    Route::get('attendance/records', [AttendanceController::class, 'records'])->middleware('permission:attendance.view');
    Route::get('attendance/punches', [AttendanceController::class, 'punches'])->middleware('permission:attendance.view');
    Route::post('attendance/manual-entry', [AttendanceController::class, 'manualEntry'])->middleware('permission:attendance.manage');
    Route::put('attendance/{id}/override', [AttendanceController::class, 'override'])->middleware('permission:attendance.manage');
    Route::post('attendance/finalize-daily', [AttendanceController::class, 'finalizeDaily'])->middleware('permission:attendance.manage');

    // Leave Management
    Route::get('leave-types', [LeaveController::class, 'listLeaveTypes'])->middleware('permission:leaves.view');
    Route::post('leave-types', [LeaveController::class, 'storeLeaveType'])->middleware('permission:leaves.manage');
    Route::get('leave-types/{id}', [LeaveController::class, 'showLeaveType'])->middleware('permission:leaves.view');
    Route::put('leave-types/{id}', [LeaveController::class, 'updateLeaveType'])->middleware('permission:leaves.manage');
    Route::delete('leave-types/{id}', [LeaveController::class, 'destroyLeaveType'])->middleware('permission:leaves.manage');

    Route::get('leave-balances', [LeaveController::class, 'listBalances'])->middleware('permission:leaves.view');
    Route::post('leave-balances/allocate', [LeaveController::class, 'allocateBalance'])->middleware('permission:leaves.manage');

    Route::get('leave-requests', [LeaveController::class, 'listRequests'])->middleware('permission:leaves.view');
    Route::post('leave-requests', [LeaveController::class, 'storeRequest'])->middleware('permission:leaves.apply,leaves.manage');
    Route::put('leave-requests/{id}/approve', [LeaveController::class, 'approveRequest'])->middleware('permission:leaves.approve,leaves.manage');
    Route::put('leave-requests/{id}/reject', [LeaveController::class, 'rejectRequest'])->middleware('permission:leaves.approve,leaves.manage');

    // Attendance Regularization (Self-Service & Manager Approvals)
    Route::get('regularization-requests', [RegularizationController::class, 'index'])->middleware('permission:selfservice.view,attendance.view');
    Route::post('regularization-requests', [RegularizationController::class, 'store'])->middleware('permission:selfservice.view,attendance.view');
    Route::put('regularization-requests/{id}/approve', [RegularizationController::class, 'approve'])->middleware('permission:attendance.approve,attendance.manage');
    Route::put('regularization-requests/{id}/reject', [RegularizationController::class, 'reject'])->middleware('permission:attendance.approve,attendance.manage');

    // Visitor Management System
    Route::get('visitors', [VisitorController::class, 'index'])->middleware('permission:visitors.view');
    Route::post('visitors', [VisitorController::class, 'store'])->middleware('permission:visitors.manage,visitors.checkin');
    Route::get('visitors/{id}', [VisitorController::class, 'show'])->middleware('permission:visitors.view');
    Route::put('visitors/{id}', [VisitorController::class, 'update'])->middleware('permission:visitors.manage');
    Route::post('visitors/{id}/block', [VisitorController::class, 'block'])->middleware('permission:visitors.manage');

    Route::get('visits', [VisitorController::class, 'listVisits'])->middleware('permission:visitors.view');
    Route::post('visits', [VisitorController::class, 'preRegister'])->middleware('permission:visitors.preregister,visitors.checkin,visitors.manage');
    Route::post('visits/pre-register', [VisitorController::class, 'preRegister'])->middleware('permission:visitors.preregister,visitors.checkin,visitors.manage');
    Route::get('visits/{id}', [VisitorController::class, 'showVisit'])->middleware('permission:visitors.view');
    Route::put('visits/{id}/check-in', [VisitorController::class, 'checkIn'])->middleware('permission:visitors.checkin,visitors.manage');
    Route::put('visits/{id}/check-out', [VisitorController::class, 'checkOut'])->middleware('permission:visitors.checkout,visitors.manage');

    // In-App Notifications
    Route::get('notifications', [NotificationController::class, 'index'])->middleware('permission:selfservice.view,attendance.view');
    Route::put('notifications/{id}/read', [NotificationController::class, 'markAsRead'])->middleware('permission:selfservice.view,attendance.view');
    Route::post('notifications/mark-all-read', [NotificationController::class, 'markAllAsRead'])->middleware('permission:selfservice.view,attendance.view');

    // Attendance & Analytical Reports
    Route::get('reports/attendance/daily', [ReportController::class, 'dailyAttendance'])->middleware('permission:reports.view');
    Route::get('reports/attendance/monthly', [ReportController::class, 'monthlyAttendance'])->middleware('permission:reports.view');
    Route::get('reports/export', [ReportController::class, 'export'])->middleware('permission:reports.export,reports.view');

    // Payroll Export
    Route::get('payroll/export', [PayrollExportController::class, 'export'])->middleware('permission:reports.payroll,reports.view');

    // Secure media retrieval for biometric photos & surveillance captures (SEC-11)
    Route::get('media/{path}', function (\Illuminate\Http\Request $request, string $path, \App\Services\ImageStorageService $storage) {
        $media = $storage->getMedia($path);
        if (!$media) {
            abort(404, 'Media not found.');
        }
        return response($media['content'], 200, [
            'Content-Type' => $media['mime_type'],
            'Cache-Control' => 'private, no-cache, no-store, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    })->where('path', '.*')->middleware('permission:personnel.view,devices.view,attendance.view,visitors.view');

    // Sync Tasks Outbox
    Route::get('sync-tasks', [SyncTaskController::class, 'index']);
    Route::post('sync-tasks/{syncTask}/retry', [SyncTaskController::class, 'retry'])->middleware('permission:personnel.sync,devices.manage');
});
