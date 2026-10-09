# DISPATCH DIRECTIVE — Visitor & Background Job Explorer (M3)

## Identity & Role
- **Agent**: `teamwork_preview_explorer_m3_11_2`
- **Archetype**: `teamwork_preview_explorer`
- **Role**: Visitor Lifecycle & Scheduled Job Explorer for Milestone M3
- **Working Directory**: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_m3_11_2`
- **Parent Conversation ID**: `340b2ee2-86ac-4ca7-9f71-8c1542c65adb`

## Mandatory Reference Documents
You MUST read these documents before starting your investigation:
1. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md` (authoritative user request)
2. `/home/wsk-devops2/AI-Camera-Integration/system-evo.md` (Feature 2)
3. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md`

## Technical Exploration Objective
Investigate Visitor lifecycle, edge camera face de-provisioning, background jobs, and frontend UI:
1. **Visitor Domain**:
   - Inspect `app/Models/Visit.php`, `app/Models/Visitor.php`, `app/Services/VisitorSyncService.php`, `app/Http/Controllers/VisitorController.php`.
   - Check how visits are scheduled, approved, checked in, and checked out.
   - Trace edge camera face synchronization: how visitor face templates are provisioned to cameras upon approval/check-in (`SyncPersonnelJob` / `DelPerson` / `DeletePersons`).
   - Design `VisitorSyncService::cancelVisit(Visit $visit, ?User $user, ?string $reason)`:
     * Check valid state (e.g. `expected -> cancelled`).
     * Trigger immediate de-provisioning of face credentials from edge cameras using `VisitorSyncService` or `SyncPersonnelJob` (delete person from authorized cameras).
     * Set status `cancelled`, metadata `cancellation_reason`, `cancelled_by`, `cancelled_at`.
2. **Overstay & No-Show Background Jobs**:
   - Inspect database columns on `visits`: `expected_departure`, `overstay_alerted_at`, etc. What migration is needed?
   - Design `App\Jobs\DetectOverstayVisitorsJob`:
     * Query active visits: `Visit::where('status', 'checked_in')->where('expected_departure', '<', now())->whereNull('overstay_alerted_at')`.
     * Transition or flag visit as overstayed (`status = 'overstayed'` or set alert).
     * Create `DeviceAlert` or fire security notification/event.
     * Record `overstay_alerted_at = now()`.
   - Design `App\Jobs\ExpireNoShowVisitsJob`:
     * Query abandoned visits: `Visit::where('status', 'expected')->whereDate('visit_date', '<', today())`.
     * Transition status to `'no_show'`.
     * Clean up any provisioned edge camera templates if pre-provisioned.
   - Investigate scheduler registration in `routes/console.php` (Laravel 11 `Schedule::job(...)->everyFifteenMinutes()`, `->dailyAt('00:00')`).
   - Endpoint `GET /api/visits/overstayed`: list overstayed visits with visitor and host details.
3. **Frontend UI Surfaces**:
   - Inspect `resources/js/views/VisitorDashboard.vue`, `resources/js/views/LeaveApprovalQueue.vue`, and `resources/js/views/SelfServicePortal.vue`.
   - Check for cancellation buttons, status badges (`overstayed`, `no_show`, `cancelled`), and overstay alert indicators.
4. **Existing Tests**:
   - Review `tests/Feature/VisitorTest.php` or `tests/Feature/Visit*` tests.

## Deliverables
Write a comprehensive technical report to `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_m3_11_2/handoff.md` with:
- Exact file paths, line numbers, and existing code snippets.
- Proposed implementation changes, step-by-step logic, edge case handling.
- Suggested automated tests.
Notify parent via `send_message`.
