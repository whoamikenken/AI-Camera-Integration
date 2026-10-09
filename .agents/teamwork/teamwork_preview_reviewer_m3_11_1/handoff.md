# Milestone M3 Review & Adversarial Challenge Handoff Report

**Reviewer Agent**: `teamwork_preview_reviewer_m3_11_1`  
**Roles**: reviewer, critic  
**Target Milestone**: M3 (Resilient Domain Lifecycle State Machines — Feature 2)  
**Parent Conversation ID**: `340b2ee2-86ac-4ca7-9f71-8c1542c65adb`  
**Verdict**: **`APPROVE`**  
**Overall Risk Assessment**: **`LOW`**

---

## 1. Observation

### 1.1 Database Migrations
- File: `database/migrations/2026_10_08_000001_add_m3_lifecycle_state_columns.php`
- Schema adjustments inspected:
  - `leave_requests`: added `cancellation_reason` (text, nullable), `cancelled_by` (foreignId to `users`, nullable), `cancelled_at` (timestamp, nullable).
  - `regularization_requests`: added `cancellation_reason` (text, nullable), `cancelled_by` (foreignId to `users`, nullable), `cancelled_at` (timestamp, nullable).
  - `visits`: added `device_id` (foreign key to `devices(device_id)`, nullable), `expected_departure` (timestamp, nullable), `overstay_alerted_at` (timestamp, nullable), `cancellation_reason` (text, nullable), `cancelled_by` (foreignId to `users`, nullable), `cancelled_at` (timestamp, nullable).
  - Indexes added: composite indexes `visits_status_expected_departure_idx` (`['status', 'expected_departure']`) and `visits_status_expected_arrival_idx` (`['status', 'expected_arrival']`).
  - Down migration drops foreign keys, indexes, and columns in reverse dependency order without leaving orphaned schema remnants.

### 1.2 Domain Service Implementations
- **`app/Services/LeaveService.php` (`cancelLeaveRequest`, lines 288–366)**:
  - Validates request status:
    ```php
    if (!in_array($request->status, ['pending', 'approved'])) {
        throw ValidationException::withMessages([
            'status' => ["Cannot cancel leave request with status '{$request->status}'."],
        ]);
    }
    ```
  - Performs atomic balance restoration inside `DB::transaction` using pessimistic locking:
    ```php
    $balance = LeaveBalance::where('employee_id', $request->employee_id)
        ->where('leave_type_id', $request->leave_type_id)
        ->where('year', $year)
        ->lockForUpdate()
        ->first();
    ```
  - Restores `pending` or `used` balances based on whether the cancelled request was `pending` or `approved`.
  - Updates request status to `'cancelled'`, recording `cancellation_reason`, `cancelled_by`, and `cancelled_at`.
  - Rolls back attendance records for approved leaves:
    - For future dates without punches, deletes the placeholder `'on_leave'` `AttendanceRecord`.
    - For dates with punches or past dates, strips `'on_leave'` remarks and invokes `AttendanceProcessingService::processDay($employee, $dateStr)`.

- **`app/Services/AttendanceProcessingService.php` (`processDay`, lines 234–237; `recalculateDailyAttendance`, lines 242–366)**:
  - Dispatches punches with `$punch->withoutRelations()`.
  - Recalculates day status to `'present'`, `'late'`, `'early_out'`, `'late_and_early_out'`, `'half_day'`, or `'absent'`/`'holiday'`.
  - Resolves shifts with versioned Redis cache invalidation (`emp_shift_v:{$employee->id}`).

- **`app/Services/RegularizationService.php` (`cancelRegularization`, lines 15–36)**:
  - Enforces status transition constraint:
    ```php
    if ($request->status !== 'pending') {
        throw ValidationException::withMessages([
            'status' => ["Cannot cancel regularization request with status '{$request->status}'."],
        ]);
    }
    ```
  - Transitions status to `'cancelled'`, recording `cancelled_by`, `cancelled_at`, and `cancellation_reason` within a transaction.

- **`app/Services/VisitorSyncService.php` (`cancelVisit`, lines 77–104)**:
  - Rejects attempts to cancel visits in terminal states (`['cancelled', 'checked_out', 'no_show']`) with HTTP 422 `ValidationException`.
  - Immediately triggers camera biometric revocation:
    ```php
    $this->revokeVisitorFace($visit);
    ```
    which dispatches `SyncPersonnelJob::dispatch($personnel->id, 'DELETE', null, $personnel->customize_id)` and deletes the temporary `Personnel` record.
  - Updates status to `'cancelled'` and dispatches `VisitorCheckedOut` event.

