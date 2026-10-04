# Stage 4 Worker Dispatch: UI/UX & Accessibility Optimization (`tasks-optimization.md`)

## Objectives
You are the UI/UX & Frontend Optimization Worker. Your role is to formulate explicit Jules briefs for all 44 pending tasks across Sections 11 through 19 in `/home/wsk-devops2/AI-Camera-Integration/tasks-optimization.md`, dispatch them via `jules new --repo whoamikenken/AI-Camera-Integration "<Brief>"`, track remote sessions, pull/teleport patches (`jules remote pull --session <ID> --apply` or `jules teleport <ID>`), validate with `npm run build` and `php artisan test`, and mark corresponding tasks in `/home/wsk-devops2/AI-Camera-Integration/tasks-optimization.md` from `- [ ]` to `- [x]`.

## Mandatory Reading
First read:
`/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md`
and
`/home/wsk-devops2/AI-Camera-Integration/tasks-optimization.md`

## Integrity Warning
DO NOT CHEAT. All implementations must be genuine. DO NOT hardcode test results, create dummy/facade implementations, or circumvent the intended task. A teamwork_preview_auditor will independently verify your work. Integrity violations WILL be detected and your work WILL be rejected.

## Task Breakdown for Jules Sessions

### Brief 1: Stranger Monitoring & Access History (Sections 11 & 13)
- Target: `resources/js/views/StrangerSnapsMonitor.vue`, `resources/js/views/AccessLogsHistory.vue`
- Tasks: STR-01 through STR-05, LOG-01 through LOG-05
- Details:
  - STR-01: Explicit `for` and `id` bindings on Camera Device, From Date, To Date filter labels and inputs.
  - STR-02: Convert interactive stream carousel, card grid, and table rows to `<button>` or `role="button"` with `tabindex="0"`, descriptive `aria-label`, and `@keydown.enter`.
  - STR-03: Replace plain text loaders with 8-card skeleton grid and 5 animated skeleton table rows.
  - STR-04: Upgrade Image Inspection and Enroll Stranger modals to accessible dialogs (`role="dialog"`, `aria-modal="true"`, `aria-labelledby`, `@keydown.escape`, close button `aria-label`).
  - STR-05: Explicit label-to-input associations and `aria-required="true"` in stranger enrollment modal.
  - LOG-01: Explicit `aria-label` or `<label>` on search, status, camera, and match filters.
  - LOG-02: `scope="col"` on all table header `<th>` cells.
  - LOG-03: Replace single cell loading text with 6 animated skeleton rows.
  - LOG-04: Convert thumbnail preview container to semantic `<button>` with descriptive `aria-label`.
  - LOG-05: Upgrade Snapshot Inspection Modal to accessible dialog (`role="dialog"`, `aria-modal="true"`, Escape listener, close button name).

### Brief 2: Alerts Center & Sync Outbox Queue (Sections 12 & 14)
- Target: `resources/js/views/DeviceAlertsCenter.vue`, `resources/js/views/SyncTasksMonitor.vue`
- Tasks: ALT-01 through ALT-05, SYN-01 through SYN-04
- Details:
  - ALT-01: Associate severity, status, and camera filter labels with select elements via `for` and `id`.
  - ALT-02: Convert interactive incident card media containers and table thumbnails/titles to keyboard-accessible `<button>` triggers with descriptive `aria-label`.
  - ALT-03: Add `scope="col"` to all table header `<th>` cells.
  - ALT-04: Replace loading text with 5 animated skeleton table rows matching 7-column layout.
  - ALT-05: Upgrade Incident Detail Modal with `role="dialog"`, `aria-modal="true"`, `aria-labelledby`, Escape dismiss, accessible close button.
  - SYN-01: Add `aria-label="Filter sync tasks by status"` to status dropdown.
  - SYN-02: Add `scope="col"` to all table header `<th>` elements.
  - SYN-03: Replace plain text loader with animated skeleton table rows.
  - SYN-04: Contextual `aria-label="Retry sync task #[ID] for [Name]"` and loading/disabled spinner state on retry buttons.

### Brief 3: Hardware Diagnostics & Backfill Modals (Section 15)
- Target: `resources/js/components/devices/HistoricalBackfillModal.vue`, `resources/js/components/devices/DeviceAuditModal.vue`
- Tasks: AUD-01 through AUD-05
- Details:
  - AUD-01: Convert outer containers into semantic dialogs (`role="dialog"`, `aria-modal="true"`, `aria-labelledby`, `@keydown.escape`, close button `aria-label`).
  - AUD-02: Bind form labels with target controls using `for` and `id` in Historical Backfill modal.
  - AUD-03: Implement `role="radiogroup"` and `role="radio"` with `aria-checked` on log type segmented buttons.
  - AUD-04: Implement ARIA tabs pattern (`role="tablist"`, `role="tab"`, `aria-selected`, `aria-controls`) for sub-tabs in Device Audit modal.
  - AUD-05: Add `scope="col"` to roster table header cells and explicit `aria-label`s on search/status filters.

### Brief 4: Workforce Leave & Quota Management (Section 16)
- Target: `resources/js/views/LeaveHub.vue`, `resources/js/components/leaves/LeaveRequestForm.vue`, `resources/js/components/leaves/LeaveApprovalQueue.vue`, `resources/js/components/leaves/LeaveBalanceWidget.vue`
- Tasks: LVE-01 through LVE-05
- Details:
  - LVE-01: Wrap `LeaveRequestForm.vue` in accessible dialog attributes (`role="dialog"`, `aria-modal="true"`, `aria-labelledby`, Escape key) and bind all inputs with `<label for="...">` and `<input id="...">`.
  - LVE-02: Replace emoji spinner `⏳` with accessible SVG spinner during submission.
  - LVE-03: Add `aria-label` to filter dropdowns and `scope="col"` to table headers in `LeaveApprovalQueue.vue`.
  - LVE-04: Contextual `aria-label`s and loading/disabled states to Approve and Reject buttons.
  - LVE-05: Add `role="progressbar"`, `aria-valuenow`, `aria-valuemin="0"`, `aria-valuemax`, and accessible name to visual quota meter in `LeaveBalanceWidget.vue`; render skeleton cards during async balance queries.

