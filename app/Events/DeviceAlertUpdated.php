<?php

namespace App\Events;

use App\Models\DeviceAlert;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DeviceAlertUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;
    public string $broadcastQueue = 'broadcasts';

    public function __construct(
        public DeviceAlert $alert,
        public ?string $previousStatus = null
    ) {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('device-alerts'),
            new PrivateChannel('alerts'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'DeviceAlertUpdated';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->alert->id,
            'device_id' => $this->alert->device_id,
            'status' => $this->alert->status,
            'previous_status' => $this->previousStatus,
            'severity' => $this->alert->severity,
            'alert_type' => $this->alert->alert_type,
            'resolved_at' => $this->alert->resolved_at ? $this->alert->resolved_at->toISOString() : null,
            'resolved_by' => $this->alert->resolved_by,
        ];
    }
}
