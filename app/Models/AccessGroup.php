<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AccessGroup extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'id',
        'organization_id',
        'name',
        'code',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function devices(): BelongsToMany
    {
        return $this->belongsToMany(Device::class, 'access_group_device');
    }

    public function personnel(): BelongsToMany
    {
        return $this->belongsToMany(Personnel::class, 'access_group_personnel')
            ->withPivot('schedule_rule_id');
    }

    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'access_group_department');
    }
}
