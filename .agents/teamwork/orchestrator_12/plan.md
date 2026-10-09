# Project Plan: Frontend Optimization, Accessibility & Navigation (Milestones 4-6)

## Overview
Orchestrator 12 execution plan covering remaining tasks:
1. Conduct Gate verification for Milestone 4 (EMP-06..EMP-08 in EmployeeDirectory.vue).
2. Execute Milestone 5: Attendance Dashboard & Sub-Hub Navigation (DASH-01, DASH-02, HUB-01, LVE-06).
3. Execute Milestone 6: Documentation & Task Tracking (tasks-optimization.md Sections 20-24).
4. Run full verification (npm run build exit code 0, no native confirm, all tests passing).

## Milestone Breakdown

### Milestone 4: Workforce Directory & Modals Optimization Gate Verification
- **Status**: Implemented by `worker_m4`, pending Gate verification.
- **Scope**: `resources/js/components/employees/EmployeeDirectory.vue`
  - EMP-06: Replace `window.confirm()` with `notify.confirm()`.
  - EMP-07: Mode-specific skeleton loaders (table & grid) with `motion-reduce:animate-none`.
  - EMP-08: Assign Shift Modal & CSV Import Modal accessibility (`role="dialog"`, `aria-modal="true"`, Escape listeners, `<label for>` mappings).
- **Subagents to Dispatch**:
  - 2 Reviewers (`teamwork_preview_reviewer`)
  - 2 Challengers (`teamwork_preview_challenger`)
  - 1 Forensic Auditor (`teamwork_preview_auditor`)
- **Gate Criteria**:
  - npm run build exit code 0.
  - Zero `window.confirm()` occurrences.
  - Form labels and ARIA dialog semantics verified.
  - Auditor CLEAN verdict.

### Milestone 5: Attendance Dashboard & Sub-Hub Navigation
- **Scope**:
  - `resources/js/components/attendance/AttendanceDashboard.vue`: DASH-01 skeleton pulse loader for KPI metric cards during summary query; DASH-02 `motion-reduce:animate-none` on live stream pulsating indicator.
  - `resources/js/App.vue`: DASH-02 `motion-reduce:animate-none` on alert ping.
  - `resources/js/components/attendance/AttendanceHub.vue`: HUB-01 ARIA tabs pattern (`role="tablist"`, `role="tab"`, `aria-selected`, `aria-controls`, `role="tabpanel"`) and flex wrapping.
  - `resources/js/components/shifts/ScheduleHub.vue`: HUB-01 ARIA tabs pattern and flex wrapping.
  - `resources/js/components/visitors/VisitorHub.vue`: HUB-01 ARIA tabs pattern and flex wrapping.
  - `resources/js/components/settings/SettingsHub.vue`: HUB-01 ARIA tabs pattern and flex wrapping.
  - `resources/js/components/leave/LeaveCalendarView.vue`: LVE-06 loading skeleton state during `leaveStore.loading`.
- **Iteration Loop**:
  - Explorers (1-2) to analyze files, DOM structure, tabs, and loading states.
  - Worker (1) with exclusive write ownership to implement.
  - Reviewers (2), Challengers (2), Auditor (1) for Gate verification.

### Milestone 6: Documentation & Task Tracking
- **Scope**:
  - Update `tasks-optimization.md` marking completed items in Sections 20-24 as checked `[x]`.
  - Section 20: REP-04, REP-05, REP-06
  - Section 21: ROST-01, ROST-02, ROST-03, ROST-04, ROST-05
  - Section 22: CAL-01, CAL-02, CAL-03
  - Section 23: EMP-06, EMP-07, EMP-08
  - Section 24: DASH-01, DASH-02, HUB-01, LVE-06
- **Worker & Gate**:
  - Worker to update `tasks-optimization.md`.
  - Reviewer/Auditor to verify formatting and consistency.

### Milestone 7: Final End-to-End Verification
- Full test suite: `php artisan test`
- Frontend build: `npm run build`
- Zero native dialogs check (`window.confirm`)
