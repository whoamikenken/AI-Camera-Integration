<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceCommand extends Model
{
    use HasFactory;

    protected $table = 'device_commands';

    protected $fillable = [
        'device_id',
        'message_id',
        'operator',
        'status',
        'payload',
        'response',
        'error_message',
        'dispatched_at',
        'completed_at',
    ];

    protected $casts = [
        'device_id' => 'integer',
        'payload' => 'array',
        'response' => 'array',
        'dispatched_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function markCompleted(array $response): self
    {
        $this->update([
            'status' => 'completed',
            'response' => $response,
            'completed_at' => now(),
        ]);

        return $this;
    }

    public function markFailed(array|string $response, ?string $errorMessage = null): self
    {
        $resp = is_array($response) ? $response : null;
        $err = $errorMessage ?: (is_string($response) ? $response : ($response['desc'] ?? $response['error'] ?? 'Command execution failed'));

        $this->update([
            'status' => 'failed',
            'response' => $resp,
            'error_message' => $err,
            'completed_at' => now(),
        ]);

        return $this;
    }
}
