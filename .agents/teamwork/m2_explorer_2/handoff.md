# Milestone 2: Shift & Schedule Management (Phase 3) — Technical Implementation Blueprint

## 1. Observation

Direct observations from the repository codebase, tests, and specification files:

1. **Existing Migrations**:
   - `database/migrations/2026_09_30_000013_create_shifts_table.php`:
     Defines `id`, `organization_id`, `name`, `code` (with simple `$table->string('code', 64)->index()`), `shift_start` (TIME default '09:00:00'), `shift_end` (TIME default '18:00:00'), `grace_period_minutes` (default 15), `early_out_threshold_minutes` (default 30), `half_day_threshold_hours` (4,2 default 4.0), `min_hours_full_day` (4,2 default 8.0), `is_overnight` (default false), `break_duration_minutes` (default 60), `is_flexible` (default false), `color` (default '#3B82F6'), `is_active` (default true), and timestamps.
     *Observation*: To enforce "code unique per organization", the table should have `$table->unique(['organization_id', 'code'])` or composite unique handling.
   - `database/migrations/2026_09_30_000015_create_employee_shift_assignments_table.php`:
     Defines `id`, `employee_id` (FK to `employees`, cascade delete), `shift_id` (FK to `shifts`, cascade delete), `effective_from` (DATE), `effective_to` (DATE, nullable), `assigned_days` (JSON, nullable), `created_by` (FK to `users`, null on delete), timestamps.
   - `database/migrations/2026_09_30_000016_create_holidays_table.php`:
     Defines `id`, `organization_id` (nullable FK to `organizations`), `name` (string 128), `date` (DATE index), `type` (string 32 default 'public'), `is_recurring` (boolean default false), `applies_to` (JSON nullable), timestamps.

2. **Existing Models**:
   - `app/Models/Shift.php`:
     Contains fillables and basic casts (`grace_period_minutes`, `early_out_threshold_minutes`, `half_day_threshold_hours`, `min_hours_full_day`, `is_overnight`, `break_duration_minutes`, `is_flexible`, `is_active`).
     *Missing*: Helper methods `durationMinutes()`, `isDayShift()`, `isOvernight()`, `crossesMidnight()`, and accessor aliases `start_time` / `end_time` required by `PROJECT.md` line 125 for Milestone 3 attendance engine compatibility.
   - `app/Models/EmployeeShiftAssignment.php`:
     Contains relationships `employee()`, `shift()`, `creator()` (`User::class`).
     *Missing*: Scopes for active/current assignments, helper method `appliesToDay(Carbon $date)` supporting both numeric ISO days (`1` to `7`) and string days (`['monday', 'tuesday', ...]`).
   - `app/Models/Holiday.php`:
     Contains relationship `organization()`.
     *Missing*: Helper methods `appliesToEmployee(Employee $employee)` and `isHolidayOn(Carbon $date)`.
   - `app/Models/Employee.php`:
     Has relationships `shift()`, `shiftAssignments()`.
     *Missing*: Contract helper methods required by `PROJECT.md` §Interface Contracts (lines 118–126): `currentShift(?Carbon $date)`, `isHoliday(Carbon $date)`, and `isRestDay(Carbon $date)`.
   - `app/Models/Organization.php`:
     Has relationships for locations, departments, designations, devices, users.
     *Missing*: `shifts(): HasMany`, `holidays(): HasMany`, `employees(): HasMany`.

3. **Existing Controllers & Routes**:
   - `app/Http/Controllers/ShiftController.php`:
     Implements `index`, `store`, `show`, `update`, `destroy`, and `assign(Request $request, int $id)`.
     *Missing*: `POST /api/shifts/bulk-assign` endpoint accepting either `employee_ids` or `department_ids`.
   - `app/Http/Controllers/HolidayController.php`:
     Implements `index` (with `year`, `organization_id`, `type` filters), `store`, `show`, `update`, `destroy`.
   - `routes/api.php` lines 165–179:
     Currently uses `shifts.view` and `shifts.manage`.
     *Missing*: Permissions `schedules.view` and `schedules.manage` alongside `shifts.view` and `shifts.manage`, route for `POST /api/shifts/bulk-assign`, and alias route `POST /api/employees/{id}/shift-assignments`.
   - `database/seeders/RolesAndPermissionsSeeder.php`:
     Contains `shifts.view` and `shifts.manage` in lines 80–81, but lacks `schedules.view` and `schedules.manage`.
     *Missing*: Default shift seeder `database/seeders/ShiftSeeder.php` for Standard Day Shift, Night Shift, and Flexible Shift.