### Brief 5: Shift & Schedule Management (Section 17)
- Target: `resources/js/components/schedules/ShiftManager.vue`, `resources/js/components/schedules/ShiftAssignment.vue`, `resources/js/components/schedules/HolidayCalendar.vue`
- Tasks: SCH-01 through SCH-05
- Details:
  - SCH-01: Add empty-state card when shifts array is empty; contextual `aria-label`s to shift Edit/Delete buttons.
  - SCH-02: Wrap Shift modal in dialog semantics, associate all labels with inputs via `for` and `id`, enclose in semantic `<form @submit.prevent>`.
  - SCH-03: Replace clickable shift selection cards with accessible radio group (`role="radiogroup"`, `role="radio"`, `aria-checked`, keyboard navigation).
  - SCH-04: Add `role="group"` and `aria-pressed="form.assigned_days.includes(day.id)"` to assigned working days toggle buttons.
  - SCH-05: Accessible labels (`aria-label="Previous month"`, `aria-label="Next month"`) to month navigation buttons; convert clickable holiday chips to accessible `<button>` triggers.

### Brief 6: Visitor, Watchlist & Settings (Sections 18 & 19)
- Target: `resources/js/views/VisitorDashboard.vue`, `resources/js/components/visitors/VisitorBadge.vue`, `resources/js/views/WatchlistManager.vue`, `resources/js/components/settings/DepartmentManager.vue`, `resources/js/components/settings/SystemSettings.vue`, `resources/js/components/settings/AuditLogViewer.vue`
- Tasks: VIS-05 through VIS-08, SET-01 through SET-06
- Details:
  - VIS-05: Replace native `window.confirm()` in `VisitorDashboard.vue` and `WatchlistManager.vue` with accessible confirmation modal dialogs.
  - VIS-06: Add `aria-label` to visit filter and refresh button; add `scope="col"` to table header cells.
  - VIS-07: Contextual `aria-label`s to "Pass", "Check Out", and "Check In" action buttons.
  - VIS-08: Upgrade `VisitorBadge.vue` modal with dialog semantics (`role="dialog"`, `aria-modal="true"`, Escape dismiss, close button name).
  - SET-01: Implement ARIA tabs pattern (`role="tablist"`, `role="tab"`, `aria-selected`, `aria-controls`) on Department Manager navigation bars.
  - SET-02: Ensure all Department, Designation, and Location modals include `role="dialog"`, `aria-modal="true"`, Escape listeners, and explicit `for`/`id` label mappings.
  - SET-03: Add `role="switch"`, `aria-checked`, and explicit `aria-label`s to custom toggle switches in `SystemSettings.vue`.
  - SET-04: Associate number inputs in `SystemSettings.vue` with `<label for="...">` and `<input id="...">`.
  - SET-05: Add explicit `aria-label` or `<label>` tags to search and filter dropdowns in `AuditLogViewer.vue`.
  - SET-06: Upgrade Change Diff modal in `AuditLogViewer.vue` with dialog semantics, Escape key listener, and close button name.

## Workflow Execution Steps
1. Dispatch Jules sessions using `jules new --repo whoamikenken/AI-Camera-Integration "<Brief>"`.
2. Monitor session statuses via `jules remote list --session`.
3. Pull or teleport completed sessions (`jules remote pull --session <ID> --apply` or `jules teleport <ID>`).
4. Validate changes:
   - Run `npm run build` (must pass with 0 errors).
   - Run `php artisan test` (must pass 100%).
5. Update `/home/wsk-devops2/AI-Camera-Integration/tasks-optimization.md`:
   Mark all 44 completed tasks from `- [ ]` to `- [x]`.
6. Write a comprehensive handoff report at `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_stage4_optimization/handoff.md`.
7. Send a message to orchestrator upon completion.


## 2026-10-04T02:39:03Z
You are the Worker subagent responsible for executing Stage 4: UI/UX & Accessibility Optimization (all 44 pending tasks across Sections 11 through 19 in tasks-optimization.md) using autonomous Jules CLI sessions.

Your working directory is:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_stage4_optimization

Read your dispatch instructions:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_stage4_optimization/DISPATCH.md

MANDATORY: Read the original user request before starting:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md

MANDATORY INTEGRITY WARNING:
DO NOT CHEAT. All implementations must be genuine. DO NOT hardcode test results, create dummy/facade implementations, or circumvent the intended task. A teamwork_preview_auditor will independently verify your work. Integrity violations WILL be detected and your work WILL be rejected.

Your responsibilities:
1. Formulate and dispatch Jules sessions for the 44 pending tasks across Sections 11-19 using `jules new --repo whoamikenken/AI-Camera-Integration "<Brief>"`.
2. Monitor session progress via `jules remote list --session`.
3. Pull/teleport and apply patches cleanly (`jules remote pull --session <ID> --apply` or `jules teleport <ID>`).
4. Validate changes with `npm run build` and `php artisan test`.
5. Update `/home/wsk-devops2/AI-Camera-Integration/tasks-optimization.md` by marking all 44 resolved tasks `- [x]`.
6. Document all Jules session IDs, brief summaries, pull statuses, test results, and verified tasks in:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_stage4_optimization/handoff.md
Once complete, send a message to your parent with your findings and results.
