## 2026-10-01T05:59:23Z
You are worker_a11y_1, a Frontend Accessibility & UX Implementation Specialist subagent.
Your working directory: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_a11y_1
Project root: /home/wsk-devops2/AI-Camera-Integration

MANDATORY FIRST STEP:
Read the authoritative user request at /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md.
Also read:
- /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/survey_frontend_a11y_1/handoff.md (Contains exhaustive code blueprints, exact line numbers, and complete drop-in templates for all 43 tasks across sections 1-10)
- /home/wsk-devops2/AI-Camera-Integration/tasks-optimization.md
- /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_2/SCOPE.md
- /home/wsk-devops2/AI-Camera-Integration/GEMINI.md

DO NOT CHEAT. All implementations must be genuine. DO NOT hardcode test results, create dummy/facade implementations, or circumvent the intended task. A teamwork_preview_auditor will independently verify your work. Integrity violations WILL be detected and your work WILL be rejected.

Your exclusive write boundaries:
You own all frontend files in `resources/js/` and Vite configuration:
- `resources/js/App.vue`
- `resources/js/components/notifications/NotificationBell.vue`
- `resources/js/views/LoginPage.vue`
- `resources/js/views/LiveTelemetry.vue`
- `resources/js/views/DeviceManager.vue`
- `resources/js/views/PersonnelManager.vue`
- `resources/js/components/employees/EmployeeDirectory.vue`
- `resources/js/components/employees/EmployeeFormModal.vue`
- `resources/js/components/employees/EmployeeProfileModal.vue`
- `resources/js/components/visitors/VisitorCheckInWizard.vue`
- `resources/js/components/CameraLivePreviewModal.vue`
- `resources/js/components/attendance/AttendanceDashboard.vue`
- `resources/js/components/attendance/ManualAttendanceEntry.vue`
- `resources/js/components/reports/PayrollExportModal.vue`
- `resources/js/echo.js`
- `vite.config.js`

Do NOT modify any backend PHP files (those are owned by worker_sec_1).

Your mission:
Implement all 43 accessibility, UI/UX, and performance tasks from `tasks-optimization.md` using the exact code blueprints in `survey_frontend_a11y_1/handoff.md`:
1. Section 1 (App Shell & Navigation):
   - APP-01: Mobile drawer ARIA (`aria-expanded`, `aria-label="Open navigation menu"`, `aria-controls="sidebar-nav"`, `aria-label="Close navigation menu"`).
   - APP-02: User profile menu keyboard accessibility (`role="menu"`, `role="menuitem"`, `tabindex="0"`, Escape listener, click-outside dismissal).
   - APP-03: Skeleton pulse cards for KPI metrics during fetch to eliminate CLS.
   - APP-04: `NotificationBell.vue` interactive dropdown (`aria-haspopup="dialog"`, `aria-expanded`, dynamic `aria-label`), accessible notification buttons with Enter/Space keyboard handlers.
   - APP-05: Wrap `animate-bounce` on notification badge in `motion-reduce:animate-none`.
2. Section 2 (Authentication & Sign-In):
   - AUTH-01: Login error banner `role="alert"` and `aria-live="assertive"`.
   - AUTH-02: Error dismissal button `aria-label="Dismiss error"` and focus ring.
   - AUTH-03: Password toggle button `:aria-label="showPassword ? 'Hide password' : 'Show password'"`, `:aria-pressed="showPassword"`, `aria-hidden="true"` on emojis.
   - AUTH-04: Inline validation feedback and focus retention.
3. Section 3 (Live Telemetry Stream):
   - TEL-01: Face snapshot thumbnail `<button>` or `role="button"`, `tabindex="0"`, `@keydown.enter` with descriptive `aria-label`.
   - TEL-02: Replace single spinner with 2-column skeleton card grid matching telemetry dimensions.
   - TEL-03: Accessible labels (`aria-label`) on stream filter, per-page select, refresh button, scene button.
   - TEL-04: Image inspection modal dialog semantics (`role="dialog"`, `aria-modal="true"`, `aria-labelledby`), focus trap, Escape key listener.
   - TEL-05: Audio alert toggle button `role="switch"` and `:aria-checked="store.soundEnabled"`.
