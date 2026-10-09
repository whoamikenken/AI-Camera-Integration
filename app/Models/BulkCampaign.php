<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BulkCampaign extends Model
{
    use HasFactory;

    protected $table = 'bulk_campaigns';

    protected $fillable = [
        'user_id',
        'campaign_type',
        'total_items',
        'processed_items',
        'failed_items',
        'status',
        'payload',
        'error_summary',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'total_items' => 'integer',
        'processed_items' => 'integer',
        'failed_items' => 'integer',
        'payload' => 'array',
    ];

    protected $appends = [
        'progress_percent',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getProgressPercentAttribute(): int
    {
        return $this->progressPercent();
    }

    public function progressPercent(): int
    {
        if ($this->total_items <= 0) {
            return 0;
        }

        $pct = (int) round(($this->processed_items / $this->total_items) * 100);

        return (int) min(100, max(0, $pct));
    }

    public function markProcessing(): self
    {
        $this->update(['status' => 'processing']);

        return $this;
    }

    public function incrementProcessed(int $count = 1): self
    {
        $this->processed_items += $count;
        $this->save();

        return $this;
    }

    public function incrementFailed(int $count = 1, ?string $error = null): self
    {
        $this->failed_items += $count;

        if ($error) {
            $existing = $this->error_summary ? $this->error_summary . "\n" : '';
            $this->error_summary = $existing . $error;
        }

        $this->save();

        return $this;
    }

    public function markCompleted(?string $finalStatus = null): self
    {
        if ($finalStatus) {
            $status = $finalStatus;
        } elseif ($this->failed_items > 0 && $this->processed_items === 0) {
            $status = 'failed';
        } elseif ($this->failed_items > 0 && $this->processed_items > 0) {
            $status = 'partial';
        } else {
            $status = 'completed';
        }

        $this->update(['status' => $status]);

        return $this;
    }

    public function markFailed(string $error): self
    {
        $existing = $this->error_summary ? $this->error_summary . "\n" : '';
        $this->update([
            'status' => 'failed',
            'error_summary' => $existing . $error,
        ]);

        return $this;
    }
}
