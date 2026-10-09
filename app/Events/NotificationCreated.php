<?php

namespace App\Events;

use App\Models\Notification;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotificationCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;
    public string $broadcastQueue = 'broadcasts';

    public function __construct(public mixed $notification)
    {
    }

    public function broadcastOn(): array
    {
        $userId = is_array($this->notification)
            ? ($this->notification['user_id'] ?? $this->notification['notifiable_id'] ?? null)
            : ($this->notification->user_id ?? $this->notification->notifiable_id ?? null);

        return [
            new PrivateChannel('notifications.' . $userId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'NotificationCreated';
    }

    public function broadcastWith(): array
    {
        $notif = is_array($this->notification) ? (object) $this->notification : $this->notification;
        $readAt = $notif->read_at ?? null;
        if ($readAt instanceof \DateTimeInterface) {
            $readAt = $readAt->toISOString();
        }
        $createdAt = $notif->created_at ?? null;
        if ($createdAt instanceof \DateTimeInterface) {
            $createdAt = $createdAt->toISOString();
        }

        return [
            'id' => $notif->id ?? null,
            'user_id' => $notif->user_id ?? $notif->notifiable_id ?? null,
            'type' => $notif->type ?? 'info',
            'title' => $notif->title ?? '',
            'message' => $notif->message ?? '',
            'data' => $notif->data ?? null,
            'read_at' => $readAt,
            'created_at' => $createdAt ?: now()->toISOString(),
        ];
    }
}
