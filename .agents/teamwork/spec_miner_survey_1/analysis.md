# Comprehensive Specification Mining Analysis: R1, R2, and R3

**Author:** Spec Miner Survey 1 (`spec_miner_survey_1`)  
**Timestamp:** 2026-10-07T02:15:00Z  
**Target Milestone:** Enterprise Access Control Groups, Lifecycle State Machines, & Fleet Campaigns  
**Authoritative References:** `ORIGINAL_REQUEST.md` (## 2026-10-07T01:57:58Z), `system-evo.md` (Features 1, 2, 5), `GEMINI.md`

---

## Executive Summary

This report delivers the authoritative specification mining and gap analysis for three core enterprise features:
1. **R1: Granular Access Control Groups & Zone-Based Dispatching** (Feature 1 in `system-evo.md`): Eliminating global broadcast sync by binding personnel and departments to authorized device zones via access groups.
2. **R2: Resilient Domain Lifecycle State Machines** (Feature 2 in `system-evo.md`): Implementing full cancellation workflows for leaves (with balance and attendance status rollbacks) and regularizations, alongside visitor overstay detection and no-show expiration.
3. **R3: Bulk Workforce Operations & Fleet Provisioning Campaigns** (Feature 5 in `system-evo.md`): Providing tracked asynchronous batch campaigns for fleet device maintenance (reboot, MQTT updates) and high-throughput workforce face synchronization (`AddPersons` up to 50 persons per payload).

---

## Features Discovered

| # | Category | Feature | Description | Inputs | Outputs | Error Behavior | Discovered Via |
|---|---|---|---|---|---|---|---|
| 1 | R1: Access Control | Access Group Entity (`access_groups`) | Organizational access control group defining security zones and physical boundaries | `name`, `code`, `organization_id`, `description`, `is_active` | `AccessGroup` model record | Unique constraint on `code`; 422 if invalid | `system-evo.md` § Feature 1 |
| 2 | R1: Access Control | Group-to-Device Mapping (`access_group_device`) | Pivot linking access groups to target edge camera devices | `access_group_id`, `device_id` (foreign keys) | Pivot record | Cascades delete on group/device removal | `system-evo.md` § Feature 1 |
| 3 | R1: Access Control | Group-to-Personnel Mapping (`access_group_personnel`) | Pivot linking specific personnel to access groups with optional schedule rules | `access_group_id`, `personnel_id`, `schedule_rule_id` | Pivot record | Cascades delete; unique on `(access_group_id, personnel_id)` | `system-evo.md` § Feature 1 |
| 4 | R1: Access Control | Group-to-Department Mapping (`access_group_department`) | Pivot granting automatic zone access to all members of a department | `access_group_id`, `department_id` | Pivot record | Cascades delete; unique on `(access_group_id, department_id)` | `system-evo.md` § Feature 1 |
| 5 | R1: Access Control | Target Device Resolution Service (`AccessControlService`) | Resolves authorized devices for any personnel by evaluating direct and departmental access group memberships | `Personnel $personnel` | `Collection<Device>` | Empty collection if no active groups; fallback to all active if 0 groups exist in system | `system-evo.md` § Feature 1; `SyncPersonnelJob.php` |
| 6 | R1: Access Control | Zone-Scoped Personnel Sync (`SyncPersonnelJob` refactor) | Scopes downlink sync commands (`ADD`, `EDIT`, `DELETE`) strictly to authorized devices in person's access group(s) | `personnelId`, `action`, `targetDeviceId`, `customizeIdToDelete` | Queued `SyncDevicePersonnelJob` instances | Skips inactive devices; logs if no authorized devices | `app/Jobs/SyncPersonnelJob.php` |
| 7 | R1: Access Control | Access Group Zone Re-sync (`sync-now`) | Pushes full face template roster of all personnel assigned to group to all devices mapped to that group | `AccessGroup $accessGroup` | `200 OK` with counts of dispatched sync tasks | 404 if not found; 422 if no devices in group | `system-evo.md` § Feature 1 |
| 8 | R1: Access Control | Access Group Management UI (`AccessGroupManager.vue`) | Interface for creating, editing access groups, configuring device/department matrix, and triggering zone sync | User selections | Reactive Vue component | Displays API validation errors in toast/modal | `system-evo.md` § Feature 1 |
| 9 | R2: Lifecycle | Leave Request Cancellation (`cancelLeaveRequest`) | Cancels pending or approved leave requests, restoring leave balances and rolling back attendance statuses | `LeaveRequest $request`, `User $user`, `reason` | Updated `LeaveRequest` (`status = 'cancelled'`) | 422 if already rejected or cancelled; 403 if unauthorized | `system-evo.md` § Feature 2; `LeaveService.php` |
| 10 | R2: Lifecycle | Leave Balance Restoration | Reverses deducted balance: decrements `pending` if cancelled from pending, decrements `used` if cancelled from approved | `LeaveRequest $request` | Updated `LeaveBalance` | Clamped to `max(0.0, balance)` inside DB transaction | `app/Services/LeaveService.php` |
| 11 | R2: Lifecycle | Attendance Status Rollback | Reverts `AttendanceRecord` statuses marked `'on_leave'` and triggers recalculation from punches or marks absent/holiday | `employee_id`, `date` | Updated/removed `AttendanceRecord` | Recalculates punches for past/today; removes future placeholders | `system-evo.md` § Feature 2; `AttendanceProcessingService.php` |
| 12 | R2: Lifecycle | Regularization Cancellation (`cancel`) | Withdraws an unreviewed regularization request before manager approval | `RegularizationRequest $id`, `User $user`, `reason` | Updated record (`status = 'cancelled'`) | 422 if already approved/rejected/cancelled; 403 if unauthorized | `system-evo.md` § Feature 2; `RegularizationController.php` |
| 13 | R2: Lifecycle | Visitor Cancellation Workflow | Cancels pre-registered or checked-in visits, revoking edge camera face templates if currently checked in | `Visit $id`, `reason` | Updated `Visit` (`status = 'cancelled'`) | 422 if already checked out or cancelled | `system-evo.md` § Feature 2; `VisitorSyncService.php` |
| 14 | R2: Lifecycle | Overstayed Visitor Detection (`DetectOverstayVisitorsJob`) | Background scheduled job running every 15m flagging visitors exceeding expected departure without checkout | System clock (`now()`) | `status = 'overstayed'`, `DeviceAlert`, notifications | Alert deduplicated via `overstay_alerted_at` timestamp | `system-evo.md` § Feature 2 |
| 15 | R2: Lifecycle | No-Show Visit Expiration (`ExpireNoShowVisitsJob`) | Midnight scheduled job expiring past unfulfilled `expected` visits to `no_show` status | System date (`today()`) | `status = 'no_show'`, `cancellation_reason` logged | Skips visits scheduled for today or future | `system-evo.md` § Feature 2 |
| 16 | R2: Lifecycle | Overstayed Visits Endpoint (`GET /api/visits/overstayed`) | Query API returning currently overstayed active visitors for security desk | Query params: `location_id`, `per_page` | Paginated overstayed visit list | 401/403 if unauthorized | `system-evo.md` § Feature 2 |
| 17 | R3: Bulk Operations | Bulk Campaigns Entity (`bulk_campaigns`) | Persistent tracking table for asynchronous multi-device and multi-person batch jobs | `user_id`, `campaign_type`, `total_items`, `payload` | `BulkCampaign` record with progress counters | 422 on invalid payload | `system-evo.md` § Feature 5 |
| 18 | R3: Bulk Operations | Fleet Bulk Reboot (`POST /api/devices/bulk-reboot`) | Asynchronously dispatches remote reboot commands across selected devices with rate limiting | `device_ids: array` | `202 Accepted` with `campaign_id` | 422 if empty device array | `system-evo.md` § Feature 5; `CameraMqttService.php` |
| 19 | R3: Bulk Operations | Fleet Bulk MQTT Sync (`POST /api/devices/bulk-sync-mqtt`) | Asynchronously updates MQTT broker parameters (`UpMQTTconfig`) across selected devices | `device_ids: array`, `mqtt_config: object` | `202 Accepted` with `campaign_id` | 422 if configuration invalid | `system-evo.md` § Feature 5; `CameraMqttService.php` |
| 20 | R3: Bulk Operations | High-Throughput Bulk Personnel Sync (`AddPersons`) | Packages personnel records into batched MQTT payloads of up to 50 persons per packet | `personnel_ids: array`, optional `device_ids` | `202 Accepted` with `campaign_id` | Chunks in batches of 50; handles offline cameras | `GEMINI.md` line 317; `system-evo.md` § Feature 5 |
| 21 | R3: Bulk Operations | Bulk Personnel Deletion (`POST /api/personnel/bulk-delete`) | Deletes multiple personnel records and dispatches batched `DeletePersons` MQTT commands to edge cameras | `personnel_ids: array` | `202 Accepted` with `campaign_id` | 422 if empty array | `system-evo.md` § Feature 5; `CameraMqttService.php` |
| 22 | R3: Bulk Operations | Bulk Campaign Progress API (`GET /api/bulk-campaigns/{id}`) | Real-time progress monitoring endpoint for tracking campaign execution state | `id` | `{ id, status, total_items, processed_items, failed_items }` | 404 if not found | `system-evo.md` § Feature 5 |
| 23 | R3: Bulk Operations | Fleet & Personnel Batch Toolbars | Checkbox selection and sticky floating action toolbars in `DeviceManager.vue` and `PersonnelManager.vue` | UI selections | Trigger batch API requests with progress modal | Prevents duplicate submissions | `system-evo.md` § Feature 5 |

---

## Edge Cases

| # | Feature | Input | Observed Behavior |
|---|---|---|---|
| 1 | Access Control | Person belonging to multiple overlapping access groups | `AccessControlService` merges device IDs and removes duplicates, ensuring each camera receives only 1 sync command. |
| 2 | Access Control | Device removed from an access group | System detects diff: members who only had access through this group receive `DELETE` on the removed device; members with access through another active group retain access. |
| 3 | Access Control | System with zero access groups configured (legacy mode) | Fallback logic: if `AccessGroup::count() === 0`, `AccessControlService` returns all active devices, preserving backward compatibility. |
| 4 | Access Control | Person with no access groups and non-empty access group database | `AccessControlService` returns empty collection; `SyncPersonnelJob` safely logs that no authorized devices exist without error. |
| 5 | Access Control | Device marked `is_active = false` inside an active group | Query filters by `where('is_active', true)`; inactive hardware is excluded from sync dispatching. |
| 6 | Leave Cancellation | Cancelling request while in `pending` status | Decrements `pending` balance by `total_days`; leaves `used` untouched; does not modify attendance records. |
| 7 | Leave Cancellation | Cancelling request while in `approved` status | Decrements `used` balance by `total_days`; iterates date range and calls `recalculateDailyAttendance()` on past/current dates. |
| 8 | Leave Cancellation | Cancelling approved request for future dates | Future placeholder `AttendanceRecord`s with status `'on_leave'` are removed or reset, preventing the employee from being marked absent prematurely. |
| 9 | Leave Cancellation | Unauthorized cancellation attempt by other employee | Non-manager user attempting to cancel another employee's request receives `403 Forbidden`. |
| 10 | Regularization Cancellation | Attempt to cancel already approved regularization | Throws `422 Unprocessable Entity` ("Cannot cancel an approved regularization request"); punches already persist. |
| 11 | Visit Cancellation | Cancelling visit currently in `checked_in` status | Status updates to `cancelled` and `VisitorSyncService::revokeVisitorFace()` is immediately invoked, deleting the face template from edge cameras. |
| 12 | Visitor Overstay | Visitor without explicit `expected_departure` time | `DetectOverstayVisitorsJob` falls back to default duration (e.g. 8 hours from `check_in_time` or `endOfDay()`). |
| 13 | Visitor Overstay | Repeated execution of `DetectOverstayVisitorsJob` | `overstay_alerted_at` timestamp check ensures a `DeviceAlert` is created exactly once per overstay episode. |
| 14 | Bulk Personnel Sync | Batch of 135 personnel records selected | `BulkPersonnelSyncJob` partitions the 135 records into 3 chunks: [50, 50, 35], dispatching 3 `AddPersons` MQTT packets per camera. |
| 15 | Bulk Device Maintenance | 3 out of 20 cameras offline during bulk reboot | Offline cameras are logged as failed (`failed_items` = 3); remaining 17 succeed; campaign status finishes as `completed` with error details. |
| 16 | Bulk Campaign Progress | UI polls campaign status while jobs are running | Returns incremental counters (`processed_items`, `failed_items`, `percentage`) with `status = 'processing'`. |

---

## Detailed Gap Analysis & Codebase Investigation

### Gap 1: Access Control Groups & Zone-Based Dispatching (R1)
- **Current State:**
  - `app/Jobs/SyncPersonnelJob.php` queries:
    ```php
    $devices = $this->targetDeviceId
        ? Device::where('id', $this->targetDeviceId)->where('is_active', true)->get()
        : Device::where('is_active', true)->get();
    ```
  - Any creation, update, or deletion in `PersonnelObserver` broadcasts to **every camera** in the database.
  - No database tables exist for access groups (`access_groups`, `access_group_device`, `access_group_personnel`, `access_group_department`).
  - No `AccessControlService` exists.
  - No access group endpoints exist in `routes/api.php`.
  - No `AccessGroupManager.vue` component exists in `resources/js/`.
- **Required Implementation:**
  - New migration creating `access_groups`, `access_group_device`, `access_group_personnel`, and `access_group_department`.
  - `App\Models\AccessGroup` with relationships: `devices()`, `personnel()`, `departments()`, `organization()`.
  - Relationships added to `Device`, `Personnel`, and `Department`.
  - `App\Services\AccessControlService`:
    ```php
    public function getAuthorizedDevicesForPersonnel(Personnel $personnel): Collection;
    public function getAuthorizedPersonnelForDevice(Device $device): Collection;
    public function syncZone(AccessGroup $accessGroup): array;
    ```
  - Refactor `SyncPersonnelJob` to resolve devices via `AccessControlService::getAuthorizedDevicesForPersonnel($personnel)`.
  - Controller `App\Http\Controllers\AccessGroupController` with full CRUD, membership assignment, and `syncNow`.
  - API routes under `/api/access-groups`.
  - Vue component `resources/js/components/settings/AccessGroupManager.vue` embedded into `SettingsHub.vue`.

### Gap 2: Resilient Domain Lifecycle State Machines (R2)
- **Current State:**
  - `LeaveService.php` only provides `submitLeaveRequest`, `approveLeaveRequest`, `rejectLeaveRequest`. No `cancelLeaveRequest` exists.
  - `leave_requests` table lacks `cancellation_reason`, `cancelled_by`, `cancelled_at`.
  - `RegularizationController.php` only provides `index`, `store`, `approve`, `reject`. No cancellation endpoint exists.
  - `regularization_requests` table lacks `cancellation_reason`, `cancelled_by`, `cancelled_at`.
  - `visits` table lacks `expected_departure`, `cancellation_reason`, `cancelled_by`, `cancelled_at`, and `overstay_alerted_at`. Status enum in migration comment only lists `expected, checked_in, checked_out, cancelled, rejected`.
  - No scheduled background jobs exist for detecting overstayed visitors or expiring no-shows.
  - `VisitorController.php` lacks `cancelVisit` and `overstayed` endpoints.
  - `LeaveApprovalQueue.vue` has no "Cancel" button for approved leaves.
  - `VisitorDashboard.vue` has no overstayed indicators, filter options, or cancel actions.
- **Required Implementation:**
  - New migration adding cancellation columns to `leave_requests` and `regularization_requests`, and adding `expected_departure`, cancellation columns, and `overstay_alerted_at` to `visits`.
  - In `LeaveService`: add `cancelLeaveRequest(LeaveRequest $request, ?User $user, ?string $reason)`. Atomically restore balances and recalculate/rollback affected `AttendanceRecord`s.
  - In `VisitorSyncService`: add `cancelVisit(Visit $visit, ?User $user, ?string $reason)`. If currently checked in, invoke `revokeVisitorFace()`.
  - Create `App\Jobs\DetectOverstayVisitorsJob` (runs every 15 minutes) to flag visits exceeding `expected_departure` and create `DeviceAlert` records.
  - Create `App\Jobs\ExpireNoShowVisitsJob` (runs at midnight) to transition past `expected` visits to `no_show`.
  - Register schedules in `routes/console.php`.
  - Add API endpoints:
    - `POST /api/leave-requests/{id}/cancel`
    - `POST /api/regularization-requests/{id}/cancel`
    - `POST /api/visits/{id}/cancel`
    - `GET /api/visits/overstayed`
  - Update `LeaveApprovalQueue.vue`, `VisitorDashboard.vue` with cancellation buttons and overstay alerts.

### Gap 3: Bulk Workforce Operations & Fleet Provisioning Campaigns (R3)
- **Current State:**
  - Hardware operations are 1-by-1 only (`POST /api/devices/{device}/reboot`, `POST /api/devices/{device}/sync-mqtt`).
  - Personnel operations are 1-by-1 only (`POST /api/personnel/{personnel}/sync-now`).
  - `CameraMqttService.php` contains `addOrUpdatePerson` (using `EditPerson` for single person) and `deletePerson` (supporting `DelPerson` or `DeletePersons`), but lacks `addPersons` (using `AddPersons` batch payload).
  - No `bulk_campaigns` table or model exists.
  - No background jobs for bulk device reboot or bulk personnel sync exist.
  - `DeviceManager.vue` and `PersonnelManager.vue` have no checkbox selections or batch action toolbars.
- **Required Implementation:**
  - New migration creating `bulk_campaigns` table (`id`, `user_id`, `campaign_type`, `total_items`, `processed_items`, `failed_items`, `status`, `payload`, `result_summary`, `started_at`, `completed_at`, `timestamps`).
  - Model `App\Models\BulkCampaign`.
  - Add `addPersons(Device $device, array|Collection $personnelList)` to `CameraMqttService` implementing the `AddPersons` protocol (`PersonNum` + `Personinfo_0` ... `Personinfo_N` up to 50 items).
  - Background jobs: `BulkDeviceCampaignJob`, `BulkPersonnelSyncJob`, `BulkPersonnelDeleteJob`.
  - Controller `BulkCampaignController` for tracking progress and listing campaigns.
  - Add bulk action methods in `DeviceController` (`bulkReboot`, `bulkSyncMqtt`) and `PersonnelController` (`bulkSync`, `bulkDelete`).
  - API routes:
    - `POST /api/devices/bulk-reboot`
    - `POST /api/devices/bulk-sync-mqtt`
    - `POST /api/personnel/bulk-sync`
    - `POST /api/personnel/bulk-delete`
    - `GET /api/bulk-campaigns`
    - `GET /api/bulk-campaigns/{id}`
  - Vue frontend toolbars with multi-select checkboxes in `DeviceManager.vue` and `PersonnelManager.vue`.

---

## Complete Database Schema Specifications

```sql
-- 1. ACCESS CONTROL GROUPS
CREATE TABLE access_groups (
    id BIGSERIAL PRIMARY KEY,
    organization_id BIGINT REFERENCES organizations(id) ON DELETE CASCADE,
    name VARCHAR(128) NOT NULL,
    code VARCHAR(64) UNIQUE NOT NULL,
    description TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE access_group_device (
    id BIGSERIAL PRIMARY KEY,
    access_group_id BIGINT NOT NULL REFERENCES access_groups(id) ON DELETE CASCADE,
    device_id BIGINT NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(access_group_id, device_id)
);

CREATE TABLE access_group_personnel (
    id BIGSERIAL PRIMARY KEY,
    access_group_id BIGINT NOT NULL REFERENCES access_groups(id) ON DELETE CASCADE,
    personnel_id BIGINT NOT NULL REFERENCES personnel(id) ON DELETE CASCADE,
    schedule_rule_id BIGINT,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(access_group_id, personnel_id)
);

CREATE TABLE access_group_department (
    id BIGSERIAL PRIMARY KEY,
    access_group_id BIGINT NOT NULL REFERENCES access_groups(id) ON DELETE CASCADE,
    department_id BIGINT NOT NULL REFERENCES departments(id) ON DELETE CASCADE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(access_group_id, department_id)
);

-- 2. LIFECYCLE SCHEMA EXPANSIONS
ALTER TABLE leave_requests
    ADD COLUMN cancellation_reason TEXT,
    ADD COLUMN cancelled_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    ADD COLUMN cancelled_at TIMESTAMP WITH TIME ZONE;

ALTER TABLE regularization_requests
    ADD COLUMN cancellation_reason TEXT,
    ADD COLUMN cancelled_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    ADD COLUMN cancelled_at TIMESTAMP WITH TIME ZONE;

ALTER TABLE visits
    ADD COLUMN expected_departure TIMESTAMP WITH TIME ZONE,
    ADD COLUMN cancellation_reason TEXT,
    ADD COLUMN cancelled_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    ADD COLUMN cancelled_at TIMESTAMP WITH TIME ZONE,
    ADD COLUMN overstay_alerted_at TIMESTAMP WITH TIME ZONE;

-- 3. BULK CAMPAIGNS
CREATE TABLE bulk_campaigns (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT REFERENCES users(id) ON DELETE SET NULL,
    campaign_type VARCHAR(64) NOT NULL,
    total_items INT DEFAULT 0,
    processed_items INT DEFAULT 0,
    failed_items INT DEFAULT 0,
    status VARCHAR(32) DEFAULT 'pending',
    payload JSONB,
    result_summary JSONB,
    started_at TIMESTAMP WITH TIME ZONE,
    completed_at TIMESTAMP WITH TIME ZONE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_bulk_campaigns_status ON bulk_campaigns(status);
CREATE INDEX idx_bulk_campaigns_user ON bulk_campaigns(user_id);
```

---

## Concrete Files to Create & Modify

| Action | File Path | Scope / Description |
|---|---|---|
| **Create** | `database/migrations/2026_10_07_000001_create_access_groups_tables.php` | Creates `access_groups`, pivots for devices, personnel, departments |
| **Create** | `database/migrations/2026_10_07_000002_expand_domain_lifecycle_states.php` | Adds cancellation fields to `leave_requests`, `regularization_requests`, and `visits`, plus `expected_departure` and `overstay_alerted_at` |
| **Create** | `database/migrations/2026_10_07_000003_create_bulk_campaigns_table.php` | Creates `bulk_campaigns` table for tracking batch operations |
| **Create** | `app/Models/AccessGroup.php` | Eloquent model for access control groups |
| **Modify** | `app/Models/Device.php` | Add `accessGroups(): BelongsToMany` relation |
| **Modify** | `app/Models/Personnel.php` | Add `accessGroups(): BelongsToMany` relation |
| **Modify** | `app/Models/Department.php` | Add `accessGroups(): BelongsToMany` relation |
| **Modify** | `app/Models/LeaveRequest.php` | Add `cancelled_by`, `cancelled_at`, `cancellation_reason` fillable and casts |
| **Modify** | `app/Models/RegularizationRequest.php` | Add `cancelled_by`, `cancelled_at`, `cancellation_reason` fillable and casts |
| **Modify** | `app/Models/Visit.php` | Add new fields and helper scope `scopeOverstayed()` |
| **Create** | `app/Models/BulkCampaign.php` | Eloquent model for bulk campaigns with progress tracking methods |
| **Create** | `app/Services/AccessControlService.php` | Core zone resolution logic, diff calculation, and zone sync execution |
| **Modify** | `app/Jobs/SyncPersonnelJob.php` | Refactor target resolution to use `AccessControlService` |
| **Modify** | `app/Services/LeaveService.php` | Implement `cancelLeaveRequest()` with balance restoration and attendance rollback |
| **Modify** | `app/Services/VisitorSyncService.php` | Implement `cancelVisit()` with face revocation |
| **Modify** | `app/Services/CameraMqttService.php` | Implement `addPersons()` supporting up to 50 persons in `AddPersons` payload |
| **Create** | `app/Jobs/DetectOverstayVisitorsJob.php` | Scheduled job flagging overstayed visitors and creating `DeviceAlert` |
| **Create** | `app/Jobs/ExpireNoShowVisitsJob.php` | Scheduled job expiring yesterday's unfulfilled visits |
| **Create** | `app/Jobs/BulkDeviceCampaignJob.php` | Job processing bulk reboot and bulk MQTT config updates |
| **Create** | `app/Jobs/BulkPersonnelSyncJob.php` | Job chunking personnel into batches of 50 and pushing `AddPersons` |
| **Create** | `app/Jobs/BulkPersonnelDeleteJob.php` | Job processing batch deletion across personnel and edge cameras |
| **Create** | `app/Http/Controllers/AccessGroupController.php` | REST API controller for access groups |
| **Create** | `app/Http/Controllers/BulkCampaignController.php` | REST API controller for tracking campaigns |
| **Modify** | `app/Http/Controllers/LeaveController.php` | Add `cancelRequest()` endpoint |
| **Modify** | `app/Http/Controllers/RegularizationController.php` | Add `cancel()` endpoint |
| **Modify** | `app/Http/Controllers/VisitorController.php` | Add `cancelVisit()` and `overstayed()` endpoints |
| **Modify** | `app/Http/Controllers/DeviceController.php` | Add `bulkReboot()` and `bulkSyncMqtt()` endpoints |
| **Modify** | `app/Http/Controllers/PersonnelController.php` | Add `bulkSync()` and `bulkDelete()` endpoints |
| **Modify** | `routes/api.php` | Register all new routes with permissions |
| **Modify** | `routes/console.php` | Register schedules for overstay and no-show jobs |
| **Create** | `resources/js/components/settings/AccessGroupManager.vue` | UI component for access control groups |
| **Modify** | `resources/js/components/settings/SettingsHub.vue` | Add Access Groups navigation tab |
| **Modify** | `resources/js/components/leave/LeaveApprovalQueue.vue` | Add Cancel buttons and status handling |
| **Modify** | `resources/js/components/visitors/VisitorDashboard.vue` | Add Overstayed KPI, filter, warning badges, and Cancel Visit button |
| **Modify** | `resources/js/views/DeviceManager.vue` | Add selection checkboxes and batch action toolbar |
| **Modify** | `resources/js/views/PersonnelManager.vue` | Add selection checkboxes and batch action toolbar |

---

## Risk Areas & Mitigations

1. **Risk: Legacy Deployments without Access Groups:**
   - *Risk:* If an existing deployment creates personnel before access groups are set up, face synchronization could stop working.
   - *Mitigation:* `AccessControlService` must inspect `AccessGroup::count()`. If zero access groups exist, it gracefully defaults to all active devices (`Device::where('is_active', true)->get()`).
2. **Risk: Large MQTT Payload Exceeding Packet Limits in `AddPersons`:**
   - *Risk:* 50 full Base64 images in one JSON payload can exceed broker max message size (typically 1MB to 2MB).
   - *Mitigation:* Favor `picURI` URLs over raw base64 in `buildPersonnelInfo()`. If Base64 is necessary, chunk by 10-25 images instead of 50 or verify payload size before dispatch.
3. **Risk: Race Conditions in Leave Balance Restoration:**
   - *Risk:* Simultaneous cancellation and new leave application could cause negative balances or inconsistent state.
   - *Mitigation:* Wrap all balance adjustments inside `DB::transaction()` with pessimistic locking (`lockForUpdate()`) on `leave_balances`.
4. **Risk: Broker Flooding during Fleet Campaigns:**
   - *Risk:* Disagreeable MQTT downlink spikes if 100 devices are rebooted simultaneously.
   - *Mitigation:* `BulkDeviceCampaignJob` introduces inter-chunk pauses (e.g. 50-100ms) and processes devices in manageable chunks.
