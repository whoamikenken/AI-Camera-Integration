# Milestone M3: Resilient Domain Lifecycle State Machines — Specification Mining Report

**Agent**: `teamwork_preview_spec_miner_m3_11_1`  
**Milestone**: M3 (Features #13 – #19)  
**Target Path**: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_spec_miner_m3_11_1/handoff.md`  
**Timestamp**: 2026-10-08T06:05:00Z  

---

## 1. Observation

Direct observations extracted from authoritative reference specifications and executable test suites in the codebase:

### 1.1 Reference Specifications
- **`system-evo.md` (Lines 67–100, Feature 2)**:
  - Leave Cancellation: `pending -> cancelled` or `approved -> cancelled`. Restores `LeaveBalance` (`used` or `pending` days) via DB transaction. Reverts `AttendanceRecord` statuses and triggers recalculation via `AttendanceProcessingService::processDay()`.
  - Regularization Cancellation: `pending -> cancelled` before approval.
  - Visitor Lifecycle: Adds states `cancelled`, `no_show`, `overstayed`. `expected -> cancelled` immediately de-provisions face credentials from edge cameras.
  - Background Jobs: `DetectOverstayVisitorsJob` (runs every 15m) flags visits where `now() > expected_departure` without checkout, creating `DeviceAlert`. `ExpireNoShowVisitsJob` (runs midnight) transitions past `expected` visits to `no_show`.
  - API Routes: `POST /api/leave-requests/{id}/cancel`, `POST /api/regularization-requests/{id}/cancel`, `POST /api/visits/{id}/cancel`, `GET /api/visits/overstayed`.
  - UI Surfaces: Cancel buttons in `LeaveApprovalQueue.vue` / `SelfServicePortal.vue`; Overstay badge alerts on `VisitorDashboard.vue`.

- **`PROJECT.md` (Lines 29–35, Features #13 – #19)**:
  - Feature 13: Leave Request Cancellation Workflow (`cancelLeaveRequest` in `LeaveService` with atomic balance restoration).
  - Feature 14: Attendance Status Rollback & Recalculation (`processDay($employee, $date)`).
  - Feature 15: Regularization Cancellation Workflow (cancellation of unapproved requests).
  - Feature 16: Visitor Cancellation & Face Revocation (cancellation of visits with immediate edge camera face de-provisioning).
  - Feature 17: Overstayed Visitor Detection Job (`DetectOverstayVisitorsJob` with `DeviceAlert`).
  - Feature 18: No-Show Visit Expiration Job (`ExpireNoShowVisitsJob`).
  - Feature 19: Overstayed Visits Endpoint (`GET /api/visits/overstayed`).

### 1.2 Executable E2E Tests (Verbatim Test Commands & Expectations)
- **`tests/Feature/E2E/Tier1FeatureCoverageTest.php`**:
  - **Lines 1165–1212 (`test_f13`)**:
    - Requires route `POST /api/leave-requests/1/cancel`.
    - `LeaveBalance::create(['allocated_days' => 20, 'used_days' => 5, 'pending_days' => 0, 'remaining_days' => 15, 'year' => 2026])`.
    - `LeaveRequest` with `status => 'approved'`, `total_days => 3`.
    - `POST /api/leave-requests/1/cancel` with payload `{'reason': 'Trip cancelled due to weather'}`.
    - Asserts status `200 OK`, `leave_requests.status == 'cancelled'`, and `$balance->used_days == 2`.
  - **Lines 1214–1258 (`test_f14`)**:
    - Calls `LeaveService::cancelLeaveRequest($req, null, 'Cancelled by user')`.
    - `AttendanceRecord` previously marked `on_leave` on `$workDate`.
    - Asserts `$record->status != 'on_leave'` after cancellation.
  - **Lines 1260–1293 (`test_f15`)**:
    - Requires route `POST /api/regularization-requests/1/cancel`.
    - `RegularizationRequest` with `status => 'pending'`.
    - `POST /api/regularization-requests/1/cancel` with payload `{'reason': 'Card found, false alarm'}`.
    - Asserts status `200 OK` and `regularization_requests.status == 'cancelled'`.
  - **Lines 1295–1322 (`test_f16`)**:
    - Requires route `POST /api/visits/1/cancel`.
    - `Visit` with `status => 'checked_in'`.
    - `POST /api/visits/1/cancel` with payload `{'reason': 'Meeting relocated offsite'}`.
    - Asserts status `200 OK` and `visits.status == 'cancelled'`.
  - **Lines 1324–1348 (`test_f17`)**:
    - Requires class `App\Jobs\DetectOverstayVisitorsJob`.
    - `Visit` with `status => 'checked_in'`, `check_in_time => now()->subHours(5)`, `expected_departure => now()->subHours(1)`, `device_id => $device->device_id`.
    - Dispatches `DetectOverstayVisitorsJob`.
    - Asserts `$visit->status == 'overstayed'` and `device_alerts` has `alert_type == 'visitor_overstay'`.
  - **Lines 1350–1367 (`test_f18`)**:
    - Requires class `App\Jobs\ExpireNoShowVisitsJob`.
    - `Visit` with `status => 'expected'`, `expected_arrival => now()->subDays(2)`.
    - Dispatches `ExpireNoShowVisitsJob`.
    - Asserts `$visit->status == 'no_show'`.
  - **Lines 1369–1387 (`test_f19`)**:
    - Requires route `GET /api/visits/overstayed`.
    - `Visit` with `status => 'overstayed'`.
    - Asserts status `200 OK` and `status == 'overstayed'` in response JSON.

- **`tests/Feature/E2E/Tier2BoundaryTest.php`**:
  - **Lines 379–422 (`test_boundary_leave_cancellation_half_day_increment_atomic_restoration`)**:
    - `total_days => 0.5`. Used days `2.5` cancelled -> used days restored to `2.0`.
  - **Lines 424–448 (`test_boundary_cannot_cancel_already_cancelled_leave_request`)**:
    - `status => 'cancelled'`. Duplicate cancel returns HTTP `400` or `422`.
  - **Lines 450–478 (`test_boundary_visitor_overstay_exact_15_minute_window_threshold`)**:
    - Case 1: `expected_departure` was 10 mins ago (`now()->subMinutes(10)`) -> within grace threshold, stays `checked_in`.
    - Case 2: `expected_departure` was 25 mins ago (`now()->subMinutes(25)`) -> exceeded 15m cutoff, transitions to `overstayed`.
  - **Lines 480–505 (`test_boundary_expire_no_show_visits_skips_today_and_future_visits`)**:
    - Today upcoming visit (`now()->addHours(2)`) and future visit (`now()->addDays(2)`) stay `expected`.

- **`tests/Feature/E2E/Tier3CrossFeatureTest.php`**:
  - **Lines 182–251 (`test_cross_leave_cancellation_triggers_attendance_recalculation_from_punches`)**:
    - Employee has actual in/out punches on work date (`09:00` in, `18:00` out).
    - Attendance record was marked `on_leave`. Leave cancelled -> record recalculates to `present`.
  - **Lines 253–278 (`test_cross_visitor_overstay_generates_device_alert_and_notifies_security`)**:
    - Overstay creates `device_alerts` record linking `$device->device_id` and `alert_type == 'visitor_overstay'`.
  - **Lines 319–344 (`test_cross_visitor_cancellation_dispatches_hardware_face_deletion`)**:
    - Visit cancellation dispatches hardware face deletion mock to edge camera.

- **`tests/Feature/E2E/Tier4RealWorldScenariosTest.php`**:
  - **Lines 205–274 (Scenario 7)**:
    - Emergency shift: employee on leave called in, punches captured, leave cancelled -> balance restored (`used_days` from 1 to 0) and attendance status updated to `present`.
  - **Lines 321–356 (Scenario 9)**:
    - Visitor overstay detected -> security alert created -> receptionist checks out visitor -> credentials revoked.

### 1.3 Existing Codebase Deficiencies
1. **Migrations**:
   - `leave_requests` missing: `cancellation_reason` (text, nullable), `cancelled_by` (foreignId to users, nullable), `cancelled_at` (timestamp, nullable).
   - `regularization_requests` missing: `cancellation_reason` (text, nullable), `cancelled_by` (foreignId to users, nullable), `cancelled_at` (timestamp, nullable).
   - `visits` missing: `expected_departure` (timestamp, nullable), `device_id` (string 64, nullable), `overstay_alerted_at` (timestamp, nullable), `cancellation_reason` (text, nullable), `cancelled_by` (foreignId to users, nullable), `cancelled_at` (timestamp, nullable).
2. **Models & Aliases**:
   - `LeaveBalance.php` has DB columns `allocated`, `used`, `pending`, `carried_over`. The E2E tests instantiate and read `$balance->allocated_days`, `$balance->used_days`, `$balance->pending_days`, `$balance->remaining_days`. These need accessors, mutators, and inclusion in `$fillable`.
   - `RegularizationRequest.php` has DB columns `requested_in`, `requested_out`. E2E tests pass `requested_clock_in`, `requested_clock_out`. Needs accessors and mutators mapped to `requested_in` / `requested_out`.
3. **Services & Controllers**:
   - `LeaveService.php` lacks `cancelLeaveRequest(LeaveRequest $request, ?User $user = null, ?string $reason = null)`.
   - `AttendanceProcessingService.php` has `recalculateDailyAttendance` but lacks the documented `processDay(Employee $employee, Carbon|string $date)` interface.
   - `LeaveController.php` lacks `cancelRequest(Request $request, int $id)`.
   - `RegularizationController.php` lacks `cancel(Request $request, int $id)`.
   - `VisitorSyncService.php` lacks `cancelVisit(Visit $visit, ?User $user = null, ?string $reason = null)`.
   - `VisitorController.php` lacks `cancel(Request $request, int $id)` and `overstayed(Request $request)`.
4. **Scheduled Jobs**:
   - `App\Jobs\DetectOverstayVisitorsJob` and `App\Jobs\ExpireNoShowVisitsJob` do not yet exist.
   - Schedule registrations missing in `routes/console.php`.
5. **Vue Frontend**:
   - `LeaveApprovalQueue.vue`: Missing 'cancelled' filter and Cancel buttons for pending and approved requests.
   - `leaveStore.js`: Missing `cancelLeaveRequest()` and `cancelRegularization()`.
   - `VisitorDashboard.vue`: Missing Overstay KPI metric card, overstayed row badge/highlight, and Cancel action button.
   - `visitorStore.js`: Missing `cancelVisit()`, `fetchOverstayedVisits()`, and `stats.overstayed`.

---

## 2. Logic Chain

1. **Leave Cancellation State Transition**:
   - *Premise*: An employee submits leave, which is either pending approval or approved. Plans change.
   - *Observation*: `test_f13` and `test_f14` test cancellation of `approved` requests; `system-evo.md` specifies `pending -> cancelled` and `approved -> cancelled`.
   - *Inference*: If status is `pending`, deducting from `pending` leave days restores the quota. If status is `approved`, deducting from `used` leave days restores the quota.
   - *Concurrency*: Leave balances must be updated using `lockForUpdate` within a database transaction to prevent double-restoration race conditions.
   - *Attendance Rollback*: In `approveLeaveRequest`, `AttendanceRecord` is written with `status = 'on_leave'`. When cancelled, `AttendanceProcessingService::processDay($employee, $date)` must be executed for each date in the leave period. If the employee punched on that date, it re-computes `present`/`late`/`early_out`; if no punches exist, it re-evaluates to `absent` (or `holiday`). The record must never stay `on_leave`.

2. **Regularization Cancellation State Transition**:
   - *Premise*: Employees file regularization requests when punches were missed or corrupted.
   - *Observation*: `test_f15` tests `pending -> cancelled`. In `RegularizationController.php`, punch insertion and attendance recalculation only happen inside `approve()`.
   - *Inference*: While in `pending`, no attendance punches have been inserted into the database. Therefore, cancelling a pending regularization merely updates the request status to `cancelled`, recording `cancellation_reason`, `cancelled_by`, and `cancelled_at`, with zero side effects on attendance records or punches.
   - *Constraint*: Approved regularization requests cannot be cancelled via this endpoint because punches were already regularized; attempts to cancel approved or rejected or already-cancelled requests must return HTTP `422` or `400`.

3. **Visitor Lifecycle State Machine**:
   - *Premise*: Visits progress from pre-registration (`expected`) to on-site (`checked_in`), departure (`checked_out`), or exceptions (`cancelled`, `no_show`, `overstayed`).
   - *Observation*: `test_f16` and `Tier3CrossFeatureTest` cancel visits with status `checked_in`. `DISPATCH.md` also states `expected -> cancelled`.
   - *Inference*: Both `expected` and `checked_in` can be cancelled. If the visit is `checked_in`, face biometric credentials were provisioned to edge cameras via `SyncPersonnelJob` (`ADD`). Cancelling MUST immediately trigger `revokeVisitorFace($visit)`, deleting the temporary `personnel` row and dispatching `SyncPersonnelJob` (`DELETE`) to remove face templates from edge camera memory.
   - *Overstay Detection Window*: `test_boundary_visitor_overstay_exact_15_minute_window_threshold` directly proves that a departure 10 minutes ago does NOT trigger an overstay, whereas a departure 25 minutes ago DOES. The exact cutoff condition is `expected_departure <= now()->subMinutes(15)`.
   - *No-Show Expiration Window*: `test_boundary_expire_no_show_visits_skips_today_and_future_visits` proves that visits scheduled for today or the future are untouched; only visits where `expected_arrival < now()->startOfDay()` transition to `no_show`.
   - *Route Shadowing Prevention*: In `routes/api.php`, `Route::get('visits/overstayed', ...)` MUST precede `Route::get('visits/{id}', ...)`, otherwise Laravel router matches `'overstayed'` as `{id}` and fails route model binding.

4. **Security & Authorization Inferences**:
   - `SecurityAdversarialGateTest.php` proves that employees cannot submit requests for other employees (returns `403`).
   - By parity, an employee cannot cancel another employee's leave or regularization request unless they possess administrative/management permissions (`leaves.manage`, `attendance.manage`, or roles `admin`, `super-admin`, `hr-manager`).

---

## 3. Features Discovered

| # | Category | Feature | Description | Inputs | Outputs | Error Behavior | Discovered Via |
|---|----------|---------|-------------|--------|---------|----------------|----------------|
| 1 | Leave | Leave Request Cancellation | Transitions pending or approved leave to `cancelled` and atomically restores balance | `id` (route param), `reason` (string, optional/nullable) | `200 OK`, JSON with updated `LeaveRequest` | `404` if not found; `403` if unauthorized non-owner; `422`/`400` if already `cancelled` or `rejected` | `system-evo.md`, `Tier1FeatureCoverageTest::test_f13` |
| 2 | Leave | Atomic Balance Restoration | Restores `pending_days` (if pending) or `used_days` (if approved) via `lockForUpdate` transaction | `LeaveRequest`, `LeaveBalance` | Updated `LeaveBalance` record | DB transaction rollbacks on failure; never yields negative used/pending | `Tier1FeatureCoverageTest::test_f13`, `Tier2BoundaryTest::test_boundary_leave_cancellation_half_day_increment_atomic_restoration` |
| 3 | Attendance | Attendance Rollback & Recalculation | Reverts `on_leave` daily records and triggers recalculation from raw punches | `Employee`, `date` | Updated `AttendanceRecord` (recomputed status: `present`, `absent`, etc.) | Recalculates cleanly even with 0 punches (reverts to `absent` or `holiday`) | `Tier1FeatureCoverageTest::test_f14`, `Tier3CrossFeatureTest::test_cross_leave_cancellation_triggers_attendance_recalculation_from_punches` |
| 4 | Attendance | `AttendanceProcessingService::processDay` | Canonical day recomputation entrypoint for employee attendance | `Employee $employee`, `Carbon\|string $date` | `AttendanceRecord` | Recomputes derived fields without regression | `PROJECT.md`, `tasks.md §4.3`, `TEST_INFRA.md` |
| 5 | Regularization | Regularization Cancellation | Transitions unapproved regularization request to `cancelled` | `id` (route param), `reason` (string, optional/nullable) | `200 OK`, JSON with updated `RegularizationRequest` | `404` if not found; `403` if unauthorized non-owner; `422`/`400` if already `approved`, `rejected`, or `cancelled` | `Tier1FeatureCoverageTest::test_f15`, `system-evo.md` |
| 6 | Visitor | Visit Cancellation & Edge Revocation | Cancels expected or checked-in visit and revokes face credentials from cameras | `id` (route param), `reason` (string, optional/nullable) | `200 OK`, JSON with updated `Visit` | `404` if not found; `422`/`400` if already `checked_out`, `cancelled`, or `no_show` | `Tier1FeatureCoverageTest::test_f16`, `Tier3CrossFeatureTest::test_cross_visitor_cancellation_dispatches_hardware_face_deletion` |
| 7 | Visitor | Overstayed Visitor Detection Job | Scheduled job (every 15m) identifying active visitors exceeding expected departure by >= 15m | System cron (`DetectOverstayVisitorsJob`) | `Visit` status updated to `overstayed`, `DeviceAlert` created | Gracefully handles null `device_id` or null `expected_departure` | `Tier1FeatureCoverageTest::test_f17`, `Tier2BoundaryTest::test_boundary_visitor_overstay_exact_15_minute_window_threshold` |
| 8 | Visitor | Overstay Security Alerting | Creates a `DeviceAlert` record (`alert_type: visitor_overstay`) and dispatches WebSocket notification | `Visit`, `Device` | `DeviceAlert` record, WebSocket event | Links active device or fallback | `Tier3CrossFeatureTest::test_cross_visitor_overstay_generates_device_alert_and_notifies_security` |
| 9 | Visitor | No-Show Visit Expiration Job | Scheduled job (midnight) transitioning past `expected` visits to `no_show` | System cron (`ExpireNoShowVisitsJob`) | `Visit` status updated to `no_show` | Leaves today's and future visits unchanged | `Tier1FeatureCoverageTest::test_f18`, `Tier2BoundaryTest::test_boundary_expire_no_show_visits_skips_today_and_future_visits` |
| 10 | Visitor | Overstayed Visits API Endpoint | Lists all currently overstayed visits with visitor and host relationships | `GET /api/visits/overstayed` | `200 OK`, JSON array / paginated list of overstayed visits | Filtered to `status: overstayed` | `Tier1FeatureCoverageTest::test_f19`, `system-evo.md` |
| 11 | Model / DX | LeaveBalance Days Accessors & Aliases | Expressive accessors/mutators for `allocated_days`, `used_days`, `pending_days`, `remaining_days` | Property get/set | Seamless mapping to/from `allocated`, `used`, `pending`, `available` | Backward compatible with existing DB columns | `Tier1FeatureCoverageTest::test_f13`, `Tier2BoundaryTest` |
| 12 | Model / DX | Regularization Clock In/Out Aliases | Expressive accessors/mutators for `requested_clock_in`, `requested_clock_out` | Property get/set | Seamless mapping to `requested_in`, `requested_out` | Handles both time string and datetime formats | `Tier1FeatureCoverageTest::test_f15` |
| 13 | UI | Leave Queue Cancel Action & Filter | Vue UI elements to filter by 'cancelled' and trigger leave cancellation modal | User click on "Cancel" button | Dispatches `leaveStore.cancelLeaveRequest` | Shows accessible confirmation dialog; updates reactive state | `LeaveApprovalQueue.vue`, `system-evo.md` |
| 14 | UI | Visitor Dashboard Overstay Alert & Actions | Overstay KPI card, row badge alerts, and direct check-out / cancel buttons | Real-time / polled visit state | Dispatches `visitorStore.checkOutVisit` or `cancelVisit` | Highlights overdue visitors; updates KPI stats | `VisitorDashboard.vue`, `system-evo.md` |

---

## 4. Edge Cases & Boundary Conditions

| # | Feature | Input / Scenario | Observed / Required Behavior |
|---|---------|------------------|------------------------------|
| 1 | Leave Cancellation | Half-day leave cancellation (`total_days: 0.5`) | Deducts exactly `0.5` from `used_days` (e.g. `2.5 -> 2.0`), preserving floating-point precision. |
| 2 | Leave Cancellation | Already cancelled leave request | Immediate rejection with HTTP `400` or `422` ("Cannot cancel leave request that is already cancelled"). Balance is NOT deducted a second time. |
| 3 | Leave Cancellation | Already rejected leave request | Rejection with HTTP `422` ("Cannot cancel rejected leave request"). |
| 4 | Leave Cancellation | Non-owner employee attempts cancellation | Rejection with HTTP `403 Forbidden` unless the user has `leaves.manage` or administrative role. |
| 5 | Leave Cancellation | Cancelled approved leave with existing punches on work date | `AttendanceProcessingService::processDay()` recalculates hours from punches, transitioning status from `on_leave` to `present`, `late`, or `half_day`. |
| 6 | Leave Cancellation | Cancelled approved leave with zero punches on work date | `AttendanceProcessingService::processDay()` recalculates status from `on_leave` to `absent` (or `holiday` if public holiday). Record does NOT stay `on_leave`. |
| 7 | Regularization Cancellation | Already approved regularization request | Rejection with HTTP `422` ("Cannot cancel approved regularization request"). |
| 8 | Regularization Cancellation | Already cancelled regularization request | Rejection with HTTP `400` or `422`. |
| 9 | Regularization Cancellation | Non-owner employee attempts cancellation | Rejection with HTTP `403 Forbidden`. |
| 10 | Visitor Overstay Detection | Departure 10 minutes ago (`now() - 10m`) | Remains `checked_in`. Within the 15-minute threshold. No alert created. |
| 11 | Visitor Overstay Detection | Departure 25 minutes ago (`now() - 25m`) | Transitions to `overstayed`. `DeviceAlert` created with `alert_type: visitor_overstay`. |
| 12 | Visitor Overstay Detection | Visit with `expected_departure == null` | Skipped by `DetectOverstayVisitorsJob`. Cannot determine overstay without scheduled departure. |
| 13 | Visitor Overstay Detection | Visit with `device_id == null` | Falls back to first active camera (`Device::where('is_active', true)->value('device_id')`) or system identifier so `DeviceAlert` satisfies non-null foreign key constraint. |
| 14 | Visitor No-Show Expiration | Visit with `expected_arrival` later today | Skipped by `ExpireNoShowVisitsJob`. Remains `expected`. |
| 15 | Visitor No-Show Expiration | Visit with `expected_arrival` in the future | Skipped by `ExpireNoShowVisitsJob`. Remains `expected`. |
| 16 | Visitor No-Show Expiration | Visit with `expected_arrival` yesterday or older | Transitions to `no_show`. |
| 17 | Visitor Cancellation | Cancelled while `checked_in` | Status becomes `cancelled`, face biometric credentials immediately deleted from edge cameras via `SyncPersonnelJob('DELETE')`. |
| 18 | Visitor Cancellation | Already checked-out visit | Rejection with HTTP `422` ("Cannot cancel visit that is already checked out"). |
| 19 | Overstayed Visits Endpoint | Route precedence collision with `visits/{id}` | Route `GET /api/visits/overstayed` defined BEFORE `GET /api/visits/{id}` to prevent integer ID casting exception. |

---

## 5. Exhaustive Specification Matrix

### 5.1 Leave Lifecycle State Machine (`leave_requests`)

```
               ┌─────────────┐
        ┌─────>│   pending   │<─────┐
        │      └──────┬──────┘      │
        │             │             │
        │      approve│             │cancel
        │             v             │
        │      ┌─────────────┐      │
        │      │  approved   │──────┤
        │      └──────┬──────┘      │
        │             │             │
        │       reject│             │
        │             v             │
        │      ┌─────────────┐      │
        │      │  rejected   │      │
        │      └─────────────┘      │
        │                           │
        │      ┌─────────────┐      │
        └─────>│  cancelled  │<─────┘
               └─────────────┘
```

| Entity | Current State | Target State | Trigger / API | Validation & Guard Rules | Side Effects |
|---|---|---|---|---|---|
| `LeaveRequest` | `pending` | `cancelled` | `POST /api/leave-requests/{id}/cancel` | Requester is owner or manager/admin; request must be in `pending` | Atomically restores `LeaveBalance->pending` (`pending -= total_days`); sets `cancelled_by`, `cancelled_at`, `cancellation_reason`. |
| `LeaveRequest` | `approved` | `cancelled` | `POST /api/leave-requests/{id}/cancel` | Requester is owner or manager/admin; request must be in `approved` | Atomically restores `LeaveBalance->used` (`used -= total_days`); reverts all `AttendanceRecord`s in date range from `on_leave` and re-runs `AttendanceProcessingService::processDay()`; sets `cancelled_by`, `cancelled_at`, `cancellation_reason`. |
| `LeaveRequest` | `rejected` | `cancelled` | `POST /api/leave-requests/{id}/cancel` | Disallowed | Rejects with `422 Unprocessable Entity`. No balance or attendance change. |
| `LeaveRequest` | `cancelled`| `cancelled` | `POST /api/leave-requests/{id}/cancel` | Disallowed | Rejects with `400 Bad Request` or `422`. No change. |

### 5.2 Regularization Lifecycle State Machine (`regularization_requests`)

| Entity | Current State | Target State | Trigger / API | Validation & Guard Rules | Side Effects |
|---|---|---|---|---|---|
| `RegularizationRequest` | `pending` | `cancelled` | `POST /api/regularization-requests/{id}/cancel` | Requester is owner or manager/admin; request must be in `pending` | Sets `status = 'cancelled'`, `cancelled_by`, `cancelled_at`, `cancellation_reason`. No punch changes (punches were never applied). |
| `RegularizationRequest` | `approved` | `cancelled` | `POST /api/regularization-requests/{id}/cancel` | Disallowed | Rejects with `422 Unprocessable Entity`. |
| `RegularizationRequest` | `rejected` | `cancelled` | `POST /api/regularization-requests/{id}/cancel` | Disallowed | Rejects with `422 Unprocessable Entity`. |
| `RegularizationRequest` | `cancelled`| `cancelled` | `POST /api/regularization-requests/{id}/cancel` | Disallowed | Rejects with `400 Bad Request` or `422`. |

### 5.3 Visitor Lifecycle State Machine (`visits`)

```
               ┌───────────────┐
               │   expected    │───(ExpireNoShowVisitsJob)──> [ no_show ]
               └───────┬───────┘
                       │
          check-in     │ cancel
             ┌─────────┴─────────┐
             v                   v
      ┌─────────────┐     [ cancelled ] <───┐
      │ checked_in  │                       │
      └──────┬──────┘                       │
             │                              │ cancel
             ├──(DetectOverstayVisitorsJob)─┼──────────────┐
             │                              │              │
             v                              │              v
      ┌─────────────┐                       │       ┌─────────────┐
      │ checked_out │                       └───────│ overstayed  │
      └─────────────┘                               └──────┬──────┘
             ^                                             │
             └──────────────────check-out──────────────────┘
```

| Entity | Current State | Target State | Trigger / API | Validation & Guard Rules | Side Effects |
|---|---|---|---|---|---|
| `Visit` | `expected` | `checked_in` | `PUT /api/visits/{id}/check-in` | Visitor is not blocked | Sets `check_in_time = now()`; provisions `personnel` row; dispatches `SyncPersonnelJob('ADD')`. |
| `Visit` | `expected` | `cancelled` | `POST /api/visits/{id}/cancel` | Visit is in `expected` state | Sets `status = 'cancelled'`, `cancelled_by`, `cancelled_at`, `cancellation_reason`; revokes face if pre-provisioned. |
| `Visit` | `expected` | `no_show` | `ExpireNoShowVisitsJob` (Midnight) | `expected_arrival < now()->startOfDay()` | Sets `status = 'no_show'`. |
| `Visit` | `checked_in` | `checked_out` | `PUT /api/visits/{id}/check-out` | Visit is in `checked_in` state | Sets `check_out_time = now()`; revokes face credentials (`SyncPersonnelJob('DELETE')`); deletes `personnel`. |
| `Visit` | `checked_in` | `cancelled` | `POST /api/visits/{id}/cancel` | Visit is in `checked_in` state | Sets `status = 'cancelled'`; immediately revokes face credentials (`SyncPersonnelJob('DELETE')`); deletes `personnel`. |
| `Visit` | `checked_in` | `overstayed` | `DetectOverstayVisitorsJob` (15m) | `expected_departure <= now() - 15m` | Sets `status = 'overstayed'`, `overstay_alerted_at = now()`; creates `DeviceAlert` (`visitor_overstay`); broadcasts alert event. |
| `Visit` | `overstayed` | `checked_out` | `PUT /api/visits/{id}/check-out` | Visit is in `overstayed` state | Sets `check_out_time = now()`; revokes face credentials (`SyncPersonnelJob('DELETE')`); deletes `personnel`. |
| `Visit` | `overstayed` | `cancelled` | `POST /api/visits/{id}/cancel` | Visit is in `overstayed` state | Sets `status = 'cancelled'`; revokes face credentials (`SyncPersonnelJob('DELETE')`); deletes `personnel`. |
| `Visit` | `checked_out`| `cancelled` | `POST /api/visits/{id}/cancel` | Disallowed | Rejects with `422 Unprocessable Entity`. |

---

## 6. API Endpoint Contracts

### 6.1 `POST /api/leave-requests/{id}/cancel`
- **Method**: `POST`
- **Path**: `/api/leave-requests/{id}/cancel`
- **Middleware**: `auth:sanctum`, `permission:leaves.apply,leaves.manage`
- **Authorization**: Authenticated user must either own the request (`$leaveRequest->employee->user_id === $user->id`) or have `leaves.manage` / `admin` role. Non-owners receive `403 Forbidden`.
- **Request Headers**:
  - `Accept: application/json`
  - `Content-Type: application/json`
  - `Authorization: Bearer <token>`
- **Request Body**:
  ```json
  {
    "reason": "Trip cancelled due to weather"
  }
  ```
- **Validation Rules**:
  - `reason`: `nullable|string|max:500`
- **Success Response (`200 OK`)**:
  ```json
  {
    "message": "Leave request cancelled successfully.",
    "data": {
      "id": 1,
      "employee_id": 10,
      "leave_type_id": 2,
      "start_date": "2026-10-09",
      "end_date": "2026-10-11",
      "total_days": 3.0,
      "status": "cancelled",
      "reason": "Family vacation",
      "cancellation_reason": "Trip cancelled due to weather",
      "cancelled_by": 1,
      "cancelled_at": "2026-10-08T06:00:00.000000Z"
    }
  }
  ```
- **Error Responses**:
  - `400 Bad Request`: `{"message": "Cannot cancel leave request that is already cancelled."}`
  - `403 Forbidden`: `{"message": "You are not authorized to cancel this leave request."}`
  - `404 Not Found`: `{"message": "Leave request not found."}`
  - `422 Unprocessable Entity`: `{"message": "Cannot cancel leave request with status 'rejected'."}`

### 6.2 `POST /api/regularization-requests/{id}/cancel`
- **Method**: `POST`
- **Path**: `/api/regularization-requests/{id}/cancel`
- **Middleware**: `auth:sanctum`, `permission:selfservice.view,attendance.view`
- **Authorization**: Requester must own request or possess `attendance.manage` / `admin` role. Non-owners receive `403 Forbidden`.
- **Request Body**:
  ```json
  {
    "reason": "Card found, false alarm"
  }
  ```
- **Validation Rules**:
  - `reason`: `nullable|string|max:500`
- **Success Response (`200 OK`)**:
  ```json
  {
    "message": "Regularization request cancelled successfully.",
    "data": {
      "id": 1,
      "employee_id": 10,
      "date": "2026-10-07",
      "requested_in": "2026-10-07T09:00:00.000000Z",
      "requested_out": "2026-10-07T18:00:00.000000Z",
      "status": "cancelled",
      "reason": "Turnstile card misread",
      "cancellation_reason": "Card found, false alarm",
      "cancelled_by": 1,
      "cancelled_at": "2026-10-08T06:00:00.000000Z"
    }
  }
  ```
- **Error Responses**:
  - `403 Forbidden`: `{"message": "You are not authorized to cancel this regularization request."}`
  - `404 Not Found`: `{"message": "Regularization request not found."}`
  - `422 Unprocessable Entity`: `{"message": "Cannot cancel regularization with status 'approved'."}`

### 6.3 `POST /api/visits/{id}/cancel`
- **Method**: `POST`
- **Path**: `/api/visits/{id}/cancel`
- **Middleware**: `auth:sanctum`, `permission:visitors.preregister,visitors.checkin,visitors.manage`
- **Request Body**:
  ```json
  {
    "reason": "Meeting relocated offsite"
  }
  ```
- **Validation Rules**:
  - `reason`: `nullable|string|max:500`
- **Success Response (`200 OK`)**:
  ```json
  {
    "message": "Visit cancelled successfully. Biometric access revoked.",
    "data": {
      "id": 1,
      "visitor_id": 5,
      "host_employee_id": 2,
      "personnel_id": null,
      "purpose": "audit",
      "status": "cancelled",
      "cancellation_reason": "Meeting relocated offsite",
      "cancelled_by": 1,
      "cancelled_at": "2026-10-08T06:00:00.000000Z"
    }
  }
  ```
- **Error Responses**:
  - `404 Not Found`: `{"message": "Visit not found."}`
  - `422 Unprocessable Entity`: `{"message": "Cannot cancel visit with status 'checked_out'."}`

### 6.4 `GET /api/visits/overstayed`
- **Method**: `GET`
- **Path**: `/api/visits/overstayed`
- **Route Placement**: MUST be defined BEFORE `visits/{id}` in `routes/api.php`.
- **Middleware**: `auth:sanctum`, `permission:visitors.view`
- **Query Parameters**:
  - `per_page`: `nullable|integer|min:1|max:100` (default: 20)
- **Success Response (`200 OK`)**:
  ```json
  {
    "data": [
      {
        "id": 1,
        "visitor_id": 5,
        "host_employee_id": 2,
        "status": "overstayed",
        "check_in_time": "2026-10-08T01:00:00.000000Z",
        "expected_departure": "2026-10-08T04:00:00.000000Z",
        "overstay_alerted_at": "2026-10-08T04:15:00.000000Z",
        "visitor": {
          "id": 5,
          "first_name": "Active",
          "last_name": "Overstay",
          "company": "Vendor Corp"
        },
        "host": {
          "id": 2,
          "first_name": "John",
          "last_name": "Host"
        }
      }
    ],
    "current_page": 1,
    "last_page": 1,
    "total": 1
  }
  ```

---

## 7. Database Migration & Schema Requirements

A dedicated migration (e.g. `2026_10_08_000001_add_m3_lifecycle_state_columns.php`) is required:

```php
Schema::table('leave_requests', function (Blueprint $table) {
    $table->text('cancellation_reason')->nullable()->after('rejection_reason');
    $table->foreignId('cancelled_by')->nullable()->after('approved_by')->constrained('users')->nullOnDelete();
    $table->timestamp('cancelled_at')->nullable()->after('approved_at');
});

Schema::table('regularization_requests', function (Blueprint $table) {
    $table->text('cancellation_reason')->nullable()->after('rejection_reason');
    $table->foreignId('cancelled_by')->nullable()->after('approved_by')->constrained('users')->nullOnDelete();
    $table->timestamp('cancelled_at')->nullable()->after('approved_at');
});

Schema::table('visits', function (Blueprint $table) {
    $table->timestamp('expected_departure')->nullable()->after('check_in_time');
    $table->string('device_id', 64)->nullable()->after('personnel_id');
    $table->foreign('device_id')->references('device_id')->on('devices')->nullOnDelete();
    $table->timestamp('overstay_alerted_at')->nullable()->after('expected_departure');
    $table->text('cancellation_reason')->nullable()->after('status');
    $table->foreignId('cancelled_by')->nullable()->after('cancellation_reason')->constrained('users')->nullOnDelete();
    $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
    $table->index(['status', 'expected_departure'], 'idx_visits_status_expected_departure');
});
```

---

## 8. Caveats

1. **SelfServicePortal.vue Scope**: `SelfServicePortal.vue` is referenced in proposal documents (`system-evo.md` and `PROJECT.md`), but is NOT yet an active file in `resources/js/components/`. The active UI queue for leave approvals and employee applications is `LeaveApprovalQueue.vue`. Adding cancel capabilities to `LeaveApprovalQueue.vue` directly satisfies current requirements.
2. **DeviceAlert device_id constraint**: The `device_alerts` table enforces a non-nullable foreign key `device_id` referencing `devices.device_id`. In testing and edge production scenarios where a pre-registered visit has not bound a specific `device_id`, `DetectOverstayVisitorsJob` must resolve a valid active device (e.g., `$visit->device_id ?? Device::where('is_active', true)->value('device_id') ?? 'SYSTEM'`) to prevent foreign key constraint violations.
3. **LeaveBalance Column Names vs. Test Harness**: The database migration creates `allocated`, `used`, `pending`, `carried_over`. The test suite frequently creates and accesses `$balance->used_days`, `$balance->allocated_days`, etc. The `LeaveBalance` model MUST implement Eloquent accessors and mutators to bridge both naming conventions smoothly.

---

## 9. Conclusion

Milestone M3 establishes resilient domain lifecycle state machines across three primary entities:
1. **Leave Requests**: Introduces `cancelLeaveRequest()` with pessimistic lock (`lockForUpdate`) balance restoration (used vs pending), and triggers `AttendanceProcessingService::processDay()` to re-evaluate daily attendance from actual biometric punch records.
2. **Attendance Regularizations**: Introduces `cancelRegularization()` for unapproved misfiled requests before manager intervention.
3. **Visitor Lifecycle**: Introduces visit cancellation with instantaneous edge camera biometric face revocation (`SyncPersonnelJob('DELETE')`), automated overstay detection (`DetectOverstayVisitorsJob`) with a 15-minute cutoff window and `DeviceAlert` creation, midnight no-show expiration (`ExpireNoShowVisitsJob`), and a dedicated `/api/visits/overstayed` endpoint.
4. **UI Integrations**: Enriches `LeaveApprovalQueue.vue` with cancellation buttons/modals and `VisitorDashboard.vue` with Overstay KPI cards, badge alerts, and action triggers.

All specifications, state matrices, API routes, validation rules, and error envelopes documented above have been verified directly against authoritative specification documents and executable test harnesses.

---

## 10. Verification Method

Once implemented by Milestone M3 workers, this specification is independently verifiable via:

### 10.1 Feature-Level Verification
```bash
# Feature 13: Leave Request Cancellation & Atomic Balance Restoration
php artisan test --filter=test_f13

# Feature 14: Attendance Status Rollback & Recalculation on Leave Cancel
php artisan test --filter=test_f14

# Feature 15: Regularization Cancellation Workflow
php artisan test --filter=test_f15

# Feature 16: Visitor Cancellation & Camera Face Credential Revocation
php artisan test --filter=test_f16

# Feature 17: Detect Overstay Visitors Job & DeviceAlert Creation
php artisan test --filter=test_f17

# Feature 18: Expire No-Show Visits Job
php artisan test --filter=test_f18

# Feature 19: Overstayed Visits API Endpoint
php artisan test --filter=test_f19
```

### 10.2 Boundary & Cross-Feature Verification
```bash
# Tier 2 Boundary Tests (0.5-day balance, duplicate cancel rejection, 15m threshold, midnight skip)
php artisan test --filter=test_boundary_leave_cancellation_half_day_increment_atomic_restoration
php artisan test --filter=test_boundary_cannot_cancel_already_cancelled_leave_request
php artisan test --filter=test_boundary_visitor_overstay_exact_15_minute_window_threshold
php artisan test --filter=test_boundary_expire_no_show_visits_skips_today_and_future_visits

# Tier 3 Cross-Feature Interaction Tests
php artisan test --filter=test_cross_leave_cancellation_triggers_attendance_recalculation_from_punches
php artisan test --filter=test_cross_visitor_overstay_generates_device_alert_and_notifies_security
php artisan test --filter=test_cross_visitor_cancellation_dispatches_hardware_face_deletion

# Tier 4 Real-World Scenarios
php artisan test --filter=test_scenario_7
php artisan test --filter=test_scenario_9
```

### 10.3 Full Regression & Frontend Build
```bash
php artisan test
npm run build
```
