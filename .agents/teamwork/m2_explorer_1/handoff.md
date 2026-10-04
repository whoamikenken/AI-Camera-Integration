# Milestone 2 Technical Blueprint: Employee Management & Biometric Linkage (Phase 2)

**Author**: `m2_explorer_1` (Teamwork Explorer)  
**Date**: 2026-09-29T22:20:00Z  
**Target Milestone**: Milestone 2 (Phase 2: Employee Management & Biometric Linkage)  
**Status**: COMPLETE Blueprint & Implementation Specification

---

## 1. Observation

A systematic inspection of the codebase, existing database schema, models, controllers, seeders, tests, and configuration yielded the following direct findings:

### 1.1 Existing Database Migrations & Schema
- `database/migrations/2026_09_30_000014_create_employees_table.php`:
  - Contains columns: `id`, `personnel_id`, `user_id`, `organization_id`, `department_id`, `designation_id`, `location_id`, `reporting_manager_id`, `shift_id`, `employee_code`, `first_name`, `last_name`, `employment_type`, `employment_status`, `date_of_joining`, `date_of_leaving`, `work_email`, `personal_email`, `phone`, `emergency_contact_name`, `emergency_contact_phone`, `timestamps()`, `softDeletes()`.
  - **Missing Column**: `avatar` (VARCHAR 255 nullable) is required by the mission specification.
  - **Constraint Status**: `personnel_id` is defined as `$table->foreignId('personnel_id')->nullable()->constrained('personnel')->nullOnDelete();`. For strict 1-to-1 biometric face linkage integrity, `personnel_id` should also have a `->unique()` constraint.
- `database/migrations/2026_08_22_000002_create_personnel_table.php` & `2026_09_17_000001_add_extra_fields_to_personnel_table.php`:
  - `personnel` contains: `id`, `customize_id` (INT UNIQUE), `person_uuid`, `name`, `person_type` (0=Whitelist, 1=Blacklist), `gender`, `id_card`, `tel_num`, `address`, `notes`, `birthday`, `temp_valid`, `valid_begin`, `valid_end`, `effect_number`, `photo_path`, `photo_base64`.
  - In `Personnel::boot()`, auto-generates `customize_id` (`max('customize_id') + 1`) and `person_uuid` on creation if not provided.
- `database/migrations/2026_08_22_000003_create_access_logs_table.php`:
  - `access_logs` references `device_id` (FK to `devices.device_id`) and stores `customize_id` (unsignedBigInteger index). It has **no foreign key** directly constraining `personnel.id`. Historical punches are preserved by `customize_id` even if biometric face profiles are updated or de-provisioned.
- `database/migrations/2026_09_30_000013_create_shifts_table.php`:
  - Defines `shifts` with `shift_start`, `shift_end`, `grace_period_minutes`, `early_out_threshold_minutes`, `half_day_threshold_hours`, `min_hours_full_day`, `is_overnight`, `break_duration_minutes`, `is_flexible`, `color`, `is_active`.
- `database/migrations/2026_09_30_000015_create_employee_shift_assignments_table.php`:
  - Defines `employee_shift_assignments` with `employee_id`, `shift_id`, `effective_from`, `effective_to`, `assigned_days` (JSON), `created_by`.
- `database/migrations/2026_09_30_000016_create_holidays_table.php`:
  - Defines `holidays` with `organization_id`, `name`, `date`, `type` (public, company, optional), `is_recurring` (bool), `applies_to` (JSON).

### 1.2 Existing Eloquent Model (`app/Models/Employee.php`)
- Implements `Auditable`, `HasFactory`, `SoftDeletes`.
- Relations defined: `personnel()`, `user()`, `organization()`, `department()`, `designation()`, `location()`, `shift()`, `reportingManager()`, `directReports()`, `shiftAssignments()`.
- **Gaps Identified**:
  1. No `manager()` relationship method (only `reportingManager()` exists; specification requires `manager()`).
  2. Missing `avatar` in `$fillable`.
  3. Missing `email` accessor (specification states `email`/`work_email`).
  4. Missing M3 contract methods required on `Employee`:
     - `currentShift(Carbon $date): ?Shift`
     - `isHoliday(Carbon $date): bool`
     - `isRestDay(Carbon $date): bool`

### 1.3 Biometric Bridge & Queue Mechanism
- `app/Observers/PersonnelObserver.php`:
  ```php
  public function created(Personnel $p) { SyncPersonnelJob::dispatch($p->id, 'ADD'); }
  public function updated(Personnel $p) { SyncPersonnelJob::dispatch($p->id, 'EDIT'); }
  public function deleting(Personnel $p) { SyncPersonnelJob::dispatch($p->id, 'DELETE', null, $p->customize_id); }
  ```
