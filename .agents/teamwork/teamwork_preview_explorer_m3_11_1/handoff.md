# Milestone M3 Handoff Report: Leave & Regularization Cancellation Workflows

**Author**: `teamwork_preview_explorer_m3_11_1`  
**Role**: Backend Leave & Attendance Explorer  
**Working Directory**: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_m3_11_1`  
**Parent Conversation ID**: `340b2ee2-86ac-4ca7-9f71-8c1542c65adb`  
**Target Milestone**: Milestone 3 (Resilient Domain Lifecycle State Machines — Leave & Regularization)

---

## 1. Observation

### 1.1 Leave Domain Models & Services

#### A. `app/Models/LeaveRequest.php`
- **File**: `app/Models/LeaveRequest.php` (Lines 14–33)
- Current `$fillable`:
  ```php
  protected $fillable = [
      'id',
      'employee_id',
      'leave_type_id',
      'start_date',
      'end_date',
      'total_days',
      'reason',
      'status',
      'approved_by',
      'rejection_reason',
      'approved_at',
  ];
  ```
- Current `$casts`:
  ```php
  protected $casts = [
      'start_date' => 'date',
      'end_date' => 'date',
      'total_days' => 'float',
      'approved_at' => 'datetime',
  ];
  ```
- **Observed Gap**: Cancellation metadata columns are completely absent:
  - Missing `$fillable`: `'cancellation_reason'`, `'cancelled_by'`, `'cancelled_at'`.
  - Missing `$casts`: `'cancelled_at' => 'datetime'`.
  - Missing relationship: `canceller(): BelongsTo` (to `User::class`, foreign key `'cancelled_by'`).

#### B. `app/Models/LeaveBalance.php`
- **File**: `app/Models/LeaveBalance.php` (Lines 14–39)
- Current `$fillable`:
  ```php
  protected $fillable = [
      'employee_id',
      'leave_type_id',
      'year',
      'allocated',
      'used',
      'pending',
      'carried_over',
  ];
  ```
- Current `$casts` & `$appends`:
  ```php
  protected $casts = [
      'year' => 'integer',
      'allocated' => 'float',
      'used' => 'float',
      'pending' => 'float',
      'carried_over' => 'float',
  ];

  protected $appends = [
      'available',
  ];

  public function getAvailableAttribute(): float
  {
      return max(0.0, ($this->allocated + $this->carried_over) - ($this->used + $this->pending));
  }
  ```
- **Critical Discrepancy Observed in E2E Tests**:
  Across `tests/Feature/E2E/Tier1FeatureCoverageTest.php` (Lines 1183–1186, 1211, 1236–1239), `Tier2BoundaryTest.php` (Lines 397–400, 421), `Tier3CrossFeatureTest.php` (Lines 227–230), and `Tier4RealWorldScenariosTest.php` (Lines 226–229, 271), the test suite creates records and asserts balance attributes using:
  - `allocated_days` instead of `allocated`
  - `used_days` instead of `used`
  - `pending_days` instead of `pending`
  - `remaining_days` instead of `available`
  For example, in `Tier1FeatureCoverageTest.php`:
  ```php
  $balance->refresh();
  $this->assertEquals(2, $balance->used_days);
  ```
  And `Tier4RealWorldScenariosTest.php`:
  ```php
  $balance->refresh();
  $this->assertEquals(0, $balance->used_days);
  ```
  Because `leave_balances` in PostgreSQL uses column names `allocated`, `used`, `pending`, `carried_over`, any model call to `$balance->used_days` returns `null` unless explicit accessors, mutators, and `$fillable` aliases are defined on `LeaveBalance`.

#### C. `app/Services/LeaveService.php`
- **File**: `app/Services/LeaveService.php` (Lines 87–248)
- Current methods:
  - `submitLeaveRequest`: Atomically creates `LeaveRequest` with status `'pending'` and executes `$balance->increment('pending', $totalDays)` (Lines 125–139).
  - `approveLeaveRequest`: Validates status `'pending'`, decrements `pending` (`max(0.0, $balance->pending - $request->total_days)`), increments `used` (`$balance->used += $request->total_days`), updates status to `'approved'`, and generates/updates `AttendanceRecord` with `status => 'on_leave'` for every non-weekend date (Lines 176–213).
  - `rejectLeaveRequest`: Validates status `'pending'`, decrements `pending`, sets status to `'rejected'` (Lines 220–247).
- **Observed Gap**: `cancelLeaveRequest` does NOT exist in `LeaveService`.
- Verbatim error when running `test_f14`:
  ```
  [SKIPPED] Method App\Services\LeaveService::cancelLeaveRequest required for Milestone 3
  ```

#### D. `app/Http/Controllers/LeaveController.php`
- **File**: `app/Http/Controllers/LeaveController.php` (Lines 160–272)
- Existing endpoints:
  - `listRequests` (`GET /api/leave-requests`)
  - `storeRequest` (`POST /api/leave-requests`)
  - `approveRequest` (`PUT /api/leave-requests/{id}/approve`)
  - `rejectRequest` (`PUT /api/leave-requests/{id}/reject`)
- **Observed Gap**: No `cancelRequest` endpoint or route exists.
- In `Tier1FeatureCoverageTest.php` line 1167:
  ```php
  $this->requireRoute('/api/leave-requests/1/cancel', 'POST', 'Milestone 3');
  ```
  Running `test_f13_leave_request_cancellation_restores_balance_atomically` results in:
  ```
  [SKIPPED] Route POST /api/leave-requests/1/cancel required for Milestone 3
  ```

---

### 1.2 Attendance Processing & Daily Recalculation

#### A. `app/Services/AttendanceProcessingService.php`
- **File**: `app/Services/AttendanceProcessingService.php` (Lines 174–298)
- Current daily recalculation method:
  ```php
  public function recalculateDailyAttendance(Employee $employee, string|Carbon $date): AttendanceRecord
  ```
  - Queries punches within the work date window (`$startDate` to `$endDate`).
  - If `$punches->isEmpty()`, assigns `$status = $isHoliday ? 'holiday' : 'absent'` and updates or creates `AttendanceRecord`.
  - If `$punches->isNotEmpty()`, calculates work hours, checks grace period, late minutes, early out minutes, overtime, and sets status to `'present'`, `'late'`, `'early_out'`, `'late_and_early_out'`, or `'half_day'`.
- **Observed Gap**: `AttendanceProcessingService` does NOT have a method named `processDay(Employee $employee, Carbon|string $date): AttendanceRecord`.
- Referenced throughout `system-evo.md` (Line 87), `PROJECT.md` (Line 89), `TEST_INFRA.md` (Line 67), and `tasks.md` (Line 251):
  ```
  "Reverts corresponding AttendanceRecord statuses and triggers recalculation via AttendanceProcessingService::processDay()."
  ```

---

### 1.3 Regularization Domain Models, Controllers & Services

#### A. `app/Models/RegularizationRequest.php`
- **File**: `app/Models/RegularizationRequest.php` (Lines 10–42)
- Current `$fillable`:
  ```php
  protected $fillable = [
      'employee_id',
      'date',
      'requested_in',
      'requested_out',
      'reason',
      'status',
      'approved_by',
      'rejection_reason',
      'approved_at',
  ];
  ```
- **Observed Gaps**:
  1. Missing cancellation fields: `cancellation_reason`, `cancelled_by`, `cancelled_at`.
  2. Model name: The actual model is `RegularizationRequest`, not `AttendanceRegularization`.
  3. **Critical Discrepancy Observed in E2E Tests**:
     In `Tier1FeatureCoverageTest.php` line 1278–1279:
     ```php
     $reg = \App\Models\RegularizationRequest::create([
         'id' => 1,
         'employee_id' => $emp->id,
         'date' => Carbon::yesterday()->toDateString(),
         'requested_clock_in' => '09:00:00',
         'requested_clock_out' => '18:00:00',
         'status' => 'pending',
         'reason' => 'Turnstile card misread',
     ]);
     ```
     The test passes `requested_clock_in` and `requested_clock_out`, but the DB columns and `$fillable` are `requested_in` and `requested_out`. Accessors/mutators and `$fillable` inclusion are required.

#### B. `app/Http/Controllers/RegularizationController.php`
- **File**: `app/Http/Controllers/RegularizationController.php` (Lines 19–170)
- Existing actions:
  - `index` (`GET /api/regularization-requests`)
  - `store` (`POST /api/regularization-requests`)
  - `approve` (`PUT /api/regularization-requests/{id}/approve`)
  - `reject` (`PUT /api/regularization-requests/{id}/reject`)
- **Observed Gap**: No `cancel` action or route exists.
- In `Tier1FeatureCoverageTest.php` line 1262:
  ```php
  $this->requireRoute('/api/regularization-requests/1/cancel', 'POST', 'Milestone 3');
  ```
  Resulting in test skip.

#### C. `app/Services/RegularizationService.php`
- Does not currently exist in the codebase. Business logic for approval currently resides directly inside `RegularizationController::approve()`.
- Specification in `PROJECT.md` (Lines 92–94) calls for:
  ```php
  RegularizationService::cancelRegularization(AttendanceRegularization $regularization, ?User $user, ?string $reason): AttendanceRegularization
  ```

---

### 1.4 Database Schema & Existing Migrations

#### A. Existing Migration: `database/migrations/2026_09_30_000019_create_leave_and_regularization_tables.php`
- `leave_requests` table schema:
  ```sql
  CREATE TABLE leave_requests (
      id BIGSERIAL PRIMARY KEY,
      employee_id BIGINT REFERENCES employees(id) ON DELETE CASCADE,
      leave_type_id BIGINT REFERENCES leave_types(id) ON DELETE CASCADE,
      start_date DATE NOT NULL,
      end_date DATE NOT NULL,
      total_days NUMERIC(5, 1) DEFAULT 1.0,
      reason TEXT,
      status VARCHAR(32) DEFAULT 'pending',
      approved_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
      rejection_reason TEXT,
      approved_at TIMESTAMP,
      created_at TIMESTAMP,
      updated_at TIMESTAMP
  );
  ```
- `regularization_requests` table schema:
  ```sql
  CREATE TABLE regularization_requests (
      id BIGSERIAL PRIMARY KEY,
      employee_id BIGINT REFERENCES employees(id) ON DELETE CASCADE,
      date DATE NOT NULL,
      requested_in TIMESTAMP,
      requested_out TIMESTAMP,
      reason TEXT NOT NULL,
      status VARCHAR(32) DEFAULT 'pending',
      approved_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
      rejection_reason TEXT,
      approved_at TIMESTAMP,
      created_at TIMESTAMP,
      updated_at TIMESTAMP
  );
  ```
- **Verification via CLI**:
  ```bash
  php -r "require 'vendor/autoload.php'; \$app = require 'bootstrap/app.php'; \$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap(); print_r(Illuminate\Support\Facades\Schema::getColumnListing('leave_requests'));"
  ```
  Confirmed: neither `leave_requests` nor `regularization_requests` has `cancellation_reason`, `cancelled_by`, or `cancelled_at`.

---

## 2. Logic Chain

```
[Observation: leave_requests and regularization_requests lack cancellation fields]
                                    │
                                    ▼
