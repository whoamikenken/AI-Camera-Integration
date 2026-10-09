<?php

namespace App\Observers;

use App\Models\Device;
use Illuminate\Support\Facades\Cache;

class DeviceObserver
{
    /**
     * Handle the Device "saved" event.
     */
    public function saved(Device $device): void
    {
        Cache::put("device_registered:{$device->device_id}", (bool) $device->is_active, 600);

        if ($device->isDirty('device_id')) {
            $oldDeviceId = $device->getOriginal('device_id');
            if ($oldDeviceId) {
                Cache::forget("device_registered:{$oldDeviceId}");
            }
        }
    }

    /**
     * Handle the Device "deleted" event.
     */
    public function deleted(Device $device): void
    {
        Cache::forget("device_registered:{$device->device_id}");
    }
}
