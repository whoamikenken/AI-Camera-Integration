<?php

use App\Http\Controllers\AccessLogController;
use App\Http\Controllers\DashboardStatsController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\PersonnelController;
use App\Http\Controllers\StrangerSnapController;
use App\Http\Controllers\SyncTaskController;
use Illuminate\Support\Facades\Route;

// Dashboard Metrics
Route::get('/stats', [DashboardStatsController::class, 'index']);

// Device Management
Route::apiResource('devices', DeviceController::class);
Route::post('devices/{device}/test-connection', [DeviceController::class, 'testConnection']);
Route::post('devices/{device}/reboot', [DeviceController::class, 'reboot']);
Route::get('devices/{device}/sys-param', [DeviceController::class, 'getSysParam']);
Route::post('devices/{device}/sys-param', [DeviceController::class, 'setSysParam']);
Route::get('devices/{device}/mqtt-param', [DeviceController::class, 'getMqttParam']);
Route::post('devices/{device}/sync-mqtt', [DeviceController::class, 'syncMqtt']);
Route::post('devices/{device}/sync-time', [DeviceController::class, 'setSysTime']);
Route::post('devices/{device}/manual-push-records', [DeviceController::class, 'manualPushRecords']);
Route::post('devices/{device}/manual-push-snaps', [DeviceController::class, 'manualPushSnaps']);
Route::post('devices/{device}/factory-reset', [DeviceController::class, 'factoryReset']);
Route::post('devices/{device}/clear-face-database', [DeviceController::class, 'deleteAllPersons']);
Route::get('devices/{device}/search-camera-list', [DeviceController::class, 'searchCameraList']);

// Personnel / Face Library
Route::apiResource('personnel', PersonnelController::class);
Route::post('personnel/{personnel}', [PersonnelController::class, 'update']); // for multipart form updates
Route::post('personnel/{personnel}/sync-now', [PersonnelController::class, 'syncNow']);

// Verification & Stranger Logs
Route::get('access-logs', [AccessLogController::class, 'index']);
Route::get('access-logs/{accessLog}', [AccessLogController::class, 'show']);

Route::get('stranger-snaps', [StrangerSnapController::class, 'index']);
Route::get('stranger-snaps/{strangerSnap}', [StrangerSnapController::class, 'show']);

// Sync Tasks Outbox
Route::get('sync-tasks', [SyncTaskController::class, 'index']);
Route::post('sync-tasks/{syncTask}/retry', [SyncTaskController::class, 'retry']);