[Step 1: Database Migration]
Create migration adding `cancellation_reason` (text, nullable), `cancelled_by` (foreignId to users, nullable, nullOnDelete), and `cancelled_at` (timestamp, nullable) to both tables.

                                    │
                                    ▼
[Step 2: Model Synchronization]
Add `cancellation_reason`, `cancelled_by`, `cancelled_at` to $fillable and $casts in LeaveRequest and RegularizationRequest.
Define `canceller(): BelongsTo` relationship to User.

                                    │
                                    ▼
[Step 3: Dual Naming Compatibility Bridge]
Observation: E2E tests access `used_days`, `allocated_days`, `pending_days`, `remaining_days` on LeaveBalance, and `requested_clock_in`, `requested_clock_out` on RegularizationRequest.
Action: Add accessors and mutators mapping `*_days` to underlying DB attributes (`used`, `allocated`, `pending`, `available`), and mapping `requested_clock_*` to `requested_*`. This guarantees zero regressions for existing production code while satisfying 100% of E2E assertions.

                                    │
                                    ▼
[Step 4: Attendance Processing Method Alias]
Observation: Specifications and callers call `processDay($employee, $date)`.
Action: Add `public function processDay(Employee $employee, Carbon|string $date): AttendanceRecord` delegating to `recalculateDailyAttendance($employee, $date)` in `AttendanceProcessingService`.

                                    │
                                    ▼
