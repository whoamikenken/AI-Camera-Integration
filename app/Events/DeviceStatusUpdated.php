<?php

namespace App\Events;

use App\Models\Device;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DeviceStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;
    public string $broadcastQueue = 'broadcasts';

    public function __construct(public Device $device)
    {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('device-status'),
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
            'scheme' => $this->device->scheme ?: 'http',
            'endpoint_url' => $this->device->endpoint_url,
            'is_active' => $this->device->is_active,
            'is_online' => $this->device->is_online,
            'last_heartbeat_at' => $this->device->last_heartbeat_at ? $this->device->last_heartbeat_at->toISOString() : null,
            'access_logs_count' => $this->device->access_logs_count ?? 0,
            'stranger_snaps_count' => $this->device->stranger_snaps_count ?? 0,
        ];
    }
}
