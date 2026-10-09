<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
}, ['guards' => ['web', 'sanctum']]);

Broadcast::channel('access-logs', function ($user) {
    return $user->hasPermission(['attendance.view', 'devices.view']);
}, ['guards' => ['web', 'sanctum']]);

Broadcast::channel('device-alerts', function ($user) {
    return $user->hasRole('security') || $user->hasPermission(['devices.manage', 'devices.view']);
}, ['guards' => ['web', 'sanctum']]);

Broadcast::channel('alerts', function ($user) {
    return $user->hasRole('security') || $user->hasPermission(['devices.manage', 'devices.view']);
}, ['guards' => ['web', 'sanctum']]);

Broadcast::channel('stranger-snaps', function ($user) {
    return $user->hasRole('security') || $user->hasPermission(['devices.view']);
}, ['guards' => ['web', 'sanctum']]);

Broadcast::channel('device-status', function ($user) {
    return $user->hasPermission(['devices.view', 'devices.manage']);
}, ['guards' => ['web', 'sanctum']]);

Broadcast::channel('attendance', function ($user) {
    return $user->hasPermission(['attendance.view']);
}, ['guards' => ['web', 'sanctum']]);

Broadcast::channel('visitors', function ($user) {
    return $user->hasPermission(['visitors.view', 'visitors.manage']);
}, ['guards' => ['web', 'sanctum']]);

Broadcast::channel('personnel', function ($user) {
    return $user->hasPermission(['personnel.view', 'personnel.manage']);
}, ['guards' => ['web', 'sanctum']]);

Broadcast::channel('sync-tasks', function ($user) {
    return $user->hasPermission(['devices.manage', 'devices.view']);
}, ['guards' => ['web', 'sanctum']]);

Broadcast::channel('notifications.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
}, ['guards' => ['web', 'sanctum']]);

Broadcast::channel('device-commands', function ($user) {
    return $user->hasPermission(['devices.manage', 'devices.view']);
}, ['guards' => ['web', 'sanctum']]);