[Step 5: LeaveService::cancelLeaveRequest Atomic Workflow]
1. Validate status is in `['pending', 'approved']`. Throw ValidationException (HTTP 422) if 'rejected' or 'cancelled'.
2. DB::transaction:
   - SELECT FOR UPDATE on LeaveBalance row matching employee_id, leave_type_id, and year.
   - If previous status was 'pending': decrement `pending` (`max(0.0, pending - total_days)`).
   - If previous status was 'approved': decrement `used` (`max(0.0, used - total_days)`), restoring available balance.
   - Update LeaveRequest: status = 'cancelled', cancellation_reason, cancelled_by, cancelled_at = now().
   - If previous status was 'approved':
     Loop dates between start_date and end_date (skipping weekends):
       * Query AttendanceRecord for that date.
       * If record has status == 'on_leave':
           - If date is strictly future AND no punches recorded: delete the pre-created on_leave record (prevents marking employee absent prematurely).
           - Else: reset status to null and remarks to null, then call AttendanceProcessingService::processDay($employee, $date).
       * Else (record exists without on_leave or punches exist): call AttendanceProcessingService::processDay($employee, $date).

                                    │
                                    ▼
[Step 6: Regularization Cancellation Workflow]
1. Validate status is strictly 'pending'. If 'approved', 'rejected', or 'cancelled', reject with HTTP 422.
2. In DB transaction: update RegularizationRequest status to 'cancelled', set cancellation_reason, cancelled_by, cancelled_at.

                                    │
                                    ▼
