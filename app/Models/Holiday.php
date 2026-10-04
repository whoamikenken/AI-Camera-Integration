<?php

namespace App\Models;

use App\Traits\Auditable;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Holiday extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'date',
        'type',
        'is_recurring',
        'applies_to',
    ];

    protected $casts = [
        'date' => 'date',
        'is_recurring' => 'boolean',
        'applies_to' => 'array',
    ];

    public function isHolidayOn(Carbon|string $date): bool
    {
        $carbon = is_string($date) ? Carbon::parse($date) : $date->copy();
        $holidayDate = is_string($this->date) ? Carbon::parse($this->date) : $this->date;

        if ($holidayDate->format('Y-m-d') === $carbon->format('Y-m-d')) {
            return true;
        }

        if ($this->is_recurring && $holidayDate->format('m-d') === $carbon->format('m-d')) {
            return true;
        }

        return false;
    }

    public function appliesToEmployee(Employee $employee): bool
    {
        if ($this->organization_id && $employee->organization_id && $this->organization_id !== $employee->organization_id) {
            return false;
        }

        if (empty($this->applies_to)) {
            return true;
        }

        $applies = $this->applies_to;

        // Structured configuration: {"departments": [1, 2], "locations": [3]}
        if (isset($applies['departments']) && is_array($applies['departments'])) {
            if (!in_array($employee->department_id, $applies['departments'])) {
                return false;
            }
        }

        if (isset($applies['locations']) && is_array($applies['locations'])) {
            if (!in_array($employee->location_id, $applies['locations'])) {
                return false;
            }
        }

        // Direct list of department IDs: [1, 2, 3]
        if (array_is_list($applies) && !empty($applies)) {
            if (!in_array($employee->department_id, $applies)) {
                return false;
            }
        }

        return true;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