4. **Test Suite Status**:
   - `php artisan test --filter=Tier1FeatureCoverageTest` passes 100% (25 passed, 27 skipped pending M3–M6).
     Section 2 tests all pass:
     - `test_m2_employee_can_be_created_and_linked_to_personnel` (Line 331)
     - `test_m2_employee_directory_can_be_filtered_by_department` (Line 355)
     - `test_m2_employee_soft_delete_preserves_historical_integrity` (Line 366)
     - `test_m2_shift_can_be_defined_with_grace_period_and_breaks` (Line 387)
     - `test_m2_overnight_shift_can_be_defined_crossing_midnight` (Line 415)
     - `test_m2_shift_can_be_assigned_to_employee_with_effective_dates` (Line 436)
     - `test_m2_holiday_calendar_supports_public_and_company_holidays` (Line 445)
   - `Tier2BoundaryTest.php`:
     - `test_boundary_shift_with_zero_break_minutes` (Line 147) requires `grace_period_minutes = 0` and `break_duration_minutes = 0` to be valid.
     - `test_boundary_rejection_of_invalid_shift_start_and_end_times` (Line 173) asserts that `$shift_start === $shift_end` when `!$is_overnight` returns 422 Unprocessable Entity.
   - `EmployeeAndShiftManagementTest.php`:
     - `test_shift_definition_and_batch_assignment` (Line 160) validates `POST /api/shifts/{id}/assign` with string assigned days (`['monday', 'tuesday', ...]`).
     - `test_holiday_crud_and_filtering` (Line 211) validates holiday lifecycle with year filtering.
   - Entire test suite: `php artisan test` runs 186 tests, 150 passed, 0 failures, 36 skipped awaiting M3–M6.

---

## 2. Logic Chain

1. **Schema & Code Uniqueness**:
   - Observation: Requirement 1 specifies `code` (unique per org). In single-tenant and multi-tenant setups, an organization may have standard codes like `DAY-01` that can be duplicated across distinct organizations, but must be unique within an organization.
   - Deductions:
     - In migration: `$table->unique(['organization_id', 'code'])`.
     - In validation: `Rule::unique('shifts', 'code')->where('organization_id', $request->organization_id)->ignore($shift?->id)`.
     - For default global shifts where `organization_id` is null: `whereNull('organization_id')`.

2. **Shift Model Calculation & Midnight Crossing**:
   - Observation: Shifts can be standard daytime (e.g. 09:00 to 18:00), overnight crossing midnight (e.g. 22:00 to 07:00), or flexible.
   - Deductions:
     - `crossesMidnight()` returns true if `is_overnight` is set OR `shift_end < shift_start`.
     - `isOvernight()` returns true if `is_overnight` is true or `crossesMidnight()` is true.
     - `isDayShift()` returns true if neither overnight nor flexible.
     - `durationMinutes(bool $netOfBreak = false)`:
       Calculates difference between start and end. If crossing midnight, adds 24 hours to end time. If `$netOfBreak` is true, subtracts `break_duration_minutes`. If flexible, returns `min_hours_full_day * 60`.
     - Accessors `getStartTimeAttribute()` and `getEndTimeAttribute()` guarantee that downstream attendance processing (`PROJECT.md` line 125) can read either `$shift->start_time` or `$shift->shift_start`.

3. **Shift Assignment, Rotation, and Rest Day Logic**:
   - Observation: An employee can have multiple shift assignments representing schedule changes over time.
   - Deductions:
     - When assigning a new shift with `effective_from`, any previous open-ended assignment (`effective_to IS NULL`) should be capped at `effective_from - 1 day` to ensure chronological continuity without overlap.
     - `Employee::currentShift($date)` resolves the active assignment on that date by checking `effective_from <= $date` and (`effective_to IS NULL OR effective_to >= $date`), falling back to `employees.shift_id`, then organization default shift.
     - `Employee::isRestDay($date)` inspects the active assignment's `assigned_days`. If `assigned_days` is null, it falls back to standard Mon-Fri (rest days = Sat/Sun: ISO days 6 & 7). Supports both integer representations (`[1, 2, 3, 4, 5]`) and string names (`['monday', 'tuesday', ...]`).

4. **Holiday Resolution & Department Scoping**:
   - Observation: Holidays can be public, company-wide, or department/location specific (`applies_to`). They can also recur annually (`is_recurring`).
   - Deductions:
     - `Holiday::isHolidayOn($date)` checks if date matches `Y-m-d` OR (if `is_recurring`) matches `m-d`.
     - `Holiday::appliesToEmployee($employee)` returns true if `applies_to` is empty, or if `$employee->department_id` or `$employee->location_id` matches the configured constraints.
     - `Employee::isHoliday($date)` queries holidays matching the organization and calls `isHolidayOn` and `appliesToEmployee`.

5. **API Design & RBAC Alignment**:
   - Observation: Mission requires `POST /api/shifts/bulk-assign` and wiring RBAC permissions `schedules.view` and `schedules.manage`.
   - Deductions:
     - `POST /api/shifts/bulk-assign` takes `shift_id`, `employee_ids` (optional array), `department_ids` (optional array), `effective_from`, `effective_to`, `assigned_days`. If `department_ids` are supplied, all active employees in those departments are resolved and combined with `employee_ids`.
     - Route permissions: specify `permission:schedules.view,schedules.manage,shifts.view,shifts.manage` for reads and `permission:schedules.manage,shifts.manage` for mutations.
     - Seed both `schedules.view` and `schedules.manage` in `RolesAndPermissionsSeeder.php` assigned to `super-admin`, `admin`, and `hr-manager`.