4. Section 4 (Device Manager):
   - DEV-01: Bind all `<label>` tags with input/select controls via explicit `for` and `id`.
   - DEV-02: Distinct `:aria-label="`Edit camera ${device.name}`"` and `:aria-label="`Delete camera ${device.name}`"`.
   - DEV-03: Responsive flex-wrap / mobile action layout on viewports <640px ensuring minimum 44x44px touch targets.
   - DEV-04: ARIA tabs pattern on modal (`role="tablist"`, `role="tab"`, `aria-selected`, `aria-controls`).
   - DEV-05: Configuration modal dialog semantics (`role="dialog"`, `aria-modal="true", focus trap, Escape listener).
5. Section 5 (Personnel Manager):
   - PERS-01: Search and filter `aria-label`s.
   - PERS-02: `scope="col"` on table headers (`<th>`) and accessible names on actions (Edit, Delete, Sync).
   - PERS-03: 5 animated skeleton table rows replacing single text cell.
   - PERS-04: Associate form labels with inputs (`for`/`id`) and accessible label on file input.
   - PERS-05: Submit button loading spinner during photo base64 encoding and sync.
6. Section 6 (Employee Directory & Modals):
   - EMP-01: Table/Grid toggle `role="group"` and `aria-pressed`.
   - EMP-02: Accessible icon buttons with explicit `aria-label` replacing raw emojis.
   - EMP-03: Input `id`/`for` bindings and `aria-required="true"`.
   - EMP-04: Semantic `<form @submit.prevent>` so Enter submits form.
   - EMP-05: Modal dialog semantics (`role="dialog"`, `aria-modal="true"`, focus trap, Escape listener).
7. Section 7 (Visitor Check-In Wizard):
   - VIS-01: Semantic step progress indicators (`aria-current="step"`, `aria-live="polite"`).
   - VIS-02: Required field validation before advancing wizard steps.
   - VIS-03: Bind form labels with inputs via `for` and `id` across all 3 steps.
   - VIS-04: Accessible SVG spinner with `aria-live` during camera provisioning.
8. Section 8 (Camera Live Preview Modal):
   - CAM-01: Responsive header layout on viewports <640px to prevent control overflow.
   - CAM-02: Explicit `aria-label` on fullscreen toggle and close buttons.
   - CAM-03: Stream quality switcher `role="group"` and `aria-pressed`.
   - CAM-04: Focus trap and Escape key listener on video preview dialog.
9. Section 9 (Attendance Dashboard & Manual Entry):
   - ATT-01: `aria-live="polite"` and `aria-relevant="additions"` on live attendance stream.
   - ATT-02: Accessible modal confirmation component replacing native `window.confirm()` on finalize attendance.
   - ATT-03: Bind form labels to inputs with `for`/`id` and dialog accessibility on manual entry modal.
10. Section 10 (Payroll Export Modal):
   - REP-01: Asynchronous loading state with disabled button and spinner during export.
   - REP-02: `<fieldset>` and `<legend>` wrapping export format radio group.
   - REP-03: Bind `for`/`id` on Month and Year selects.
11. Architecture Harmonization:
   - In `resources/js/App.vue`, use `defineAsyncComponent` for heavy views (`DeviceManager`, `PersonnelManager`, `AccessLogsHistory`, `StrangerSnapsMonitor`, `ReportsPage`, etc.) to optimize bundle loading.
   - In `resources/js/echo.js` and `resources/js/App.vue`, configure Echo listeners to use private channels (`echo.private('access-logs')`, `echo.private('device-alerts')`, etc.) with auth headers matching the secure backend.
   - Deduplicate Echo listeners in `App.vue` and add `isTelemetryInitialized` guard to prevent memory leaks.