[Step 7: Route & Controller Security Enforcement]
1. `POST|PUT /api/leave-requests/{id}/cancel` -> LeaveController::cancelRequest
   - Middleware: `permission:leaves.apply,leaves.manage`
   - Authorization: Non-managers can only cancel requests where `leave_request.employee_id == user.employee.id` (HTTP 403 on mismatch).
2. `POST|PUT /api/regularization-requests/{id}/cancel` -> RegularizationController::cancel
   - Middleware: `permission:selfservice.view,attendance.view,attendance.manage`
   - Authorization: Non-managers can only cancel their own regularization requests (HTTP 403 on mismatch).
```

---

## 3. Caveats

1. **Model Naming Convention (`AttendanceRegularization` vs `RegularizationRequest`)**:
   The directive and architectural text refer to `AttendanceRegularization`, while the existing codebase model is `App\Models\RegularizationRequest` (backed by `regularization_requests` table). To prevent future breakage if tests or services instantiate `App\Models\AttendanceRegularization`, we propose defining a class alias or forwarding class:
   ```php
   class_alias(\App\Models\RegularizationRequest::class, 'App\Models\AttendanceRegularization');
   ```
2. **Visit Lifecycle Scope**:
   While Milestone M3 also includes visitor lifecycle automation (`DetectOverstayVisitorsJob`, `ExpireNoShowVisitsJob`, and visit cancellation), this investigation report strictly focuses on the Leave and Attendance Regularization workflows as assigned. A shared migration adding cancellation fields to `leave_requests`, `regularization_requests`, and `visits` is provided for efficiency.
3. **Overnight Shift Edge Cases**:
   `AttendanceProcessingService::resolveWorkDate` already handles overnight shifts by shifting early-morning punches before noon back to the previous calendar day. Calling `processDay` on the calendar date resolves the proper shift assignment and recalculates punches within the correct window.
4. **Half-Day Leave Increments**:
   As tested in `Tier2BoundaryTest::test_boundary_leave_cancellation_half_day_increment_atomic_restoration`, `total_days` can be a decimal (e.g. `0.5`). All decrement and balance computations must use floating point math with casting.

---

## 4. Conclusion & Complete Implementation Design

### 4.1 Required Migration

**Target File**: `database/migrations/2026_10_08_000001_add_cancellation_fields_to_leave_and_regularization_tables.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Leave Requests
        Schema::table('leave_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('leave_requests', 'cancellation_reason')) {
                $table->text('cancellation_reason')->nullable()->after('rejection_reason');
            }
            if (!Schema::hasColumn('leave_requests', 'cancelled_by')) {
                $table->foreignId('cancelled_by')->nullable()->after('cancellation_reason')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('leave_requests', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
            }
        });

        // 2. Regularization Requests
        Schema::table('regularization_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('regularization_requests', 'cancellation_reason')) {
                $table->text('cancellation_reason')->nullable()->after('rejection_reason');
            }
            if (!Schema::hasColumn('regularization_requests', 'cancelled_by')) {
                $table->foreignId('cancelled_by')->nullable()->after('cancellation_reason')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('regularization_requests', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
            }
        });

        // 3. Visits (Milestone 3 Support)
        if (Schema::hasTable('visits')) {
            Schema::table('visits', function (Blueprint $table) {
                if (!Schema::hasColumn('visits', 'cancellation_reason')) {
                    $table->text('cancellation_reason')->nullable()->after('status');
                }
                if (!Schema::hasColumn('visits', 'cancelled_by')) {
                    $table->foreignId('cancelled_by')->nullable()->after('cancellation_reason')->constrained('users')->nullOnDelete();
                }
                if (!Schema::hasColumn('visits', 'cancelled_at')) {
                    $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
                }
                if (!Schema::hasColumn('visits', 'expected_departure')) {
                    $table->timestamp('expected_departure')->nullable()->after('expected_arrival');
                }
                if (!Schema::hasColumn('visits', 'overstay_alerted_at')) {
                    $table->timestamp('overstay_alerted_at')->nullable()->after('check_out_time');
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            if (Schema::hasColumn('leave_requests', 'cancelled_by')) {
                $table->dropForeign(['cancelled_by']);
                $table->dropColumn('cancelled_by');
            }
            if (Schema::hasColumn('leave_requests', 'cancellation_reason')) {
                $table->dropColumn('cancellation_reason');
            }
            if (Schema::hasColumn('leave_requests', 'cancelled_at')) {
                $table->dropColumn('cancelled_at');
            }
        });

        Schema::table('regularization_requests', function (Blueprint $table) {
            if (Schema::hasColumn('regularization_requests', 'cancelled_by')) {
                $table->dropForeign(['cancelled_by']);
                $table->dropColumn('cancelled_by');
            }
            if (Schema::hasColumn('regularization_requests', 'cancellation_reason')) {
                $table->dropColumn('cancellation_reason');
            }
            if (Schema::hasColumn('regularization_requests', 'cancelled_at')) {
                $table->dropColumn('cancelled_at');
            }
        });

        if (Schema::hasTable('visits')) {
            Schema::table('visits', function (Blueprint $table) {
                if (Schema::hasColumn('visits', 'cancelled_by')) {
                    $table->dropForeign(['cancelled_by']);
                    $table->dropColumn('cancelled_by');
                }
                if (Schema::hasColumn('visits', 'cancellation_reason')) {
                    $table->dropColumn('cancellation_reason');
                }
                if (Schema::hasColumn('visits', 'cancelled_at')) {
                    $table->dropColumn('cancelled_at');
                }
                if (Schema::hasColumn('visits', 'expected_departure')) {
                    $table->dropColumn('expected_departure');
                }
                if (Schema::hasColumn('visits', 'overstay_alerted_at')) {
                    $table->dropColumn('overstay_alerted_at');
                }
            });
        }
    }
};
```

---

### 4.2 Model Upgrades

#### A. `app/Models/LeaveRequest.php`
- Add to `$fillable`: `'cancellation_reason'`, `'cancelled_by'`, `'cancelled_at'`.
- Add to `$casts`: `'cancelled_at' => 'datetime'`.
- Add relationship:
  ```php
  public function canceller(): BelongsTo
  {
      return $this->belongsTo(User::class, 'cancelled_by');
  }
  ```

#### B. `app/Models/LeaveBalance.php`
- Add to `$fillable`: `'allocated_days'`, `'used_days'`, `'pending_days'`, `'remaining_days'`.
- Add to `$appends`: `'allocated_days'`, `'used_days'`, `'pending_days'`, `'remaining_days'`.
- Add accessors and mutators:
  ```php
  public function getAllocatedDaysAttribute(): float
  {
      return (float) ($this->attributes['allocated'] ?? 0.0);
  }

  public function setAllocatedDaysAttribute($value): void
  {
      $this->attributes['allocated'] = (float) $value;
  }

  public function getUsedDaysAttribute(): float
  {
      return (float) ($this->attributes['used'] ?? 0.0);
  }

  public function setUsedDaysAttribute($value): void
  {
      $this->attributes['used'] = (float) $value;
  }

  public function getPendingDaysAttribute(): float
  {
      return (float) ($this->attributes['pending'] ?? 0.0);
  }

  public function setPendingDaysAttribute($value): void
  {
      $this->attributes['pending'] = (float) $value;
  }

  public function getRemainingDaysAttribute(): float
  {
      return (float) $this->available;
  }

  public function setRemainingDaysAttribute($value): void
  {
      // Virtual/read-only computed attribute
  }
  ```

#### C. `app/Models/RegularizationRequest.php`
- Add to `$fillable`: `'cancellation_reason'`, `'cancelled_by'`, `'cancelled_at'`, `'requested_clock_in'`, `'requested_clock_out'`.
- Add to `$casts`: `'cancelled_at' => 'datetime'`.
- Add accessors and mutators:
  ```php
  public function getRequestedClockInAttribute()
  {
      return $this->requested_in;
  }

  public function setRequestedClockInAttribute($value): void
  {
      $this->attributes['requested_in'] = $value;
  }

  public function getRequestedClockOutAttribute()
  {
      return $this->requested_out;
  }

  public function setRequestedClockOutAttribute($value): void
  {
      $this->attributes['requested_out'] = $value;
  }

  public function canceller(): BelongsTo
  {
      return $this->belongsTo(User::class, 'cancelled_by');
  }
  ```
- Support `AttendanceRegularization` class alias:
  ```php
  if (!class_exists(\App\Models\AttendanceRegularization::class)) {
      class_alias(\App\Models\RegularizationRequest::class, \App\Models\AttendanceRegularization::class);
  }
  ```

---

### 4.3 Service Implementations

#### A. `app/Services/AttendanceProcessingService.php`
Add public method alias `processDay`:
```php
/**
 * Process/recalculate daily attendance for a specific employee and date.
 */
