<?php

namespace App\Events;

use App\Models\Visit;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class VisitorCheckedIn implements ShouldBroadcastNow
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
        return 'VisitorCheckedIn';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->visit->id,
            'visitor_id' => $this->visit->visitor_id,
            'visitor_name' => $this->visit->visitor ? "{$this->visit->visitor->first_name} {$this->visit->visitor->last_name}" : 'Unknown',
            'visitor_company' => $this->visit->visitor?->company,
            'host_name' => $this->visit->hostEmployee?->personnel?->name ?? 'N/A',
            'purpose' => $this->visit->purpose,
            'badge_number' => $this->visit->badge_number,
            'check_in_time' => $this->visit->check_in_time ? $this->visit->check_in_time->toISOString() : now()->toISOString(),
            'status' => $this->visit->status,
        ];
    }
}