### 1.3 Background Jobs & Scheduled Automation
- **`app/Jobs/DetectOverstayVisitorsJob.php` (lines 23–81)**:
  - Evaluates checked-in visits with a 15-minute grace window:
    ```php
    $cutoff = Carbon::now()->subMinutes(15);
    $overstayedVisits = Visit::where('status', 'checked_in')
        ->whereNotNull('expected_departure')
        ->where('expected_departure', '<=', $cutoff)
        ->whereNull('overstay_alerted_at')
        ->with(['visitor', 'host'])
        ->get();
    ```
  - Flags visits as `'overstayed'`, records `overstay_alerted_at`, creates a `DeviceAlert` (`alert_type: visitor_overstay`, `severity: WARNING`), and broadcasts `DeviceAlertReceived`.

- **`app/Jobs/ExpireNoShowVisitsJob.php` (lines 21–46)**:
  - Queries unfulfilled visits: `Visit::where('status', 'expected')->whereNotNull('expected_arrival')->where('expected_arrival', '<', $startOfToday)->get()`.
  - Purges pre-provisioned edge camera face profiles via `revokeVisitorFace($visit)`.
  - Transitions visit status to `'no_show'`.

- **`routes/console.php` (lines 13–14)**:
  - Scheduled `DetectOverstayVisitorsJob` to run `everyFifteenMinutes()`.
  - Scheduled `ExpireNoShowVisitsJob` to run `dailyAt('00:00')`.

### 1.4 Route Ordering & Controller Precedence
- **`routes/api.php`**:
  - Line 273: `Route::get('visits/overstayed', [VisitorController::class, 'overstayed'])` is defined strictly BEFORE line 277: `Route::get('visits/{id}', [VisitorController::class, 'showVisit'])`.
  - Line 257: `Route::match(['post', 'put'], 'leave-requests/{id}/cancel', [LeaveController::class, 'cancelRequest'])`.
  - Line 264: `Route::match(['post', 'put'], 'regularization-requests/{id}/cancel', [RegularizationController::class, 'cancel'])`.
  - Line 280: `Route::match(['post', 'put'], 'visits/{id}/cancel', [VisitorController::class, 'cancel'])`.

### 1.5 Independent Verification Tool Execution Results
1. **Tier 1 Feature Coverage (Features 13–19)**:
   ```bash
   php artisan test --filter="test_f1[3-9]"
   ```
   Output:
   ```json
   {"tool":"phpunit","result":"passed","tests":7,"passed":7,"assertions":13,"duration_ms":556}
   ```
2. **Tier 2 Boundary Tests**:
   ```bash
   php artisan test --filter="test_boundary_.*leave|test_boundary_.*visit|test_boundary_.*regularization"
   ```
   Output:
   ```json
   {"tool":"phpunit","result":"passed","tests":10,"passed":10,"assertions":12,"duration_ms":1005}
   ```
3. **Tier 3 & 4 Cross-Feature and Real-World Scenarios**:
   ```bash
   php artisan test --filter="test_cross_leave|test_cross_visitor|test_scenario_7|test_scenario_9"
   ```
   Output:
   ```json
   {"tool":"phpunit","result":"passed","tests":7,"passed":7,"assertions":12,"duration_ms":1035}
   ```
4. **Feature Domain Suites**:
   ```bash
   php artisan test tests/Feature/LeaveAndRegularizationTest.php tests/Feature/VisitorManagementTest.php
   ```
   Output:
   ```json
   {"tool":"phpunit","result":"passed","tests":7,"passed":7,"assertions":37,"duration_ms":693}
   ```
5. **Adversarial & Stress Gate Tests**:
   ```bash
   php artisan test tests/Feature/AdversarialMilestone3Challenger2Test.php tests/Feature/AdversarialMilestone3CspDependencyTest.php tests/Feature/Phase6Milestone3Challenger2Test.php
   ```
   Output:
   ```json
   {"tool":"phpunit","result":"passed","tests":30,"passed":30,"assertions":211,"duration_ms":3094}
   ```
6. **Frontend Production Build**:
   ```bash
   npm run build
   ```
   Output:
   ```
   ✓ built in 2.31s (all chunks rendered and hashed, 0 syntax/bundling errors)
   ```

---

## 2. Logic Chain