public function processDay(Employee $employee, Carbon|string $date): AttendanceRecord
{
    return $this->recalculateDailyAttendance($employee, $date);
}
```

#### B. `app/Services/LeaveService.php`
Add constructor dependency injection and `cancelLeaveRequest`:
```php
public function __construct(
    protected ?AttendanceProcessingService $attendanceService = null
) {
    $this->attendanceService = $this->attendanceService ?? app(AttendanceProcessingService::class);
}

/**
 * Cancel an existing leave request (pending or approved).
 * Restores balances atomically and reverts affected daily attendance records.
 */
public function cancelLeaveRequest(
    LeaveRequest $request,
    ?User $user = null,
    ?string $reason = null
): LeaveRequest {
    if (!in_array($request->status, ['pending', 'approved'])) {
        throw ValidationException::withMessages([
            'status' => ["Cannot cancel leave request with status '{$request->status}'."],
        ]);
    }

    return DB::transaction(function () use ($request, $user, $reason) {
        $previousStatus = $request->status;
        $totalDays = (float) $request->total_days;
        $year = Carbon::parse($request->start_date)->year;

        // 1. Lock and update LeaveBalance
        $balance = LeaveBalance::where('employee_id', $request->employee_id)
            ->where('leave_type_id', $request->leave_type_id)
            ->where('year', $year)
            ->lockForUpdate()
            ->first();

        if ($balance) {
            if ($previousStatus === 'pending') {
                $balance->pending = max(0.0, (float) $balance->pending - $totalDays);
            } elseif ($previousStatus === 'approved') {
                $balance->used = max(0.0, (float) $balance->used - $totalDays);
            }
            $balance->save();
        }

        // 2. Update LeaveRequest status
        $request->update([
            'status' => 'cancelled',
            'cancellation_reason' => $reason ?? 'Cancelled by user',
            'cancelled_by' => $user?->id,
            'cancelled_at' => now(),
        ]);

        // 3. Rollback AttendanceRecords if previously approved
        if ($previousStatus === 'approved') {
            $employee = $request->employee ?? Employee::find($request->employee_id);
            if ($employee) {
                $period = CarbonPeriod::create(
                    Carbon::parse($request->start_date),
                    Carbon::parse($request->end_date)
                );

                foreach ($period as $date) {
                    if ($date->isWeekend()) {
                        continue;
                    }

                    $dateStr = $date->format('Y-m-d');
                    $isFuture = $date->copy()->startOfDay()->isFuture();

                    $record = AttendanceRecord::where('employee_id', $employee->id)
                        ->whereDate('date', $dateStr)
                        ->first();

                    $hasPunches = \App\Models\AttendancePunch::where('employee_id', $employee->id)
                        ->whereBetween('punch_time', [
                            $date->copy()->startOfDay(),
                            $date->copy()->endOfDay(),
                        ])
                        ->exists();

                    if ($isFuture && !$hasPunches) {
                        // Future workday with no punches: cleanly remove pre-created 'on_leave' placeholder
                        if ($record && $record->status === 'on_leave') {
                            $record->delete();
                        }
                    } else {
                        // Past, present, or day with punches: clear on_leave and recalculate
                        if ($record && $record->status === 'on_leave') {
                            $record->update(['status' => null, 'remarks' => null]);
                        }
                        $this->attendanceService->processDay($employee, $dateStr);
                    }
                }
            }
        }

        return $request->fresh();
    });
}
```

#### C. `app/Services/RegularizationService.php`
Create dedicated service:
```php
<?php

