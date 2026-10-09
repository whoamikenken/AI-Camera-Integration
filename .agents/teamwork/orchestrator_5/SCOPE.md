# Scope: Frontend UI/UX & Accessibility Optimization (Sections 20-24)

## Architecture
- Vue 3 Composition API, Vite, Pinia, Tailwind CSS.
- Notification & Dialog services: `notify.confirm()`.
- Accessible SVG spinners, ARIA tabs pattern, semantic dialogs with Escape key handling.
- Motion preferences: `motion-reduce:animate-none`.

## Feature Inventory
| # | Feature | Description | Milestone | Source |
|---|---------|-------------|-----------|--------|
| 1 | REP-04 | Label association (for/id) for period, date, month, year, dept in AttendanceReports.vue | M1 | tasks-optimization.md §20 |
| 2 | REP-05 | 8-column animated skeleton table & accessible SVG spinner in AttendanceReports.vue | M1 | tasks-optimization.md §20 |
| 3 | REP-06 | Export CSV disabled & loading state feedback in AttendanceReports.vue | M1 | tasks-optimization.md §20 |
| 4 | ROST-01 | Replace window.confirm() with notify.confirm() in DailyAttendanceRoster.vue | M2 | tasks-optimization.md §21 |
| 5 | ROST-02 | Add aria-labels to date, dept, status, search, refresh in DailyAttendanceRoster.vue | M2 | tasks-optimization.md §21 |
| 6 | ROST-03 | Add scope="col" to table header cells in DailyAttendanceRoster.vue | M2 | tasks-optimization.md §21 |
| 7 | ROST-04 | 5 animated skeleton table rows matching columns in DailyAttendanceRoster.vue | M2 | tasks-optimization.md §21 |
| 8 | ROST-05 | Upgrade Status Override Modal to role="dialog", aria-modal="true", Escape handling, labels | M2 | tasks-optimization.md §21 |
| 9 | CAL-01 | Convert calendar modal wrapper to role="dialog", aria-modal="true", Escape handler, close label | M3 | tasks-optimization.md §22 |
| 10 | CAL-02 | Add aria-label="Previous month" and "Next month" to navigation in EmployeeAttendanceCalendar.vue | M3 | tasks-optimization.md §22 |
| 11 | CAL-03 | role="grid", cell aria-labels with date/status, skeleton loading state during async queries | M3 | tasks-optimization.md §22 |
| 12 | EMP-06 | Replace window.confirm() on employee deletion with notify.confirm() in EmployeeDirectory.vue | M4 | tasks-optimization.md §23 |
| 13 | EMP-07 | Mode-specific skeleton loaders (table vs grid cards) in EmployeeDirectory.vue | M4 | tasks-optimization.md §23 |
| 14 | EMP-08 | Upgrade Assign Shift & CSV Import Modals to compliant dialogs (role="dialog", labels, Escape) | M4 | tasks-optimization.md §23 |
| 15 | DASH-01 | Skeleton pulse loader to KPI metric cards in AttendanceDashboard.vue | M5 | tasks-optimization.md §24 |
| 16 | DASH-02 | motion-reduce:animate-none override on live attendance indicator and App.vue alert ping | M5 | tasks-optimization.md §24 |
| 17 | HUB-01 | ARIA tabs pattern & responsive flex wrapping across AttendanceHub, ScheduleHub, VisitorHub, SettingsHub | M5 | tasks-optimization.md §24 |
| 18 | LVE-06 | Loading skeleton state in LeaveCalendarView.vue to prevent "No approved leaves" flash | M5 | tasks-optimization.md §24 |
| 19 | DOC-01 | Update tasks-optimization.md to mark all completed items [x] | M6 | tasks-optimization.md §R6 |

## Milestones
| # | Name | Scope | Dependencies | Status |
|---|------|-------|-------------|--------|
| M1 | Attendance Reports Optimization | REP-04, REP-05, REP-06 in AttendanceReports.vue | none | DONE |
| M2 | Daily Attendance Roster & Overrides | ROST-01..ROST-05 in DailyAttendanceRoster.vue | none | DONE |
| M3 | Employee Attendance Calendar a11y | CAL-01..CAL-03 in EmployeeAttendanceCalendar.vue | none | PLANNED |
| M4 | Workforce Directory & Modals | EMP-06..EMP-08 in EmployeeDirectory.vue | none | PLANNED |
| M5 | Attendance Dashboard & Sub-Hub Navigation | DASH-01, DASH-02, HUB-01, LVE-06 | none | PLANNED |
| M6 | Task Tracking & Documentation | Mark tasks in tasks-optimization.md | M1-M5 | PLANNED |

## Interface Contracts
- `notify.confirm()`: Promise-based confirmation modal from `useNotification()` or similar composable.
- Modal dialogs: `role="dialog"`, `aria-modal="true"`, `@keydown.escape="close"`, `aria-labelledby`, accessible close button with `aria-label`.
- Skeletons: pulse animation (`animate-pulse`) with accessible layout geometry matching actual content.
- Reduced motion: `motion-reduce:animate-none` on all pulsating or bouncing elements.
- ARIA tabs pattern: container with `role="tablist"`, triggers with `role="tab"` and `:aria-selected="active"`, panels with `role="tabpanel"`.
