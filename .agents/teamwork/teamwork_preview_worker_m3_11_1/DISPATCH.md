# DISPATCH DIRECTIVE — Implementation Worker for Milestone M3

## Identity & Role
- **Agent**: `teamwork_preview_worker_m3_11_1`
- **Archetype**: `teamwork_preview_worker`
- **Role**: Backend & Frontend Implementation Worker for Milestone M3 (Resilient Domain Lifecycle State Machines)
- **Working Directory**: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_worker_m3_11_1`
- **Parent Conversation ID**: `340b2ee2-86ac-4ca7-9f71-8c1542c65adb`

## MANDATORY INTEGRITY WARNING
DO NOT CHEAT. All implementations must be genuine. DO NOT hardcode test results, create dummy/facade implementations, or circumvent the intended task. A teamwork_preview_auditor will independently verify your work. Integrity violations WILL be detected and your work WILL be rejected.

## Mandatory Reference Documents
1. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md` (authoritative user request)
2. `/home/wsk-devops2/AI-Camera-Integration/system-evo.md` (Feature 2: Resilient Domain Lifecycle State Machines)
3. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md`
4. Spec Miner Handoff: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_spec_miner_m3_11_1/handoff.md`
5. Leave Explorer Handoff: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_m3_11_1/handoff.md`
6. Visitor Explorer Handoff: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_m3_11_2/handoff.md`

## Implementation Tasks (Milestone M3)

### Task 1: Database Migrations
Create clean migrations for the new columns and indexes:
1. `leave_requests`: add `cancellation_reason` (text, nullable), `cancelled_by` (unsignedBigInteger, nullable, foreign key users.id), `cancelled_at` (timestamp, nullable).
2. `regularization_requests`: add `cancellation_reason` (text, nullable), `cancelled_by` (unsignedBigInteger, nullable, foreign key users.id), `cancelled_at` (timestamp, nullable).
3. `visits`: add `device_id` (string, nullable), `expected_departure` (timestamp, nullable), `overstay_alerted_at` (timestamp, nullable), `cancellation_reason` (text, nullable), `cancelled_by` (unsignedBigInteger, nullable, foreign key users.id), `cancelled_at` (timestamp, nullable). Add composite index on `['status', 'expected_departure']`.

### Task 2: Eloquent Models
1. `app/Models/LeaveRequest.php`:
   - Add `$fillable`: `'cancellation_reason'`, `'cancelled_by'`, `'cancelled_at'`.
   - Add `$casts`: `'cancelled_at' => 'datetime'`.
   - Add `canceller(): BelongsTo` relationship.
2. `app/Models/LeaveBalance.php`:
   - Add dual-attribute compatibility accessors & mutators:
     * `used_days` <-> `used`
     * `allocated_days` <-> `allocated`
     * `pending_days` <-> `pending`
     * `remaining_days` <-> `available`
   - Add `used_days`, `allocated_days`, `pending_days` to `$fillable` and `remaining_days` to `$appends`.
3. `app/Models/RegularizationRequest.php`:
   - Add `$fillable`: `'cancellation_reason'`, `'cancelled_by'`, `'cancelled_at'`, `'requested_clock_in'`, `'requested_clock_out'`.
   - Add `$casts`: `'cancelled_at' => 'datetime'`.
   - Dual-naming compatibility for `requested_clock_in` <-> `corrected_clock_in`, `requested_clock_out` <-> `corrected_clock_out`.
4. `app/Models/Visit.php`:
   - Add `$fillable`: `'device_id'`, `'expected_departure'`, `'overstay_alerted_at'`, `'cancellation_reason'`, `'cancelled_by'`, `'cancelled_at'`.
   - Add `$casts`: `'expected_departure' => 'datetime'`, `'overstay_alerted_at' => 'datetime'`, `'cancelled_at' => 'datetime'`.

### Task 3: Domain Services
1. `app/Services/LeaveService.php`:
   - Implement `cancelLeaveRequest(LeaveRequest $request, ?User $user = null, ?string $reason = null): LeaveRequest`:
     * Validate status transition: allowed from `pending` or `approved`. If `rejected` or already `cancelled`, throw `ValidationException` (422).
     * Wrap in `DB::transaction()`:
       - Pessimistically lock `LeaveBalance`: `LeaveBalance::where(...)->lockForUpdate()->first()`.
       - If `pending`: decrement `pending` / `pending_days` by `$request->total_days`.
       - If `approved`:
         - Decrement `used` / `used_days` by `$request->total_days`.
         - Roll back daily `AttendanceRecord` marked `on_leave` for the date range. Call `AttendanceProcessingService::processDay($employee, $date)`. If future placeholder with no punches, delete or recalculate.
       - Update `$request->update(['status' => 'cancelled', 'cancellation_reason' => $reason, 'cancelled_by' => $user?->id, 'cancelled_at' => now()])`.
2. `app/Services/AttendanceProcessingService.php`:
   - Add `processDay(Employee $employee, Carbon|string $date): AttendanceRecord`:
     * Call `$this->recalculateDailyAttendance($employee, $date)`.
