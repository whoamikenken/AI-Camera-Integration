<?php

namespace App\Events;

use App\Models\DeviceCommand;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DeviceCommandCompleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public ?string $connection = null;
    public string $broadcastQueue = 'broadcasts';

    public function __construct(public DeviceCommand $command)
    {
        $this->connection = app()->environment('testing') ? null : 'redis';
        $this->broadcastQueue = 'broadcasts';
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('device-commands'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'DeviceCommandCompleted';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->command->id,
            'device_id' => $this->command->device_id,
            'message_id' => $this->command->message_id,
            'operator' => $this->command->operator,
            'status' => $this->command->status,
            'payload' => $this->command->payload,
            'response' => $this->command->response,
            'error_message' => $this->command->error_message,
            'dispatched_at' => $this->command->dispatched_at?->toIso8601String(),
            'completed_at' => $this->command->completed_at?->toIso8601String(),
        ];
    }
}