---

## 3. Caveats

1. **Composite Unique Index on Nullable Column**:
   In standard SQL (PostgreSQL and SQLite), multiple rows with `organization_id = NULL` and the same `code` are permitted because `NULL != NULL` in standard SQL unique indexes. Validation in `ShiftController` handles this explicitly using `whereNull('organization_id')` to enforce unique codes even for global shifts.
2. **Day of Week Numbering Conventions**:
   ISO-8601 defines Monday as 1 and Sunday as 7. Some external systems use 0 for Sunday. To prevent ambiguity, the implementation explicitly supports both ISO integer days (1 = Monday through 7 = Sunday) and case-insensitive day string names (`'monday'`, `'tuesday'`, etc.).
3. **Time Formats**:
   HTML time inputs typically send `HH:mm` (e.g. `09:00`), whereas database time columns return `HH:mm:ss` (e.g. `09:00:00`). Validation regex `/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/` and Carbon normalization accept both cleanly.

---

## 4. Conclusion & Technical Implementation Blueprint

The technical implementation is divided into 6 modular components ready for immediate deployment.

### Blueprint Component 1: `shifts` Migration & Model

#### 1.1 Database Migration: `database/migrations/2026_09_30_000013_create_shifts_table.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('name', 128);
            $table->string('code', 64)->index();
            $table->time('shift_start')->default('09:00:00');
            $table->time('shift_end')->default('18:00:00');
            $table->integer('grace_period_minutes')->default(15);
            $table->integer('early_out_threshold_minutes')->default(30);
            $table->decimal('half_day_threshold_hours', 4, 2)->default(4.0);
            $table->decimal('min_hours_full_day', 4, 2)->default(8.0);
            $table->boolean('is_overnight')->default(false);
            $table->integer('break_duration_minutes')->default(60);
            $table->boolean('is_flexible')->default(false);
            $table->string('color', 32)->default('#3B82F6');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
```

#### 1.2 Model: `app/Models/Shift.php`
```php
<?php

namespace App\Models;

use App\Traits\Auditable;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'shift_start',
        'shift_end',
        'grace_period_minutes',
        'early_out_threshold_minutes',
        'half_day_threshold_hours',
        'min_hours_full_day',
        'is_overnight',
        'break_duration_minutes',
        'is_flexible',
        'color',
        'is_active',
    ];

    protected $casts = [
        'grace_period_minutes' => 'integer',
        'early_out_threshold_minutes' => 'integer',
        'half_day_threshold_hours' => 'float',
        'min_hours_full_day' => 'float',
        'is_overnight' => 'boolean',
        'break_duration_minutes' => 'integer',
        'is_flexible' => 'boolean',
        'is_active' => 'boolean',
    ];

    // Compatibility accessors for M3 interface contract
    public function getStartTimeAttribute(): ?string
    {
        return $this->shift_start;
    }

    public function getEndTimeAttribute(): ?string
    {
        return $this->shift_end;
    }

    // Helper Methods
    public function crossesMidnight(): bool
    {
        if ($this->is_overnight) {
            return true;
        }
        if (!$this->shift_start || !$this->shift_end) {
            return false;
        }
        return Carbon::parse($this->shift_end)->lessThan(Carbon::parse($this->shift_start));
    }

    public function isOvernight(): bool
    {
        return (bool) $this->is_overnight || $this->crossesMidnight();
    }

    public function isDayShift(): bool
    {
        return !$this->isOvernight() && !$this->is_flexible;
    }

    public function durationMinutes(bool $netOfBreak = false): int
    {
        if ($this->is_flexible && (!$this->shift_start || !$this->shift_end)) {
            $totalMin = (int) (($this->min_hours_full_day ?? 8.0) * 60);
            return $netOfBreak ? max(0, $totalMin - ($this->break_duration_minutes ?? 0)) : $totalMin;
        }

        $start = Carbon::parse($this->shift_start);
        $end = Carbon::parse($this->shift_end);

        if ($this->crossesMidnight() || $end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }

        $grossMinutes = (int) $start->diffInMinutes($end);

        return $netOfBreak
            ? max(0, $grossMinutes - ($this->break_duration_minutes ?? 0))
            : $grossMinutes;
    }

    // Relationships
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function shiftAssignments(): HasMany
    {
        return $this->hasMany(EmployeeShiftAssignment::class);
    }
}
```

---

### Blueprint Component 2: `employee_shift_assignments` Migration & Model

#### 2.1 Database Migration: `database/migrations/2026_09_30_000015_create_employee_shift_assignments_table.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_shift_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained('shifts')->cascadeOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->json('assigned_days')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'effective_from', 'effective_to'], 'emp_shift_effective_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_shift_assignments');
    }
};
```

#### 2.2 Model: `app/Models/EmployeeShiftAssignment.php`
```php
<?php

