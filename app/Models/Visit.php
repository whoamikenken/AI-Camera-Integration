<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Visit extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'id',
        'visitor_id',
        'host_employee_id',
        'personnel_id',
        'purpose',
        'purpose_detail',
        'expected_arrival',
        'check_in_time',
        'check_out_time',
        'badge_number',
        'nda_signed',
        'status',
    ];

    protected $casts = [
        'expected_arrival' => 'datetime',
        'check_in_time' => 'datetime',
        'check_out_time' => 'datetime',
        'nda_signed' => 'boolean',
    ];

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Visitor::class);
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'host_employee_id');
    }

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(Personnel::class);
    }
}