- Registered in `app/Providers/AppServiceProvider.php`:
  `\App\Models\Personnel::observe(\App\Observers\PersonnelObserver::class);`
- `app/Jobs/SyncPersonnelJob.php`:
  Constructed on queue `'camera-sync'`:
  `public function __construct(?int $personnelId = null, string $action = 'ADD', ?int $targetDeviceId = null, ?int $customizeIdToDelete = null) { $this->onQueue('camera-sync'); }`
  Dispatches to edge cameras via `CameraMqttService` / `CameraHttpService` and updates `sync_tasks`.

### 1.4 REST API & Controller (`app/Http/Controllers/EmployeeController.php`)
- Current methods: `index()`, `store()`, `show()`, `update()`, `destroy()`, `assignShift()`, `export()`, `import()`.
- **Gaps Identified**:
  1. `attendanceSummary(int $id)` is **missing** (`GET /api/employees/{id}/attendance-summary`).
  2. `index()` lacks filtering by `designation_id` and `employment_type`.
  3. `store()` does not automatically bridge face creation (i.e. creating a `Personnel` entity with `photo_base64`/`photo` when `personnel_id` is omitted).
  4. `update()` does not cascade biometric updates (name, phone, whitelist/blacklist) to the linked `Personnel` entity.
  5. `destroy()` soft-deletes the employee but does not cascade biometric revocation (`SyncPersonnelJob('DELETE')`) to edge cameras.

### 1.5 Routes & RBAC Permissions (`routes/api.php` & `RolesAndPermissionsSeeder.php`)
- `routes/api.php` lines 154–163 register employee endpoints with `permission:employees.view`, `employees.create`, `employees.edit`, `employees.delete`, `shifts.manage`.
- `RolesAndPermissionsSeeder.php` seeds permissions: `employees.view`, `employees.create`, `employees.edit`, `employees.delete`, `employees.import`, `employees.export`.
- **Gaps Identified**:
  1. Route for `GET /api/employees/{id}/attendance-summary` is **not registered**.
  2. Specification requires wiring `employees.manage`: routes should support `employees.manage` alongside granular permissions (e.g. `permission:employees.create,employees.manage`) via `CheckPermission` middleware which performs OR-matching.
  3. `employees.manage` should be explicitly added to `RolesAndPermissionsSeeder.php`.

### 1.6 Verification & Testing Baseline
- Running `php artisan test tests/Feature/E2E/Tier1FeatureCoverageTest.php --filter=test_m2`:
  Result: **7 passed, 12 assertions, 0 failures**.
- Running `php artisan test tests/Feature/EmployeeAndShiftManagementTest.php`:
  Result: **5 passed, 29 assertions, 0 failures**.
- Running full test suite `php artisan test`:
  Result: **150 passed, 36 skipped, 0 failures** (skipped tests belong to future M3–M7 milestones awaiting subsequent tables).

---

## 2. Logic Chain

The architecture and technical decisions flow logically from observations to implementation:

1. **Database Schema Evolution (Observation 1.1)**:
   - Adding `avatar` (VARCHAR 255 nullable) to `employees` allows profile pictures, avatar URLs, or storage paths.
   - Setting `personnel_id` as unique and nullable enforces a strict 1-to-1 relationship between an employee and their biometric face record while allowing non-biometric staff (e.g. remote contractors) to exist without a camera profile.
   - Soft-deletes (`deleted_at`) ensure employee records are never physically destroyed, maintaining historical audit logs, salary history, and punch records.

2. **Model Contracts & M3 Readiness (Observation 1.2)**:
   - Aliasing `manager()` to `reportingManager()` ensures code expecting either naming convention succeeds without duplicate relations.
   - Adding `getEmailAttribute()` returning `work_email ?? personal_email` guarantees `$employee->email` always resolves correctly.
   - For `currentShift(Carbon $date)`:
     1. Search `EmployeeShiftAssignment` records where `effective_from <= $date` AND (`effective_to IS NULL` OR `effective_to >= $date`), ordered by `effective_from DESC`.
     2. Check `assigned_days` (handling lowercase strings `monday`..`sunday` and numeric ISO `1`..`7`). If matched, return the assigned `Shift`.
     3. If no assignment matches, fall back to `$employee->shift` (default assigned shift).
     4. If `$employee->shift` is null, fall back to the organization's default active shift (`Shift::where('organization_id', $this->organization_id)->where('is_active', true)->first()`).
   - For `isHoliday(Carbon $date)`:
     1. Query `Holiday` where `organization_id` is null or matches `$this->organization_id`.
     2. Check exact date match or recurring annual match (`is_recurring = true` with matching month and day).
     3. Evaluate `applies_to`: if null/empty, it applies globally; if array contains `$this->department_id` or `$this->location_id`, it applies to this employee.
   - For `isRestDay(Carbon $date)`:
     1. If an active shift assignment defines `assigned_days`, return `true` if the date's day of week is NOT in `assigned_days`.
     2. If no assignment or `assigned_days` is null, default to `$date->isWeekend()`.