1. **State Machine Correctness**:
   - Observations 1.1 and 1.2 demonstrate that leave cancellation cleanly differentiates between `pending` requests (decrementing `pending_days`) and `approved` requests (decrementing `used_days`). Both operations lock the `LeaveBalance` record with `lockForUpdate()`, eliminating concurrent balance corruption.
   - Observation 1.2 confirms that cancelled leaves with punches are evaluated by `AttendanceProcessingService::processDay()`. In accordance with `test_scenario_7`, when an employee works on an originally approved leave day that is subsequently cancelled, their attendance transitions from `'on_leave'` to `'present'`. Future leaves with no punches have their placeholder `'on_leave'` record removed.
   - Observation 1.2 confirms that regularization cancellation strictly permits only `pending` requests to be cancelled, rejecting approved or rejected requests with HTTP 422.

2. **Hardware Perimeter Protection & Visitor Deprovisioning**:
   - Observation 1.2 proves that upon visit cancellation (`cancelVisit`), `VisitorSyncService::revokeVisitorFace` immediately dispatches `SyncPersonnelJob` with action `'DELETE'` and deletes the associated `Personnel` record.
   - Edge camera turnstiles are thus instructed to wipe the visitor's face template immediately, preventing gate entry after a cancelled appointment.

3. **Scheduler & Automation Soundness**:
   - Observation 1.3 shows that `DetectOverstayVisitorsJob` respects a 15-minute grace window (`expected_departure <= now()->subMinutes(15)`). Visits inside the grace window remain `checked_in`, while visits exceeding the grace window become `overstayed`, creating a high-severity `DeviceAlert` and firing real-time event broadcasts.
   - `ExpireNoShowVisitsJob` transitions unfulfilled visits from prior days to `no_show` and purges any biometric whitelist entries.

4. **Routing Priority Guarantee**:
   - Observation 1.4 confirms that `/api/visits/overstayed` is declared prior to `/api/visits/{id}`. This prevents Laravel's route router from treating `"overstayed"` as an integer route parameter for `{id}`.

5. **Integrity & Adversarial Verification**:
   - No mock conditionals (`app()->environment('testing')`) or dummy facade shortcuts exist in any M3 service or job.
   - Repeat attendance punches with identical `customize_id` generate exactly 0 database queries against the `personnel` table due to cache bridging and `$punch->withoutRelations()` decoupling (verified in `Phase6Milestone3Challenger2Test`).
   - All 61 relevant unit, boundary, feature, cross-scenario, and adversarial tests pass without workarounds.

---

## 3. Caveats

- **No Caveats**: All 7 features and acceptance criteria specified in `ORIGINAL_REQUEST.md`, `system-evo.md` (Feature 2), and `orchestrator_11/PROJECT.md` for Milestone M3 have been implemented, verified, and stress-tested.

---

## 4. Conclusion

The Milestone M3 backend implementation for resilient domain lifecycle state machines is **complete, robust, and architecturally compliant**.
- Zero integrity violations were detected.
- All balance transactions, attendance rollbacks, camera face revocations, overstay alerts, and route definitions are working correctly.
- **Verdict**: **`APPROVE`**.

---

## 5. Verification Method

To independently reproduce the verification results:

```bash
# 1. Tier 1 Feature Coverage (Features 13 - 19)
php artisan test --filter="test_f1[3-9]"

# 2. Tier 2 Boundary Tests
php artisan test --filter="test_boundary_.*leave|test_boundary_.*visit|test_boundary_.*regularization"

# 3. Tier 3 & 4 Cross-Feature and Real-World Scenarios
php artisan test --filter="test_cross_leave|test_cross_visitor|test_scenario_7|test_scenario_9"

# 4. Feature and Domain Unit Suites
php artisan test tests/Feature/LeaveAndRegularizationTest.php tests/Feature/VisitorManagementTest.php

# 5. Milestone 3 Adversarial & Challenge Gate Tests
php artisan test tests/Feature/AdversarialMilestone3Challenger2Test.php tests/Feature/AdversarialMilestone3CspDependencyTest.php tests/Feature/Phase6Milestone3Challenger2Test.php

# 6. Frontend Production Build
npm run build
```

**Invalidation Conditions**:
- Any test failure in the commands above.
- Re-ordering route `/api/visits/overstayed` after `/api/visits/{id}` in `routes/api.php`.
- Failure to revoke camera biometric credentials on visit cancellation.
