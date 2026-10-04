<?php

namespace App\Events;

use App\Models\DeviceAlert;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DeviceAlertReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;
    public string $broadcastQueue = 'broadcasts';

    public function __construct(public DeviceAlert $alert)
    {
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
        return 'DeviceAlertReceived';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->alert->id,
            'device_id' => $this->alert->device_id,
            'device_name' => $this->alert->device?->name ?? "Camera {$this->alert->device_id}",
            'alert_type' => $this->alert->alert_type,
            'operator' => $this->alert->operator,
            'severity' => $this->alert->severity,
            'title' => $this->alert->title,
            'description' => $this->alert->description,
            'snap_pic_url' => $this->alert->snap_pic_url,
            'scene_pic_url' => $this->alert->scene_pic_url,
            'video_url' => $this->alert->video_url,
            'details' => $this->alert->details,
            'status' => $this->alert->status,
            'captured_at' => $this->alert->captured_at ? $this->alert->captured_at->toISOString() : now()->toISOString(),
        ];
    }
}