namespace App\Services;

use App\Models\RegularizationRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegularizationService
{
    /**
     * Cancel an unapproved regularization request.
     */
    public function cancelRegularization(
        RegularizationRequest $regularization,
        ?User $user = null,
        ?string $reason = null
    ): RegularizationRequest {
        if ($regularization->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => ["Cannot cancel regularization request with status '{$regularization->status}'."],
            ]);
        }

        return DB::transaction(function () use ($regularization, $user, $reason) {
            $regularization->update([
                'status' => 'cancelled',
                'cancellation_reason' => $reason ?? 'Cancelled by user',
                'cancelled_by' => $user?->id,
                'cancelled_at' => now(),
            ]);

            return $regularization->fresh();
        });
    }
}
```

---

### 4.4 Controller Implementations & Route Registration

#### A. `app/Http/Controllers/LeaveController.php`
Add `cancelRequest`:
```php
public function cancelRequest(Request $request, int $id): JsonResponse
{
    $leaveRequest = LeaveRequest::findOrFail($id);

    $user = $request->user();
    $canManageLeaves = $user && ($user->hasRole(['super-admin', 'admin', 'hr-manager']) || $user->hasPermission('leaves.manage'));

    if (!$canManageLeaves && $user) {
        $userEmployee = $user->employee;
        if (!$userEmployee) {
            return response()->json([
                'message' => 'User is not associated with an active employee record.',
            ], 403);
        }
        if ((int) $leaveRequest->employee_id !== (int) $userEmployee->id) {
            return response()->json([
                'message' => 'You cannot cancel leave requests for other employees.',
            ], 403);
        }
    }

    if (!in_array($leaveRequest->status, ['pending', 'approved'])) {
        return response()->json([
            'message' => "Cannot cancel leave request with status '{$leaveRequest->status}'.",
        ], 422);
    }

    $reason = $request->input('reason', 'Cancelled by user');
    $cancelled = $this->leaveService->cancelLeaveRequest($leaveRequest, $user, $reason);

    return response()->json([
        'message' => 'Leave request cancelled successfully.',
        'data' => $cancelled->fresh(['employee', 'leaveType']),
    ], 200);
}
```

#### B. `app/Http/Controllers/RegularizationController.php`
Inject `RegularizationService` and add `cancel`:
```php
public function __construct(
    protected AttendanceProcessingService $attendanceService,
    protected ?RegularizationService $regularizationService = null
) {
    $this->regularizationService = $this->regularizationService ?? app(RegularizationService::class);
}

