<?php

namespace App\Events;

use App\Models\StrangerSnap;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StrangerSnapReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;
    public string $broadcastQueue = 'broadcasts';

    public function __construct(public StrangerSnap $snap)
    {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('stranger-snaps'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'StrangerSnapReceived';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->snap->id,
            'device_id' => $this->snap->device_id,
            'snap_id' => $this->snap->snap_id,
            'snap_pic_url' => $this->snap->snap_pic_url,
            'scene_pic_url' => $this->snap->scene_pic_url,
            'target_pos' => $this->snap->target_pos,
            'is_no_mask' => $this->snap->is_no_mask,
            'alarm_action' => $this->snap->alarm_action,
            'captured_at' => $this->snap->captured_at ? $this->snap->captured_at->toISOString() : now()->toISOString(),
        ];
    }
}