3. **1-to-1 Biometric Bridge (Observation 1.3 & 1.4)**:
   - When an employee is created via `POST /api/employees`:
     - If `personnel_id` is provided, link to the existing `Personnel`.
     - If `personnel_id` is not provided but `photo_base64` or `avatar` is provided, automatically instantiate a `Personnel` record (`name = "{$first_name} {$last_name}"`, `person_type = 0`, `photo_base64`, `notes = "Employee: {$employee_code}"`).
     - Because `Personnel::observe(PersonnelObserver::class)` is active, creating the `Personnel` record immediately triggers `PersonnelObserver::created` -> `SyncPersonnelJob::dispatch($personnel->id, 'ADD')` on queue `camera-sync`.
   - When an employee is updated via `PUT /api/employees/{id}`:
     - If `$employee->personnel` exists:
       - If `first_name` or `last_name` changed, update `personnel.name`.
       - If `phone` changed, update `personnel.tel_num`.
       - If `photo_base64` or `avatar` changed, update `personnel.photo_base64` / `photo_path`.
       - If `employment_status` changed to `suspended`, `terminated`, or `resigned`, set `personnel.person_type = 1` (Blacklist) to revoke access immediately on cameras.
       - If changed back to `active`, set `personnel.person_type = 0` (Whitelist).
       - Updating `personnel` fires `PersonnelObserver::updated` -> `SyncPersonnelJob::dispatch($personnel->id, 'EDIT')`.
   - When an employee is deleted via `DELETE /api/employees/{id}`:
     - Soft-delete `$employee`.
     - In order to de-provision facial access on physical cameras, delete the linked `personnel` record. This invokes `PersonnelObserver::deleting` -> `SyncPersonnelJob::dispatch($personnel->id, 'DELETE', null, $personnel->customize_id)`.
     - The camera removes the face template from edge memory, while the database keeps the historical punches intact in `access_logs` via `customize_id`.

4. **Attendance Summary Endpoint Design (Observation 1.4 & 1.5)**:
   - `GET /api/employees/{id}/attendance-summary`:
     - Supports query parameters `month` (`YYYY-MM`), `year` (`YYYY`), `from`, `to`.
     - If `attendance_records` table exists (in Milestone 3+), aggregates `total_working_days`, `present_days`, `absent_days`, `late_days`, `half_days`, `on_leave_days`, `total_work_hours`, `total_overtime_hours`.
     - If `attendance_records` table does not exist yet (during progressive M2 verification), queries available `access_logs` matching `$employee->personnel?->customize_id` or returns zeroed summary schema `{ employee_id, period, total_working_days, present_days, absent_days, late_days, half_days, on_leave_days, total_work_hours, total_overtime_hours, recent_punches }`.
     - This guarantees contract fulfillment across all milestone stages.

5. **RBAC Wiring & Permissions (Observation 1.5)**:
   - By adding `employees.manage` alongside granular permissions (e.g. `permission:employees.view,employees.manage`), roles like `admin` and `hr-manager` gain full administrative control, while read-only roles like `security` or `receptionist` can only view.
   - The `CheckPermission` middleware evaluates whether the authenticated user has ANY of the specified permissions (`OR` logic), or has role `super-admin` (which automatically bypasses checks).

---

## 3. Caveats

1. **Progressive Database Testability**: The attendance tables (`attendance_records`, `attendance_punches`) are scheduled for Milestone 3. The `attendanceSummary()` method must check `Schema::hasTable('attendance_records')` before querying them to ensure calls do not crash in environments where M3 migrations have not yet executed.
2. **Camera Hardware Edge State**: Camera face deletion requires `customize_id`. When an employee with a linked `Personnel` is soft-deleted, `PersonnelObserver::deleting` captures `$personnel->customize_id` and passes it to `SyncPersonnelJob`. If the employee has no linked `personnel_id`, no camera job is dispatched.
3. **CSV File Encoding & Memory Limits**: For bulk employee imports (`POST /api/employees/import`), CSV files should be processed using streamed reading (`fgetcsv`) inside a database transaction, with error row collection, rather than loading the entire file into memory at once.

---

## 4. Conclusion & Technical Implementation Blueprint

The following detailed implementation specifications provide the exact blueprints for the files to create or update.

