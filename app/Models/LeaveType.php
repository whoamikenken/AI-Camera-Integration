<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveType extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'id',
        'organization_id',
        'name',
        'code',
        'max_days_per_year',
        'is_paid',
        'is_carry_forward',
        'max_carry_forward_days',
        'description',
        'is_active',
    ];

    protected $casts = [
        'max_days_per_year' => 'float',
        'is_paid' => 'boolean',
        'is_carry_forward' => 'boolean',
        'max_carry_forward_days' => 'float',
        'is_active' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function balances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }
}
