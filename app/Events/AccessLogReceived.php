<?php

namespace App\Events;

use App\Models\AccessLog;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AccessLogReceived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public AccessLog $log)
    {
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('access-logs'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'AccessLogReceived';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->log->id,
            'device_id' => $this->log->device_id,
            'customize_id' => $this->log->customize_id,
            'person_name' => $this->log->person_name,
            'verify_status' => $this->log->verify_status,
            'verify_type' => $this->log->verify_type,
            'person_type' => $this->log->person_type,
            'similarity' => (float) $this->log->similarity,
            'snap_pic_url' => $this->log->snap_pic_url,
            'scene_pic_url' => $this->log->scene_pic_url,
            'target_pos' => $this->log->target_pos,
            'is_no_mask' => $this->log->is_no_mask,
            'captured_at' => $this->log->captured_at ? $this->log->captured_at->toISOString() : now()->toISOString(),
        ];
    }
}
