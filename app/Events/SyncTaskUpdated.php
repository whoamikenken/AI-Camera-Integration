<?php

namespace App\Events;

use App\Models\SyncTask;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SyncTaskUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $broadcastQueue = 'broadcasts';

    public function __construct(
        public SyncTask $syncTask,
        public ?string $oldStatus = null
    ) {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('sync-tasks'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'SyncTaskUpdated';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->syncTask->id,
            'device_id' => $this->syncTask->device_id,
            'personnel_id' => $this->syncTask->personnel_id,
            'action' => $this->syncTask->action,
            'status' => $this->syncTask->status,
            'old_status' => $this->oldStatus,
            'attempts' => $this->syncTask->attempts,
            'error_message' => $this->syncTask->error_message,
        ];
    }
}