3. `app/Services/RegularizationService.php`:
   - Implement `cancelRegularization(RegularizationRequest $request, ?User $user = null, ?string $reason = null): RegularizationRequest`:
     * Validate status is `pending`. If `approved`, `rejected`, or `cancelled`, throw `ValidationException`.
     * Update status to `cancelled`, record cancellation metadata.
4. `app/Services/VisitorSyncService.php`:
   - Implement `cancelVisit(Visit $visit, ?User $user = null, ?string $reason = null): Visit`:
     * Validate status: allowed from `expected` or `checked_in`. If `checked_out` or `cancelled`, throw `ValidationException`.
     * Immediate camera face de-provisioning: `$this->revokeVisitorFace($visit->visitor, $visit)`.
     * Update status to `cancelled`, record cancellation metadata.

### Task 4: Background Jobs & Scheduler
1. `app/Jobs/DetectOverstayVisitorsJob.php`:
   - Query `checked_in` visits where `expected_departure <= now()->subMinutes(15)` and `overstay_alerted_at IS NULL`.
   - Transition status to `'overstayed'`, set `overstay_alerted_at = now()`.
   - Create `DeviceAlert` (`alert_type: 'visitor_overstay'`).
2. `app/Jobs/ExpireNoShowVisitsJob.php`:
   - Query `expected` visits where `expected_arrival < today()->startOfDay()` (or past `visit_date`).
   - Transition status to `'no_show'`.
   - De-provision any pre-provisioned edge camera face whitelist.
3. `routes/console.php`:
   - Register `Schedule::job(new DetectOverstayVisitorsJob)->everyFifteenMinutes();`
   - Register `Schedule::job(new ExpireNoShowVisitsJob)->dailyAt('00:00');`

### Task 5: HTTP Endpoints & Routing
1. `app/Http/Controllers/LeaveController.php`:
   - Implement `cancelRequest(Request $request, $id)`.
2. `app/Http/Controllers/AttendanceRegularizationController.php` (or `RegularizationController.php`):
   - Implement `cancel(Request $request, $id)`.
3. `app/Http/Controllers/VisitorController.php`:
   - Implement `cancelVisit(Request $request, $id)`.
   - Implement `overstayed(Request $request)`.
4. `routes/api.php`:
   - CRITICAL: Register `GET /api/visits/overstayed` BEFORE `GET /api/visits/{id}`.
   - Register `POST|PUT /api/visits/{id}/cancel`.
   - Register `POST|PUT /api/leave-requests/{id}/cancel`.
   - Register `POST|PUT /api/regularization-requests/{id}/cancel`.

### Task 6: Frontend Updates
1. `resources/js/views/LeaveApprovalQueue.vue`:
   - Add `cancelled` filter, cancel buttons, cancellation modal.
2. `resources/js/views/SelfServicePortal.vue`:
   - Add cancel request button for pending leave/regularization requests.
3. `resources/js/views/VisitorDashboard.vue`:
   - Add Overstay KPI card, badge, and Cancel action with reason modal.
4. `resources/js/stores/leaveStore.js` & `resources/js/stores/visitorStore.js`:
   - Add `cancelLeaveRequest` and `cancelVisit` actions.

### Task 7: Verification Commands
Run the following verification commands and record verbatim output in `handoff.md`:
1. `php artisan test --filter="test_f1[3-9]"`
2. `php artisan test --filter="test_boundary_.*leave|test_boundary_.*visit|test_boundary_.*regularization"`
3. `php artisan test --filter=LeaveServiceTest`
4. `php artisan test --filter=Visitor`
5. `php artisan test --filter=Regularization`
6. `npm run build`
7. Complete test suite run or relevant subset.

Deliver `handoff.md` in your working directory and notify parent via `send_message`.


## 2026-10-08T06:05:08Z
[Message] timestamp=2026-10-08T06:05:08Z sender=340b2ee2-86ac-4ca7-9f71-8c1542c65adb priority=MESSAGE_PRIORITY_HIGH
Content:
Implement all 7 tasks detailed in DISPATCH.md:
- Database migrations for leave_requests, regularization_requests, and visits.
- Eloquent model updates (LeaveRequest, LeaveBalance with dual-attribute mappings, RegularizationRequest, Visit).
- Domain services (LeaveService::cancelLeaveRequest with atomic balance restore & attendance rollback, AttendanceProcessingService::processDay, RegularizationService::cancelRegularization, VisitorSyncService::cancelVisit with immediate camera face de-provisioning).
- Background jobs (DetectOverstayVisitorsJob every 15m, ExpireNoShowVisitsJob midnight) and scheduler in routes/console.php.
- Controllers & routes (LeaveController, RegularizationController, VisitorController, routes/api.php ensuring visits/overstayed is declared before visits/{id}).
- Frontend views & stores (LeaveApprovalQueue.vue, SelfServicePortal.vue, VisitorDashboard.vue, leaveStore.js, visitorStore.js).
- Run verification tests and build: php artisan test --filter="test_f1[3-9]", php artisan test --filter="LeaveServiceTest", php artisan test --filter="Visitor", npm run build.
