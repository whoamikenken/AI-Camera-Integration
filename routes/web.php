<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Mock Camera Hardware API Endpoint for local testing & development
Route::post('/action/{operator}', function (string $operator, Request $request) {
    $input = $request->all();
    $info = $input['info'] ?? [];
    $deviceId = $info['DeviceID'] ?? 'DEV-MOCK-001';

    return response()->json([
        'operator' => $operator,
        'code' => 200,
        'info' => [
            'Result' => 'Ok',
            'DeviceID' => $deviceId,
            'Name' => 'Simulated Edge AI Camera',
            'Version' => 'v4.2.1-mock',
            'DeviceType' => 0,
            'MQEnable' => 1,
            'MQAddr' => '127.0.0.1',
            'MQPort' => 1883,
            'MQTopic' => "mqtt/face/{$deviceId}",
            'Detail' => 'Mock hardware camera endpoint responded successfully',
        ],
    ]);
});

// Camera HTTP Webhook Event Push Endpoints (HTTP Protocol V1.13 Section 3)
Route::middleware(['throttle:60,1'])->group(function () {
    Route::post('/Subscribe/heartbeat', [\App\Http\Controllers\HttpWebhookController::class, 'handleHeartbeat']);
    Route::post('/Subscribe/Verify', [\App\Http\Controllers\HttpWebhookController::class, 'handleVerify']);
    Route::post('/Subscribe/Snap', [\App\Http\Controllers\HttpWebhookController::class, 'handleSnap']);
});
