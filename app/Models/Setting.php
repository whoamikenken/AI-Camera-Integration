<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Setting extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'organization_id',
        'group',
        'key',
        'value',
        'type',
        'description',
        'is_public',
    ];

    protected $casts = [
        'is_public' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function getCastedValueAttribute(): mixed
    {
        if ($this->value === null) {
            return null;
        }

        return match ($this->type) {
            'boolean', 'bool' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'integer', 'int' => (int) $this->value,
            'float', 'double' => (float) $this->value,
            'json', 'array' => json_decode($this->value, true),
            default => (string) $this->value,
        };
    }

    public function setCastedValueAttribute(mixed $val): void
    {
        if ($val === null) {
            $this->attributes['value'] = null;

            return;
        }

        $this->attributes['value'] = match ($this->type) {
            'boolean', 'bool' => $val ? '1' : '0',
            'json', 'array' => is_string($val) ? $val : json_encode($val),
            default => (string) $val,
        };
    }
}
