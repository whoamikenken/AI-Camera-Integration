<?php

namespace App\Events;

use App\Models\Visit;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class VisitorCheckedOut implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Visit $visit)
    {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('visitors'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'VisitorCheckedOut';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->visit->id,
            'visitor_id' => $this->visit->visitor_id,
            'visitor_name' => $this->visit->visitor ? "{$this->visit->visitor->first_name} {$this->visit->visitor->last_name}" : 'Unknown',
            'check_out_time' => $this->visit->check_out_time ? $this->visit->check_out_time->toISOString() : now()->toISOString(),
            'status' => $this->visit->status,
        ];
    }
}
