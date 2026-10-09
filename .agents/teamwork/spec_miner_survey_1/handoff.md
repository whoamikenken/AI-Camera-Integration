# Handoff Report: Specification Mining for R1, R2, and R3

**Agent:** `spec_miner_survey_1`  
**Recipient:** Orchestrator (`b7d1e4eb-1a56-481f-aa27-2fac5acd5ff8`)  
**Date:** 2026-10-07T02:20:00Z  
**Type:** Hard (Task complete)

---

## 1. Observation

1. **Global Personnel Broadcast Sync in `SyncPersonnelJob.php`:**
   Lines 47–50 of `/home/wsk-devops2/AI-Camera-Integration/app/Jobs/SyncPersonnelJob.php`:
   ```php
   $devices = $this->targetDeviceId
       ? Device::where('id', $this->targetDeviceId)->where('is_active', true)->get()
       : Device::where('is_active', true)->get();
   ```
   Any personnel creation or update triggers a broadcast to every active camera in the system. There are currently zero access group models or tables in the database.

2. **Absence of Access Control Group Entities:**
   Grep search for `access_group` across the entire codebase returned matches only in `system-evo.md`. No migrations, models, or controllers exist for access control groups.

3. **Absence of Leave Request Cancellation in `LeaveService.php`:**
   `/home/wsk-devops2/AI-Camera-Integration/app/Services/LeaveService.php` contains:
   - `calculateRequestedDays` (line 23)
   - `allocateBalance` (line 64)
   - `submitLeaveRequest` (line 87)
   - `approveLeaveRequest` (line 168)
   - `rejectLeaveRequest` (line 220)
   - `processYearEndCarryForward` (line 253)
   There is no method for cancelling a leave request.

4. **Schema Gaps in `leave_requests`, `regularization_requests`, and `visits`:**
   In `/home/wsk-devops2/AI-Camera-Integration/database/migrations/2026_09_30_000019_create_leave_and_regularization_tables.php`:
   - `leave_requests` lacks `cancellation_reason`, `cancelled_by`, and `cancelled_at`.
   - `regularization_requests` lacks `cancellation_reason`, `cancelled_by`, and `cancelled_at`.
   In `/home/wsk-devops2/AI-Camera-Integration/database/migrations/2026_09_30_000020_create_visitors_and_visits_tables.php`:
   - `visits` lacks `expected_departure`, `cancellation_reason`, `cancelled_by`, `cancelled_at`, and `overstay_alerted_at`.

5. **Single-Device Downlink Primitives in `CameraMqttService.php`:**
   In `/home/wsk-devops2/AI-Camera-Integration/app/Services/CameraMqttService.php`:
   - Single personnel creation: `addOrUpdatePerson` (line 320) using `EditPerson`.
   - Single and batch deletion: `deletePerson` (line 329) using `DelPerson` / `DeletePersons`.
   - `AddPersons` (hardware batch enrollment payload up to 50 persons per packet per `GEMINI.md` line 317) is not implemented.

6. **Absence of Bulk Campaign Infrastructure:**
   No `bulk_campaigns` table, model, or jobs exist in the codebase. In `/home/wsk-devops2/AI-Camera-Integration/resources/js/views/DeviceManager.vue` and `views/PersonnelManager.vue`, there are no checkbox selection states or batch action toolbars.

7. **Test Suite Baseline:**
   Executed `php artisan test --filter=PersonnelSyncTest` successfully with exit code 0 (4 tests passed, 7 assertions).

---

## 2. Logic Chain

1. **R1 (Access Control Groups):**
   - Observation 1 demonstrates that any person synchronization broadcasts to all devices indiscriminately because `targetDeviceId` is rarely provided.
   - Observation 2 confirms that access groups do not exist in the schema.
   - Therefore, introducing `access_groups`, `access_group_device`, `access_group_personnel`, and `access_group_department` with an `AccessControlService` is necessary to restrict target device queries in `SyncPersonnelJob` to authorized zones.
   - To preserve backward compatibility, when zero access groups are configured in a system, `AccessControlService` must fall back to all active devices.

