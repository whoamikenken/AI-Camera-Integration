<?php

namespace App\Events;

use App\Models\Device;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DeviceStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Device $device)
    {
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('device-status'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'DeviceStatusUpdated';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->device->id,
            'device_id' => $this->device->device_id,
            'name' => $this->device->name,
            'ip_address' => $this->device->ip_address,
            'port' => $this->device->port,
            'is_active' => $this->device->is_active,
            'is_online' => $this->device->is_online,
            'last_heartbeat_at' => $this->device->last_heartbeat_at ? $this->device->last_heartbeat_at->toISOString() : null,
        ];
    }
}