public function cancel(Request $request, int $id): JsonResponse
{
    $regularization = RegularizationRequest::findOrFail($id);

    $user = $request->user();
    $canManageAttendance = $user && ($user->hasRole(['super-admin', 'admin', 'hr-manager']) || $user->hasPermission('attendance.manage'));

    if (!$canManageAttendance && $user) {
        $userEmployee = $user->employee;
        if (!$userEmployee) {
            return response()->json([
                'message' => 'User is not associated with an active employee record.',
            ], 403);
        }
        if ((int) $regularization->employee_id !== (int) $userEmployee->id) {
            return response()->json([
                'message' => 'You cannot cancel regularization requests for other employees.',
            ], 403);
        }
    }

    if ($regularization->status !== 'pending') {
        return response()->json([
            'message' => "Cannot cancel regularization with status '{$regularization->status}'.",
        ], 422);
    }

    $reason = $request->input('reason', 'Cancelled by user');
    $cancelled = $this->regularizationService->cancelRegularization($regularization, $user, $reason);

    return response()->json([
        'message' => 'Regularization request cancelled successfully.',
        'data' => $cancelled,
    ], 200);
}
```

#### C. `routes/api.php`
Register cancellation routes:
```php
// Leave cancellation (supports both POST and PUT for compatibility)
Route::match(['post', 'put'], 'leave-requests/{id}/cancel', [LeaveController::class, 'cancelRequest'])
    ->middleware('permission:leaves.apply,leaves.manage');