### 4.1 Database Migration: `database/migrations/2026_09_30_000014_create_employees_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personnel_id')->nullable()->unique()->constrained('personnel')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('designation_id')->nullable()->constrained('designations')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('reporting_manager_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->string('employee_code', 64)->unique();
            $table->string('first_name', 64);
            $table->string('last_name', 64)->nullable();
            $table->string('employment_type', 32)->default('full-time');
            $table->string('employment_status', 32)->default('active');
            $table->date('date_of_joining')->nullable();
            $table->date('date_of_leaving')->nullable();
            $table->string('work_email', 128)->nullable()->unique();
            $table->string('personal_email', 128)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('avatar', 255)->nullable();
            $table->string('emergency_contact_name', 128)->nullable();
            $table->string('emergency_contact_phone', 32)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('employment_status');
            $table->index('department_id');
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
```

---

### 4.2 Eloquent Model: `app/Models/Employee.php`

```php
<?php

namespace App\Models;

use App\Traits\Auditable;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = [
        'personnel_id',
        'user_id',
        'organization_id',
        'department_id',
        'designation_id',
        'location_id',
        'reporting_manager_id',
        'shift_id',
        'employee_code',
        'first_name',
        'last_name',
        'employment_type',
        'employment_status',
        'date_of_joining',
        'date_of_leaving',
        'work_email',
        'personal_email',
        'phone',
        'avatar',
        'emergency_contact_name',
        'emergency_contact_phone',
    ];

    protected $casts = [
        'date_of_joining' => 'date',
        'date_of_leaving' => 'date',
    ];

    protected $appends = [
        'name',
        'email',
    ];

    public function getNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function getEmailAttribute(): ?string
    {
        return $this->work_email ?? $this->personal_email;
    }

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(Personnel::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reporting_manager_id');
    }

    public function reportingManager(): BelongsTo
    {
        return $this->manager();
    }

    public function directReports(): HasMany
    {
        return $this->hasMany(Employee::class, 'reporting_manager_id');
    }

    public function shiftAssignments(): HasMany
    {
        return $this->hasMany(EmployeeShiftAssignment::class);
    }

    // =========================================================================
    // CONTRACT METHODS FOR M3 (ATTENDANCE & SCHEDULING ENGINE)
    // =========================================================================

    /**
     * Resolve the effective Shift for this employee on a given date.
     * Order of precedence:
     * 1. Active ShiftAssignment covering $date with matching assigned_days
     * 2. Active ShiftAssignment covering $date with null assigned_days (daily default)
     * 3. Employee's default assigned shift ($this->shift)
     * 4. Organization default active shift
     */
    public function currentShift(Carbon $date): ?Shift
    {
        $dateStr = $date->format('Y-m-d');
        $dayName = strtolower($date->format('l'));
        $dayIso = $date->dayOfWeekIso; // 1 (Mon) - 7 (Sun)
        $dayNum = $date->dayOfWeek;    // 0 (Sun) - 6 (Sat)

        // Find active assignment
        $assignments = $this->shiftAssignments()
            ->with('shift')
            ->where('effective_from', '<=', $dateStr)
            ->where(function ($q) use ($dateStr) {
                $q->whereNull('effective_to')
                  ->orWhere('effective_to', '>=', $dateStr);
            })
            ->orderBy('effective_from', 'desc')
            ->get();

        foreach ($assignments as $assignment) {
            if (empty($assignment->assigned_days)) {
                return $assignment->shift;
            }

            $days = array_map(fn($d) => is_string($d) ? strtolower($d) : $d, $assignment->assigned_days);
            if (in_array($dayName, $days, true) || in_array($dayIso, $days, true) || in_array($dayNum, $days, true)) {
                return $assignment->shift;
            }
        }

        // Fall back to employee default shift
        if ($this->shift && $this->shift->is_active) {
            return $this->shift;
        }

        // Fall back to organization shift
        if ($this->organization_id) {
            return Shift::where('organization_id', $this->organization_id)
                ->where('is_active', true)
                ->first();
        }

        return Shift::where('is_active', true)->first();
    }

    /**
     * Determine if a given date is a Holiday for this employee.
     */
    public function isHoliday(Carbon $date): bool
    {
        $dateStr = $date->format('Y-m-d');
        $monthDay = $date->format('m-d');

        $holidays = Holiday::where(function ($q) {
            $q->whereNull('organization_id')
              ->orWhere('organization_id', $this->organization_id);
        })->where(function ($q) use ($dateStr, $monthDay) {
            $q->where('date', $dateStr)
              ->orWhere(function ($sub) use ($monthDay) {
                  $sub->where('is_recurring', true)
                      ->whereRaw("TO_CHAR(date, 'MM-DD') = ?", [$monthDay]);
              });
        })->get();

        foreach ($holidays as $holiday) {
            if (empty($holiday->applies_to)) {
                return true;
            }

            $scope = $holiday->applies_to;
            if (is_array($scope)) {
                if ($this->department_id && in_array($this->department_id, $scope, false)) {
                    return true;
                }
                if ($this->location_id && in_array($this->location_id, $scope, false)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Determine if a given date is a Rest Day (non-working day) for this employee.
     */
    public function isRestDay(Carbon $date): bool
    {
        $dateStr = $date->format('Y-m-d');
        $dayName = strtolower($date->format('l'));
        $dayIso = $date->dayOfWeekIso;
        $dayNum = $date->dayOfWeek;

        // Check active shift assignment schedule
        $assignment = $this->shiftAssignments()
            ->where('effective_from', '<=', $dateStr)
            ->where(function ($q) use ($dateStr) {
                $q->whereNull('effective_to')
                  ->orWhere('effective_to', '>=', $dateStr);
            })
            ->orderBy('effective_from', 'desc')
            ->first();

        if ($assignment && !empty($assignment->assigned_days)) {
            $days = array_map(fn($d) => is_string($d) ? strtolower($d) : $d, $assignment->assigned_days);
            $isAssigned = in_array($dayName, $days, true) || in_array($dayIso, $days, true) || in_array($dayNum, $days, true);
            return !$isAssigned;
        }

        // Default standard rest day is weekend
        return $date->isWeekend();
    }
}
```

---

### 4.3 REST API Controller: `app/Http/Controllers/EmployeeController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\Personnel;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Employee::with(['personnel', 'department', 'designation', 'location', 'shift', 'manager']);

        if ($request->filled('status')) {
            $query->where('employment_status', $request->query('status'));
        }

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->query('department_id'));
        }

        if ($request->filled('designation_id')) {
            $query->where('designation_id', $request->query('designation_id'));
        }

        if ($request->filled('location_id')) {
            $query->where('location_id', $request->query('location_id'));
        }

        if ($request->filled('shift_id')) {
            $query->where('shift_id', $request->query('shift_id'));
        }

        if ($request->filled('organization_id')) {
            $query->where('organization_id', $request->query('organization_id'));
        }

        if ($request->filled('employment_type')) {
            $query->where('employment_type', $request->query('employment_type'));
        }

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('employee_code', 'like', "%{$search}%")
                  ->orWhere('work_email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $perPage = (int) $request->query('per_page', 15);
        $employees = $query->orderBy('first_name')->paginate($perPage);

        return response()->json($employees);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'personnel_id' => 'nullable|exists:personnel,id',
            'user_id' => 'nullable|exists:users,id',
            'organization_id' => 'nullable|exists:organizations,id',
            'department_id' => 'nullable|exists:departments,id',
            'designation_id' => 'nullable|exists:designations,id',
            'location_id' => 'nullable|exists:locations,id',
            'reporting_manager_id' => 'nullable|exists:employees,id',
            'shift_id' => 'nullable|exists:shifts,id',
            'employee_code' => 'required|string|max:64|unique:employees,employee_code',
            'first_name' => 'required|string|max:64',
            'last_name' => 'nullable|string|max:64',
            'employment_type' => 'nullable|string',
            'employment_status' => 'nullable|string',
            'date_of_joining' => 'nullable|date',
            'date_of_leaving' => 'nullable|date',
            'work_email' => 'nullable|email|max:128|unique:employees,work_email',
            'personal_email' => 'nullable|email|max:128',
            'phone' => 'nullable|string|max:32',
            'avatar' => 'nullable|string|max:255',
            'photo_base64' => 'nullable|string',
            'photo_path' => 'nullable|string|max:255',
            'emergency_contact_name' => 'nullable|string|max:128',
            'emergency_contact_phone' => 'nullable|string|max:32',
        ]);

        if (empty($validated['employment_status'])) {
            $validated['employment_status'] = 'active';
        }

        DB::beginTransaction();
        try {
            // 1-to-1 Biometric Bridge: Auto-provision Personnel entity if photo provided without personnel_id
            if (empty($validated['personnel_id']) && (!empty($validated['photo_base64']) || !empty($validated['photo_path']) || !empty($validated['avatar']))) {
                $personnel = Personnel::create([
                    'name' => trim("{$validated['first_name']} " . ($validated['last_name'] ?? '')),
                    'person_type' => 0, // Whitelist
                    'tel_num' => $validated['phone'] ?? null,
                    'photo_base64' => $validated['photo_base64'] ?? null,
                    'photo_path' => $validated['photo_path'] ?? $validated['avatar'] ?? null,
                    'notes' => "Employee: {$validated['employee_code']}",
                ]);
                $validated['personnel_id'] = $personnel->id;
            }

            unset($validated['photo_base64'], $validated['photo_path']);

            $employee = Employee::create($validated);
            $employee->load(['personnel', 'department', 'designation', 'location', 'shift', 'manager']);

            DB::commit();

            return response()->json([
                'message' => 'Employee created successfully.',
                'data' => $employee,
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function show(int $id): JsonResponse
    {
        $employee = Employee::with([
            'personnel',
            'department',
            'designation',
            'location',
            'shift',
            'manager',
            'directReports',
            'user',
            'shiftAssignments.shift',
        ])->findOrFail($id);

        return response()->json(['data' => $employee]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $employee = Employee::findOrFail($id);

        $validated = $request->validate([
            'personnel_id' => 'nullable|exists:personnel,id',
            'user_id' => 'nullable|exists:users,id',
            'organization_id' => 'nullable|exists:organizations,id',
            'department_id' => 'nullable|exists:departments,id',
            'designation_id' => 'nullable|exists:designations,id',
            'location_id' => 'nullable|exists:locations,id',
            'reporting_manager_id' => 'nullable|exists:employees,id',
            'shift_id' => 'nullable|exists:shifts,id',
            'employee_code' => 'sometimes|required|string|max:64|unique:employees,employee_code,' . $employee->id,
            'first_name' => 'sometimes|required|string|max:64',
            'last_name' => 'nullable|string|max:64',
            'employment_type' => 'nullable|string',
            'employment_status' => 'nullable|string',
            'date_of_joining' => 'nullable|date',
            'date_of_leaving' => 'nullable|date',
            'work_email' => 'nullable|email|max:128|unique:employees,work_email,' . $employee->id,
            'personal_email' => 'nullable|email|max:128',
            'phone' => 'nullable|string|max:32',
            'avatar' => 'nullable|string|max:255',
            'photo_base64' => 'nullable|string',
            'photo_path' => 'nullable|string|max:255',
            'emergency_contact_name' => 'nullable|string|max:128',
            'emergency_contact_phone' => 'nullable|string|max:32',
        ]);

        DB::beginTransaction();
        try {
            // Update biometric personnel record if linked
            if ($employee->personnel) {
                $personnelUpdates = [];
                if (isset($validated['first_name']) || isset($validated['last_name'])) {
                    $fn = $validated['first_name'] ?? $employee->first_name;
                    $ln = $validated['last_name'] ?? $employee->last_name;
                    $personnelUpdates['name'] = trim("{$fn} {$ln}");
                }
                if (isset($validated['phone'])) {
                    $personnelUpdates['tel_num'] = $validated['phone'];
                }
                if (!empty($validated['photo_base64'])) {
                    $personnelUpdates['photo_base64'] = $validated['photo_base64'];
                }
                if (!empty($validated['photo_path']) || !empty($validated['avatar'])) {
                    $personnelUpdates['photo_path'] = $validated['photo_path'] ?? $validated['avatar'];
                }
                if (isset($validated['employment_status'])) {
                    $personnelUpdates['person_type'] = in_array($validated['employment_status'], ['suspended', 'terminated', 'resigned']) ? 1 : 0;
                }

                if (!empty($personnelUpdates)) {
                    $employee->personnel->update($personnelUpdates);
                }
            }

            unset($validated['photo_base64'], $validated['photo_path']);

            $employee->update($validated);
            $employee->load(['personnel', 'department', 'designation', 'location', 'shift', 'manager']);

            DB::commit();

            return response()->json([
                'message' => 'Employee updated successfully.',
                'data' => $employee,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function destroy(int $id): JsonResponse
    {
        $employee = Employee::findOrFail($id);

        DB::beginTransaction();
        try {
            // Cascade biometric revocation to camera devices
            if ($employee->personnel) {
                $employee->personnel->delete(); // Triggers PersonnelObserver::deleting -> SyncPersonnelJob('DELETE')
            }

            $employee->delete(); // Soft-deletes employee, preserving punches and access logs

            DB::commit();

            return response()->json([
                'message' => 'Employee deleted successfully.',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function attendanceSummary(Request $request, int $id): JsonResponse
    {
        $employee = Employee::with('personnel')->findOrFail($id);

        $from = $request->query('from')
            ? Carbon::parse($request->query('from'))->startOfDay()
            : ($request->query('month') ? Carbon::parse($request->query('month') . '-01')->startOfMonth() : Carbon::now()->startOfMonth());

        $to = $request->query('to')
            ? Carbon::parse($request->query('to'))->endOfDay()
            : ($request->query('month') ? Carbon::parse($request->query('month') . '-01')->endOfMonth() : Carbon::now()->endOfMonth());

        $totalWorkingDays = 0;
        $current = $from->copy();
        while ($current->lte($to)) {
            if (!$employee->isRestDay($current) && !$employee->isHoliday($current)) {
                $totalWorkingDays++;
            }
            $current->addDay();
        }

        $presentDays = 0;
        $absentDays = 0;
        $lateDays = 0;
        $halfDays = 0;
        $onLeaveDays = 0;
        $totalWorkHours = 0.0;
        $totalOvertimeHours = 0.0;
        $recentPunches = [];

        // Progressive integration: query attendance_records if table exists (M3+)
        if (Schema::hasTable('attendance_records')) {
            $records = DB::table('attendance_records')
                ->where('employee_id', $employee->id)
                ->whereBetween('date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
                ->get();

            foreach ($records as $record) {
                if (in_array($record->status, ['present', 'late', 'early_out', 'late_and_early_out'])) {
                    $presentDays++;
                } elseif ($record->status === 'absent') {
                    $absentDays++;
                } elseif ($record->status === 'half_day') {
                    $halfDays++;
                } elseif ($record->status === 'on_leave') {
                    $onLeaveDays++;
                }

                if (!empty($record->is_late)) {
                    $lateDays++;
                }

                $totalWorkHours += (float) ($record->total_work_hours ?? 0);
                $totalOvertimeHours += (float) ($record->overtime_hours ?? 0);
            }
        }

        // Query raw punches or access logs if available
        if (Schema::hasTable('attendance_punches')) {
            $recentPunches = DB::table('attendance_punches')
                ->where('employee_id', $employee->id)
                ->whereBetween('punch_time', [$from, $to])
                ->orderBy('punch_time', 'desc')
                ->limit(10)
                ->get();
        } elseif ($employee->personnel_id && Schema::hasTable('access_logs')) {
            $cId = $employee->personnel?->customize_id;
            if ($cId) {
                $recentPunches = DB::table('access_logs')
                    ->where('customize_id', $cId)
                    ->whereBetween('captured_at', [$from, $to])
                    ->orderBy('captured_at', 'desc')
                    ->limit(10)
                    ->get();
            }
        }

        return response()->json([
            'employee_id' => $employee->id,
            'period' => [
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
            ],
            'total_working_days' => $totalWorkingDays,
            'present_days' => $presentDays,
            'absent_days' => $absentDays,
            'late_days' => $lateDays,
            'half_days' => $halfDays,
            'on_leave_days' => $onLeaveDays,
            'total_work_hours' => round($totalWorkHours, 2),
            'total_overtime_hours' => round($totalOvertimeHours, 2),
            'recent_punches' => $recentPunches,
        ]);
    }

    public function assignShift(Request $request, int $id): JsonResponse
    {
        $employee = Employee::findOrFail($id);

        $validated = $request->validate([
            'shift_id' => 'required|exists:shifts,id',
            'effective_from' => 'required|date',
            'effective_to' => 'nullable|date|after_or_equal:effective_from',
            'assigned_days' => 'nullable|array',
        ]);

        $assignment = EmployeeShiftAssignment::create([
            'employee_id' => $employee->id,
            'shift_id' => $validated['shift_id'],
            'effective_from' => $validated['effective_from'],
            'effective_to' => $validated['effective_to'] ?? null,
            'assigned_days' => $validated['assigned_days'] ?? null,
            'created_by' => $request->user()?->id,
        ]);

        $employee->update(['shift_id' => $validated['shift_id']]);

        return response()->json([
            'message' => 'Shift assigned successfully.',
            'data' => $assignment->load('shift'),
        ], 201);
    }

    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $format = $request->query('format', 'csv');

        $employees = Employee::with(['department', 'designation', 'location', 'shift'])
            ->orderBy('employee_code')
            ->get();

        if ($format === 'json') {
            return response()->json(['data' => $employees]);
        }

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="employees_export_' . date('Y-m-d') . '.csv"',
        ];

        $callback = function () use ($employees) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'Employee Code',
                'First Name',
                'Last Name',
                'Work Email',
                'Phone',
                'Department',
                'Designation',
                'Location',
                'Shift',
                'Status',
                'Date of Joining',
            ]);

            foreach ($employees as $emp) {
                fputcsv($handle, [
                    $emp->employee_code,
                    $emp->first_name,
                    $emp->last_name,
                    $emp->work_email,
                    $emp->phone,
                    $emp->department?->name,
                    $emp->designation?->name,
                    $emp->location?->name,
                    $emp->shift?->name,
                    $emp->employment_status,
                    $emp->date_of_joining?->format('Y-m-d'),
                ]);
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt',
        ]);

        $file = $request->file('file');
        $handle = fopen($file->getRealPath(), 'r');
        $header = fgetcsv($handle);

        $imported = 0;
        $errors = [];

        DB::beginTransaction();
        try {
            $rowNum = 1;
            while (($row = fgetcsv($handle)) !== false) {
                $rowNum++;
                if (empty($row[0])) {
                    continue;
                }

                $data = array_combine($header, $row);
                $employeeCode = trim($data['Employee Code'] ?? $data['employee_code'] ?? '');
                $firstName = trim($data['First Name'] ?? $data['first_name'] ?? '');
                $lastName = trim($data['Last Name'] ?? $data['last_name'] ?? '');
                $email = trim($data['Work Email'] ?? $data['work_email'] ?? $data['email'] ?? '');
                $phone = trim($data['Phone'] ?? $data['phone'] ?? '');

                if (!$employeeCode || !$firstName) {
                    $errors[] = "Row {$rowNum}: Missing employee_code or first_name";
                    continue;
                }

                Employee::updateOrCreate(
                    ['employee_code' => $employeeCode],
                    [
                        'first_name' => $firstName,
                        'last_name' => $lastName ?: null,
                        'work_email' => $email ?: null,
                        'phone' => $phone ?: null,
                        'employment_status' => 'active',
                    ]
                );
                $imported++;
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Import failed: ' . $e->getMessage()], 422);
        } finally {
            fclose($handle);
        }

        return response()->json([
            'message' => "Successfully imported {$imported} employees.",
            'imported_count' => $imported,
            'errors' => $errors,
        ]);
    }
}
```

---

### 4.4 Routes & RBAC Permissions Wiring: `routes/api.php`

Update lines 154–163 of `routes/api.php` to wire permissions (`employees.view`, `employees.manage`, `employees.delete`) with `CheckPermission` middleware:

```php
    // =========================================================================
    // Employees & Directory (Milestone 2)
    // =========================================================================
    Route::get('employees', [EmployeeController::class, 'index'])
        ->middleware('permission:employees.view,employees.manage');

    Route::post('employees', [EmployeeController::class, 'store'])
        ->middleware('permission:employees.create,employees.manage');

    Route::get('employees/export', [EmployeeController::class, 'export'])
        ->middleware('permission:employees.export,employees.view,employees.manage');

    Route::post('employees/import', [EmployeeController::class, 'import'])
        ->middleware('permission:employees.import,employees.manage');

    Route::get('employees/{id}', [EmployeeController::class, 'show'])
        ->middleware('permission:employees.view,employees.manage');

    Route::put('employees/{id}', [EmployeeController::class, 'update'])
        ->middleware('permission:employees.edit,employees.manage');

    Route::delete('employees/{id}', [EmployeeController::class, 'destroy'])
        ->middleware('permission:employees.delete,employees.manage');

    Route::get('employees/{id}/attendance-summary', [EmployeeController::class, 'attendanceSummary'])
        ->middleware('permission:employees.view,attendance.view,selfservice.view,employees.manage');

    Route::post('employees/{id}/assign-shift', [EmployeeController::class, 'assignShift'])
        ->middleware('permission:shifts.manage,employees.manage');
```

---

### 4.5 RBAC Permissions Catalog Update: `database/seeders/RolesAndPermissionsSeeder.php`

In `database/seeders/RolesAndPermissionsSeeder.php`:
Add `employees.manage` to the Permission catalog:
```php
    ['slug' => 'employees.manage',   'name' => 'Manage Employees',   'group' => 'employees',  'description' => 'Full administrative management of employee records and assignments'],
```
And add `'employees.manage'` to `$hrPerms`.

---

## 5. Verification Method

To independently verify the implementation blueprint:

1. **Verify M2 Isolated E2E Tests**:
   ```bash
   php artisan test tests/Feature/E2E/Tier1FeatureCoverageTest.php --filter=test_m2
   ```
   **Expected Result**: All 7 M2 tests pass:
   - `test_m2_employee_can_be_created_and_linked_to_personnel` (HTTP 201)
   - `test_m2_employee_directory_can_be_filtered_by_department` (HTTP 200)
   - `test_m2_employee_soft_delete_preserves_historical_integrity` (HTTP 200)
   - `test_m2_shift_can_be_defined_with_grace_period_and_breaks` (HTTP 201)
   - `test_m2_overnight_shift_can_be_defined_crossing_midnight` (HTTP 201)
   - `test_m2_shift_can_be_assigned_to_employee_with_effective_dates` (HTTP 200)
   - `test_m2_holiday_calendar_supports_public_and_company_holidays` (HTTP 201)

2. **Verify Full Employee & Shift Management Feature Suite**:
   ```bash
   php artisan test tests/Feature/EmployeeAndShiftManagementTest.php
   ```
   **Expected Result**: 5 tests pass with 29 assertions.

3. **Verify Zero Regressions Across Codebase**:
   ```bash
   php artisan test
   ```
   **Expected Result**: 150+ tests pass, 0 failures, 0 errors.

4. **Verify Attendance Summary & Shift Assignment Endpoints**:
   ```bash
   curl -s -H "Authorization: Bearer <TOKEN>" -H "Accept: application/json" http://localhost:8000/api/employees/1/attendance-summary
   ```
   **Expected Result**: HTTP 200 with structured summary metrics.
