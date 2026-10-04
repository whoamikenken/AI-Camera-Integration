<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PersonnelUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $action, // 'created', 'updated', 'deleted'
        public int $personType, // 0: Whitelist, 1: Blacklist
        public ?int $oldPersonType = null,
        public ?int $personnelId = null,
        public ?string $name = null
    ) {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('personnel'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'PersonnelUpdated';
    }

    public function broadcastWith(): array
    {
        return [
            'action' => $this->action,
            'person_type' => $this->personType,
            'old_person_type' => $this->oldPersonType,
            'personnel_id' => $this->personnelId,
            'name' => $this->name,
        ];
    }
}
