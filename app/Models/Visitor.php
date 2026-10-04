<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Visitor extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'id',
        'organization_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'company',
        'id_type',
        'id_number',
        'photo_path',
        'is_blocked',
        'block_reason',
    ];

    protected $casts = [
        'is_blocked' => 'boolean',
    ];

    protected $appends = [
        'name',
    ];

    public function getNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }
}