// Regularization cancellation (supports both POST and PUT)
Route::match(['post', 'put'], 'regularization-requests/{id}/cancel', [RegularizationController::class, 'cancel'])
    ->middleware('permission:selfservice.view,attendance.view,attendance.manage');
```

---

## 5. Verification Method

### 5.1 Step-by-Step Test Execution Commands

1. **Verify Database Migrations**:
   ```bash
   php artisan migrate --force
   ```
   *Expected*: Migration `add_cancellation_fields_to_leave_and_regularization_tables` applies cleanly.

2. **Run Dedicated Feature Tests**:
   ```bash
   php artisan test --filter=LeaveAndRegularizationTest
   ```
   *Expected*: All existing tests pass, plus new cancellation tests pass with 0 errors.

3. **Run Milestone 3 Lifecycle E2E Tests**:
   ```bash
   php artisan test --filter=test_f13_leave_request_cancellation_restores_balance_atomically
   php artisan test --filter=test_f14_attendance_status_rollback_and_recalculation_on_leave_cancel
   php artisan test --filter=test_f15_regularization_cancellation_workflow_updates_status
   php artisan test --filter=test_boundary_leave_cancellation_half_day_increment_atomic_restoration
   php artisan test --filter=test_boundary_cannot_cancel_already_cancelled_leave_request
   php artisan test --filter=test_cross_leave_cancellation_triggers_attendance_recalculation_from_punches
   php artisan test --filter=test_scenario_7_emergency_shift_adjustment_and_leave_cancellation
   ```
   *Expected*: All 7 tests transition from `[SKIPPED]` to `[PASSED]`.

4. **Run Full Test Suite**:
   ```bash
   php artisan test
   ```
   *Expected*: Zero regressions across all test suites.

### 5.2 Invalidation Conditions
- If cancelling an approved leave request fails to restore the employee's `LeaveBalance.used` / `used_days`, or leaves the `AttendanceRecord` as `on_leave`.
- If an already cancelled or rejected leave request returns `200 OK` instead of `422 Unprocessable Entity`.
- If an employee is permitted to cancel another employee's request without administrative permissions.
- If calling `LeaveBalance::refresh()` in tests throws a column missing error or returns `null` for `used_days`.