2. **R2 (Resilient Lifecycle State Machines):**
   - Observation 3 and 4 show that leave and regularization requests cannot transition to `cancelled`, and database columns for recording cancellation metadata do not exist.
   - When cancelling an approved leave request, the `used` balance must be decremented, and the `AttendanceRecord`s marked as `'on_leave'` must be recalculated (or deleted if in the future) via `AttendanceProcessingService::recalculateDailyAttendance()`.
   - For visitors, visits can remain in `expected` or `checked_in` indefinitely without automated expiration or overstay detection. Introducing `DetectOverstayVisitorsJob` (every 15m) and `ExpireNoShowVisitsJob` (daily at midnight) transitions visits to `overstayed` and `no_show` with `DeviceAlert` integration.

3. **R3 (Bulk Fleet & Workforce Operations):**
   - Observation 5 and 6 reveal that device maintenance and personnel enrollment are strictly 1-by-1 HTTP operations.
   - Implementing `bulk_campaigns` enables asynchronous, rate-limited execution of fleet reboots and MQTT updates via `BulkDeviceCampaignJob`.
   - Implementing `addPersons` in `CameraMqttService` using `operator: "AddPersons"` allows chunking up to 50 persons per downlink MQTT packet, yielding a dramatic throughput improvement over sequential `EditPerson` calls.

---

## 3. Caveats

1. **Hardware Broker Packet Size Limits:** The `AddPersons` command supports up to 50 persons, but if Base64 images are bundled for all 50 persons, the payload could reach 2–3 MB. We recommend using `picURI` URLs or smaller chunks of 15–25 when Base64 images are embedded.
2. **Attendance Recalculation for Future Dates:** When an approved leave is cancelled, future attendance records (where date > today) should be purged rather than recalculated into "absent" records, because the employee has not had an opportunity to clock in yet.

---

## 4. Conclusion

The specification for R1, R2, and R3 is fully mapped, and concrete schemas, service contracts, jobs, API endpoints, and frontend components have been specified in `analysis.md`.
- **R1** requires 1 migration (`access_groups` and 3 pivots), `AccessGroup` model, `AccessControlService`, refactoring `SyncPersonnelJob`, `AccessGroupController`, and `AccessGroupManager.vue`.
- **R2** requires 1 migration (cancellation & overstay columns on 3 tables), `cancelLeaveRequest` in `LeaveService`, `cancelVisit` in `VisitorSyncService`, 2 scheduled jobs (`DetectOverstayVisitorsJob`, `ExpireNoShowVisitsJob`), and UI buttons.
- **R3** requires 1 migration (`bulk_campaigns`), `BulkCampaign` model, `addPersons` in `CameraMqttService`, 3 batch jobs (`BulkDeviceCampaignJob`, `BulkPersonnelSyncJob`, `BulkPersonnelDeleteJob`), bulk endpoints in `DeviceController` & `PersonnelController`, and batch toolbars in `DeviceManager.vue` and `PersonnelManager.vue`.

---

## 5. Verification Method

To independently verify the findings in this report:
1. Inspect `analysis.md` at `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/spec_miner_survey_1/analysis.md` for full interface definitions, schema specifications, and edge cases.
2. Verify existing personnel sync query in `app/Jobs/SyncPersonnelJob.php` lines 47–50.
3. Verify lack of cancellation methods in `app/Services/LeaveService.php` and `app/Http/Controllers/RegularizationController.php`.
4. Verify existing test suites run cleanly:
   ```bash
   php artisan test --filter=PersonnelSyncTest
   php artisan test --filter=LeaveAndRegularizationTest
   php artisan test --filter=VisitorManagementTest
   ```
5. Verify frontend build status:
   ```bash
   npm run build
   ```