namespace App\Models;

use App\Traits\Auditable;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeShiftAssignment extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'employee_id',
        'shift_id',
        'effective_from',
        'effective_to',
        'assigned_days',
        'created_by',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'assigned_days' => 'array',
    ];

    // Helper to test if assignment applies to a specific day of week
    public function appliesToDay(Carbon|string $date): bool
    {
        $carbon = is_string($date) ? Carbon::parse($date) : $date->copy();
        $dayIso = $carbon->dayOfWeekIso; // 1 (Mon) - 7 (Sun)
        $dayName = strtolower($carbon->format('l')); // 'monday', ...
        $dayShort = strtolower($carbon->format('D')); // 'mon', ...

        if (empty($this->assigned_days)) {
            return in_array($dayIso, [1, 2, 3, 4, 5], true);
        }

        foreach ($this->assigned_days as $d) {
            if (is_numeric($d) && (int) $d === $dayIso) {
                return true;
            }
            if (is_string($d)) {
                $dl = strtolower($d);
                if ($dl === $dayName || $dl === $dayShort) {
                    return true;
                }
            }
        }

        return false;
    }

    // Scopes
    public function scopeActiveOn(Builder $query, Carbon|string $date): Builder
    {
        $dateStr = is_string($date) ? $date : $date->toDateString();

        return $query->where('effective_from', '<=', $dateStr)
            ->where(function ($q) use ($dateStr) {
                $q->whereNull('effective_to')
                  ->orWhere('effective_to', '>=', $dateStr);
            });
    }

    // Relationships
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
```

---

### Blueprint Component 3: `holidays` Migration & Model

#### 3.1 Database Migration: `database/migrations/2026_09_30_000016_create_holidays_table.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('name', 128);
            $table->date('date')->index();
            $table->string('type', 32)->default('public'); // public, company, optional
            $table->boolean('is_recurring')->default(false);
            $table->json('applies_to')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
```

#### 3.2 Model: `app/Models/Holiday.php`
```php
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
```

#### 3.3 Employee Model Additions: `app/Models/Employee.php`
Add the following methods to `Employee.php` to complete the M2 ↔ M3 interface contract:
```php
    public function currentShift(Carbon|string|null $date = null): ?Shift
    {
        $dateStr = $date ? Carbon::parse($date)->toDateString() : Carbon::today()->toDateString();

        $assignment = $this->shiftAssignments()
            ->where('effective_from', '<=', $dateStr)
            ->where(function ($query) use ($dateStr) {
                $query->whereNull('effective_to')
                      ->orWhere('effective_to', '>=', $dateStr);
            })
            ->orderBy('effective_from', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        if ($assignment && $assignment->shift) {
            return $assignment->shift;
        }

        if ($this->shift) {
            return $this->shift;
        }

        if ($this->organization_id) {
            return Shift::where('organization_id', $this->organization_id)
                ->where('is_active', true)
                ->first();
        }

        return Shift::where('is_active', true)->first();
    }

    public function isHoliday(Carbon|string $date): bool
    {
        $carbon = is_string($date) ? Carbon::parse($date) : $date->copy();

        $holidays = Holiday::query()
            ->where(function ($query) {
                if ($this->organization_id) {
                    $query->where('organization_id', $this->organization_id)
                          ->orWhereNull('organization_id');
                }
            })
            ->get();

        foreach ($holidays as $holiday) {
            if ($holiday->isHolidayOn($carbon) && $holiday->appliesToEmployee($this)) {
                return true;
            }
        }

        return false;
    }

    public function isRestDay(Carbon|string $date): bool
    {
        $carbon = is_string($date) ? Carbon::parse($date) : $date->copy();
        $dateStr = $carbon->toDateString();
        $dayIso = $carbon->dayOfWeekIso; // 1 (Mon) - 7 (Sun)
        $dayName = strtolower($carbon->format('l'));

        $assignment = $this->shiftAssignments()
            ->where('effective_from', '<=', $dateStr)
            ->where(function ($query) use ($dateStr) {
                $query->whereNull('effective_to')
                      ->orWhere('effective_to', '>=', $dateStr);
            })
            ->orderBy('effective_from', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        $days = $assignment?->assigned_days;

        if (empty($days)) {
            // Default standard business week: Saturday (6) and Sunday (7) are rest days
            return in_array($dayIso, [6, 7], true);
        }

        foreach ($days as $d) {
            if (is_numeric($d) && (int) $d === $dayIso) {
                return false; // Scheduled work day
            }
            if (is_string($d) && strtolower($d) === $dayName) {
                return false; // Scheduled work day
            }
        }

        return true; // Not in assigned days -> Rest day
    }
```

---

### Blueprint Component 4: Controllers & Routes

#### 4.1 `ShiftController.php` Updates
Incorporate `bulkAssign()` and multi-tenant code uniqueness:
```php
<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\Shift;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ShiftController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Shift::with(['organization'])->withCount('employees');

        if ($request->filled('organization_id')) {
            $query->where('organization_id', $request->query('organization_id'));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        return response()->json($query->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'organization_id' => 'nullable|exists:organizations,id',
            'name' => 'required|string|max:128',
            'code' => [
                'required',
                'string',
                'max:64',
                Rule::unique('shifts')->where(function ($query) use ($request) {
                    $orgId = $request->input('organization_id');
                    return $orgId ? $query->where('organization_id', $orgId) : $query->whereNull('organization_id');
                }),
            ],
            'shift_start' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/'],
            'shift_end' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/'],
            'grace_period_minutes' => 'nullable|integer|min:0',
            'early_out_threshold_minutes' => 'nullable|integer|min:0',
            'half_day_threshold_hours' => 'nullable|numeric|min:0',
            'min_hours_full_day' => 'nullable|numeric|min:0',
            'is_overnight' => 'nullable|boolean',
            'break_duration_minutes' => 'nullable|integer|min:0',
            'is_flexible' => 'nullable|boolean',
            'color' => 'nullable|string|max:32',
            'is_active' => 'nullable|boolean',
        ]);

        $isOvernight = !empty($validated['is_overnight']);
        $startNorm = strlen($validated['shift_start']) === 5 ? $validated['shift_start'] . ':00' : $validated['shift_start'];
        $endNorm = strlen($validated['shift_end']) === 5 ? $validated['shift_end'] . ':00' : $validated['shift_end'];

        if (!$isOvernight && $startNorm === $endNorm) {
            throw ValidationException::withMessages([
                'shift_end' => ['Shift start time and end time cannot be identical for non-overnight shifts.'],
            ]);
        }

        $validated['shift_start'] = $startNorm;
        $validated['shift_end'] = $endNorm;

        $shift = Shift::create($validated);

        return response()->json([
            'message' => 'Shift created successfully.',
            'data' => $shift,
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $shift = Shift::with(['organization', 'employees'])->findOrFail($id);

        return response()->json(['data' => $shift]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $shift = Shift::findOrFail($id);

        $validated = $request->validate([
            'organization_id' => 'nullable|exists:organizations,id',
            'name' => 'sometimes|required|string|max:128',
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:64',
                Rule::unique('shifts')->where(function ($query) use ($request, $shift) {
                    $orgId = $request->input('organization_id', $shift->organization_id);
                    return $orgId ? $query->where('organization_id', $orgId) : $query->whereNull('organization_id');
                })->ignore($shift->id),
            ],
            'shift_start' => ['sometimes', 'required', 'regex:/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/'],
            'shift_end' => ['sometimes', 'required', 'regex:/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/'],
            'grace_period_minutes' => 'nullable|integer|min:0',
            'early_out_threshold_minutes' => 'nullable|integer|min:0',
            'half_day_threshold_hours' => 'nullable|numeric|min:0',
            'min_hours_full_day' => 'nullable|numeric|min:0',
            'is_overnight' => 'nullable|boolean',
            'break_duration_minutes' => 'nullable|integer|min:0',
            'is_flexible' => 'nullable|boolean',
            'color' => 'nullable|string|max:32',
            'is_active' => 'nullable|boolean',
        ]);

        $start = $validated['shift_start'] ?? $shift->shift_start;
        $end = $validated['shift_end'] ?? $shift->shift_end;
        $isOvernight = isset($validated['is_overnight']) ? (bool) $validated['is_overnight'] : (bool) $shift->is_overnight;

        $startNorm = strlen($start) === 5 ? $start . ':00' : $start;
        $endNorm = strlen($end) === 5 ? $end . ':00' : $end;

        if (!$isOvernight && $startNorm === $endNorm) {
            throw ValidationException::withMessages([
                'shift_end' => ['Shift start time and end time cannot be identical for non-overnight shifts.'],
            ]);
        }

        if (isset($validated['shift_start'])) {
            $validated['shift_start'] = $startNorm;
        }
        if (isset($validated['shift_end'])) {
            $validated['shift_end'] = $endNorm;
        }

        $shift->update($validated);

        return response()->json([
            'message' => 'Shift updated successfully.',
            'data' => $shift,
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $shift = Shift::findOrFail($id);
        $shift->delete();

        return response()->json([
            'message' => 'Shift deleted successfully.',
        ]);
    }

    // Direct assignment to specific employee IDs
    public function assign(Request $request, int $id): JsonResponse
    {
        $shift = Shift::findOrFail($id);

        $validated = $request->validate([
            'employee_ids' => 'required|array',
            'employee_ids.*' => 'exists:employees,id',
            'effective_from' => 'required|date',
            'effective_to' => 'nullable|date|after_or_equal:effective_from',
            'assigned_days' => 'nullable|array',
        ]);

        $assignments = $this->performShiftAssignment(
            $shift,
            $validated['employee_ids'],
            $validated['effective_from'],
            $validated['effective_to'] ?? null,
            $validated['assigned_days'] ?? null,
            $request->user()?->id
        );

        return response()->json([
            'message' => 'Shift assigned to ' . count($assignments) . ' employees.',
            'data' => $assignments,
        ], 201);
    }

    // Bulk assign endpoint supporting employees or entire departments
    public function bulkAssign(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'shift_id' => 'required|exists:shifts,id',
            'employee_ids' => 'nullable|array',
            'employee_ids.*' => 'exists:employees,id',
            'department_ids' => 'nullable|array',
            'department_ids.*' => 'exists:departments,id',
            'effective_from' => 'required|date',
            'effective_to' => 'nullable|date|after_or_equal:effective_from',
            'assigned_days' => 'nullable|array',
        ]);

        $shift = Shift::findOrFail($validated['shift_id']);
        $targetIds = $validated['employee_ids'] ?? [];

        if (!empty($validated['department_ids'])) {
            $deptEmpIds = Employee::whereIn('department_id', $validated['department_ids'])
                ->where('employment_status', 'active')
                ->pluck('id')
                ->toArray();
            $targetIds = array_values(array_unique(array_merge($targetIds, $deptEmpIds)));
        }

        if (empty($targetIds)) {
            return response()->json([
                'message' => 'No eligible employees found for assignment.',
            ], 422);
        }

        $assignments = $this->performShiftAssignment(
            $shift,
            $targetIds,
            $validated['effective_from'],
            $validated['effective_to'] ?? null,
            $validated['assigned_days'] ?? null,
            $request->user()?->id
        );

        return response()->json([
            'message' => 'Shift assigned to ' . count($assignments) . ' employees.',
            'count' => count($assignments),
            'data' => $assignments,
        ], 201);
    }

    protected function performShiftAssignment(
        Shift $shift,
        array $employeeIds,
        string $effectiveFrom,
        ?string $effectiveTo,
        ?array $assignedDays,
        ?int $userId
    ): array {
        $assignments = [];
        $effFromDate = Carbon::parse($effectiveFrom);
        $prevEndDate = $effFromDate->copy()->subDay()->toDateString();

        DB::transaction(function () use (
            $shift,
            $employeeIds,
            $effectiveFrom,
            $effectiveTo,
            $assignedDays,
            $userId,
            $prevEndDate,
            &$assignments
        ) {
            foreach ($employeeIds as $employeeId) {
                // Intelligent shift rotation capping: cap previous open-ended assignments
                EmployeeShiftAssignment::where('employee_id', $employeeId)
                    ->where('effective_from', '<=', $effectiveFrom)
                    ->whereNull('effective_to')
                    ->update(['effective_to' => $prevEndDate]);

                $assignment = EmployeeShiftAssignment::create([
                    'employee_id' => $employeeId,
                    'shift_id' => $shift->id,
                    'effective_from' => $effectiveFrom,
                    'effective_to' => $effectiveTo,
                    'assigned_days' => $assignedDays,
                    'created_by' => $userId,
                ]);

                // Update default active shift on employee record
                Employee::where('id', $employeeId)->update(['shift_id' => $shift->id]);
                $assignments[] = $assignment;
            }
        });

        return $assignments;
    }
}
```

#### 4.2 `HolidayController.php` Updates
```php
<?php

namespace App\Http\Controllers;

use App\Models\Holiday;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HolidayController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Holiday::with('organization');

        if ($request->filled('organization_id')) {
            $query->where('organization_id', $request->query('organization_id'));
        }

        if ($request->filled('year')) {
            $year = (int) $request->query('year');
            $query->where(function ($q) use ($year) {
                $q->whereYear('date', $year)
                  ->orWhere('is_recurring', true);
            });
        }

        if ($request->filled('month')) {
            $month = (int) $request->query('month');
            $query->where(function ($q) use ($month) {
                $q->whereMonth('date', $month);
            });
        }

        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where('name', 'like', "%{$search}%");
        }

        return response()->json($query->orderBy('date')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'organization_id' => 'nullable|exists:organizations,id',
            'name' => 'required|string|max:128',
            'date' => 'required|date',
            'type' => 'nullable|string|in:public,company,optional',
            'is_recurring' => 'nullable|boolean',
            'applies_to' => 'nullable|array',
        ]);

        if (empty($validated['type'])) {
            $validated['type'] = 'public';
        }

        $holiday = Holiday::create($validated);

        return response()->json([
            'message' => 'Holiday created successfully.',
            'data' => $holiday,
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $holiday = Holiday::with('organization')->findOrFail($id);

        return response()->json(['data' => $holiday]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $holiday = Holiday::findOrFail($id);

        $validated = $request->validate([
            'organization_id' => 'nullable|exists:organizations,id',
            'name' => 'sometimes|required|string|max:128',
            'date' => 'sometimes|required|date',
            'type' => 'nullable|string|in:public,company,optional',
            'is_recurring' => 'nullable|boolean',
            'applies_to' => 'nullable|array',
        ]);

        $holiday->update($validated);

        return response()->json([
            'message' => 'Holiday updated successfully.',
            'data' => $holiday,
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $holiday = Holiday::findOrFail($id);
        $holiday->delete();

        return response()->json([
            'message' => 'Holiday deleted successfully.',
        ]);
    }
}
```

#### 4.3 Route Registrations in `routes/api.php`
```php
    // Shifts & Schedules
    Route::get('shifts', [ShiftController::class, 'index'])->middleware('permission:schedules.view,schedules.manage,shifts.view,shifts.manage');
    Route::post('shifts', [ShiftController::class, 'store'])->middleware('permission:schedules.manage,shifts.manage');
    Route::post('shifts/bulk-assign', [ShiftController::class, 'bulkAssign'])->middleware('permission:schedules.manage,shifts.manage');
    Route::get('shifts/{id}', [ShiftController::class, 'show'])->middleware('permission:schedules.view,schedules.manage,shifts.view,shifts.manage');
    Route::put('shifts/{id}', [ShiftController::class, 'update'])->middleware('permission:schedules.manage,shifts.manage');
    Route::delete('shifts/{id}', [ShiftController::class, 'destroy'])->middleware('permission:schedules.manage,shifts.manage');
    Route::post('shifts/{id}/assign', [ShiftController::class, 'assign'])->middleware('permission:schedules.manage,shifts.manage');

    // Employee Shift Assignment Endpoints (supporting tasks.md contract)
    Route::post('employees/{id}/shift-assignments', [EmployeeController::class, 'assignShift'])->middleware('permission:schedules.manage,shifts.manage');
    Route::post('employees/{id}/assign-shift', [EmployeeController::class, 'assignShift'])->middleware('permission:schedules.manage,shifts.manage');

    // Holiday Calendar
    Route::get('holidays', [HolidayController::class, 'index'])->middleware('permission:schedules.view,schedules.manage,shifts.view,shifts.manage');
    Route::post('holidays', [HolidayController::class, 'store'])->middleware('permission:schedules.manage,shifts.manage');
    Route::get('holidays/{id}', [HolidayController::class, 'show'])->middleware('permission:schedules.view,schedules.manage,shifts.view,shifts.manage');
    Route::put('holidays/{id}', [HolidayController::class, 'update'])->middleware('permission:schedules.manage,shifts.manage');
    Route::delete('holidays/{id}', [HolidayController::class, 'destroy'])->middleware('permission:schedules.manage,shifts.manage');
```

---

### Blueprint Component 5: Seeders & Default Data

#### 5.1 Shift Seeder: `database/seeders/ShiftSeeder.php`
```php
<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Shift;
use Illuminate\Database\Seeder;

class ShiftSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::first();
        $orgId = $org?->id;

        $shifts = [
            [
                'organization_id' => $orgId,
                'name' => 'Standard Day Shift',
                'code' => 'SHIFT-DAY',
                'shift_start' => '09:00:00',
                'shift_end' => '18:00:00',
                'grace_period_minutes' => 15,
                'early_out_threshold_minutes' => 30,
                'half_day_threshold_hours' => 4.0,
                'min_hours_full_day' => 8.0,
                'is_overnight' => false,
                'break_duration_minutes' => 60,
                'is_flexible' => false,
                'color' => '#3B82F6',
                'is_active' => true,
            ],
            [
                'organization_id' => $orgId,
                'name' => 'Night Shift',
                'code' => 'SHIFT-NIGHT',
                'shift_start' => '22:00:00',
                'shift_end' => '07:00:00',
                'grace_period_minutes' => 15,
                'early_out_threshold_minutes' => 30,
                'half_day_threshold_hours' => 4.0,
                'min_hours_full_day' => 8.0,
                'is_overnight' => true,
                'break_duration_minutes' => 60,
                'is_flexible' => false,
                'color' => '#8B5CF6',
                'is_active' => true,
            ],
            [
                'organization_id' => $orgId,
                'name' => 'Flexible Shift',
                'code' => 'SHIFT-FLEX',
                'shift_start' => '09:00:00',
                'shift_end' => '18:00:00',
                'grace_period_minutes' => 0,
                'early_out_threshold_minutes' => 0,
                'half_day_threshold_hours' => 4.0,
                'min_hours_full_day' => 8.0,
                'is_overnight' => false,
                'break_duration_minutes' => 60,
                'is_flexible' => true,
                'color' => '#10B981',
                'is_active' => true,
            ],
        ];

        foreach ($shifts as $s) {
            Shift::firstOrCreate(
                ['code' => $s['code']],
                $s
            );
        }
    }
}
```

#### 5.2 Seeder Updates: `database/seeders/RolesAndPermissionsSeeder.php`
Add permissions under Shifts & Schedules:
```php
            // Shifts & Schedules
            ['slug' => 'shifts.view',         'name' => 'View Shifts',            'group' => 'shifts',     'description' => 'View shifts and scheduling calendars'],
            ['slug' => 'shifts.manage',       'name' => 'Manage Shifts',          'group' => 'shifts',     'description' => 'Create, edit shifts, assignments, and holidays'],
            ['slug' => 'schedules.view',      'name' => 'View Schedules',         'group' => 'shifts',     'description' => 'View shift assignments, calendars, and holidays'],
            ['slug' => 'schedules.manage',    'name' => 'Manage Schedules',       'group' => 'shifts',     'description' => 'Assign shifts to employees and departments'],
```
And add `'schedules.view'` and `'schedules.manage'` to `$hrPerms` array.
Register `ShiftSeeder::class` in `DatabaseSeeder.php`.

---

### Blueprint Component 6: Frontend Architecture (`tasks.md` §3.4)

Three modular Vue 3 components to be located in `resources/js/components/schedules/`:
1. `ShiftManager.vue`:
   - Table of shift definitions with active/inactive badges, start/end time, grace period, color pill.
   - Modal for creating/editing shifts with color picker, overnight toggle, and break minute slider.
   - Integrates with `GET /api/shifts`, `POST /api/shifts`, `PUT /api/shifts/{id}`, `DELETE /api/shifts/{id}`.
2. `ShiftAssignment.vue`:
   - Department selector, employee multi-select table, and shift dropdown.
   - Effective date range picker (`effective_from`, `effective_to`) and weekday toggle buttons (Mon–Sun).
   - Integrates with `POST /api/shifts/bulk-assign`.
3. `HolidayCalendar.vue`:
   - Month-by-month visual grid displaying public, company, and optional holidays marked in distinct tag colors.
   - Modal for adding recurring annual holidays or department-scoped holidays.
   - Integrates with `GET /api/holidays?year={year}`, `POST /api/holidays`, `DELETE /api/holidays/{id}`.
4. Navigation Tab in `App.vue`:
   - Adds `🕐 Schedules` (`schedules`) category to the header navigation.

---

## 5. Verification Method

To independently verify the implementation:

1. **Direct PHPUnit Execution**:
   Run the full M2 feature coverage test:
   ```bash
   php artisan test --filter=Tier1FeatureCoverageTest
   ```
   *Expected Result*: All Section 2 M2 tests pass:
   - `test_m2_shift_can_be_defined_with_grace_period_and_breaks`
   - `test_m2_overnight_shift_can_be_defined_crossing_midnight`
   - `test_m2_shift_can_be_assigned_to_employee_with_effective_dates`
   - `test_m2_holiday_calendar_supports_public_and_company_holidays`

2. **Shift Boundary Tests**:
   ```bash
   php artisan test --filter=Tier2BoundaryTest
   ```
   *Expected Result*:
   - `test_boundary_shift_with_zero_break_minutes` passes (zero break/grace allowed).
   - `test_boundary_rejection_of_invalid_shift_start_and_end_times` passes (identical start/end without `is_overnight` returns HTTP 422).

3. **Employee & Shift Integration Tests**:
   ```bash
   php artisan test --filter=EmployeeAndShiftManagementTest
   ```
   *Expected Result*:
   - `test_shift_definition_and_batch_assignment` passes.
   - `test_holiday_crud_and_filtering` passes.

4. **Database Migration Verification**:
   ```bash
   php artisan migrate:fresh --seed
   ```
   Verify default shifts (`SHIFT-DAY`, `SHIFT-NIGHT`, `SHIFT-FLEX`) are present in database:
   ```bash
   php artisan tinker --execute="echo App\Models\Shift::count();"
   ```
   *Expected Result*: Returns at least 3 seeded shifts.

5. **Helper Method Unit Verification**:
   Execute via `tinker`:
   ```php
   $day = App\Models\Shift::where('code', 'SHIFT-DAY')->first();
   assert($day->isDayShift() === true);
   assert($day->durationMinutes(false) === 540); // 9 hours gross
   assert($day->durationMinutes(true) === 480);  // 8 hours net of break

   $night = App\Models\Shift::where('code', 'SHIFT-NIGHT')->first();
   assert($night->isOvernight() === true);
   assert($night->crossesMidnight() === true);
   assert($night->durationMinutes(false) === 540); // 22:00 to 07:00 = 9h
   ```

6. **Invalidation Conditions**:
   The technical design would be invalidated if:
   - Identical start and end times for non-overnight shifts return HTTP 200 or 201 instead of HTTP 422.
   - `break_duration_minutes` of 0 is rejected by validation.
   - Overnight shifts crossing midnight calculate negative duration minutes instead of adding 24 hours.
   - `assigned_days` fails when submitted as lowercase string day names (`['monday', ...]`) vs integer array (`[1, 2, ...]`).
