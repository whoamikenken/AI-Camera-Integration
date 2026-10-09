# Frontend UI/UX & Accessibility Optimization Roadmap (`tasks-optimization.md`)

This optimization roadmap documents all audit findings, UX friction points, accessibility (WCAG 2.1 AA) violations, responsive design defects, and missing interactive states identified across the **Intelligent AI Camera Hub** frontend application (`resources/js`).

---

## 📋 Optimization Backlog & Action Items

### 1. Global App Shell & Navigation
- [x] **APP-01 (a11y):** Add proper ARIA attributes to mobile drawer trigger and close buttons (`aria-expanded`, `aria-label="Open navigation menu"`, `aria-controls="sidebar-nav"`) in `resources/js/App.vue`.
- [x] **APP-02 (a11y):** Add accessible roles and keyboard navigation (`role="menu"`, `role="menuitem"`, `aria-expanded`, Escape key handler, click-outside dismissal) to User Profile Menu in `resources/js/App.vue`.
- [x] **APP-03 (Interaction):** Implement skeleton pulse cards for KPI Summary Metric cards while telemetry statistics are being fetched from the backend API in `resources/js/App.vue`.
- [x] **APP-04 (a11y):** Refactor `NotificationBell.vue` interactive dropdown button (`aria-haspopup="dialog"`, `aria-expanded`, dynamic `aria-label`) and convert notification items from raw clickable `<div>` elements into accessible `<button>` with keyboard trigger support (`Enter` / `Space`).
- [x] **APP-05 (Visual Polish):** Wrap `animate-bounce` on notification badge in `motion-reduce:animate-none` override to prevent vestibular motion sickness for sensitive users in `resources/js/components/notifications/NotificationBell.vue`.

### 2. Authentication & Sign-In (`LoginPage.vue`)
- [x] **AUTH-01 (a11y):** Add `role="alert"` and `aria-live="assertive"` to the login error banner in `resources/js/views/LoginPage.vue` so screen readers immediately announce authentication failures.
- [x] **AUTH-02 (a11y):** Add explicit `aria-label="Dismiss error"` and visible focus rings to error dismissal button in `resources/js/views/LoginPage.vue`.
- [x] **AUTH-03 (a11y):** Add explicit `aria-label` to the password visibility toggle button (`"Show password"` / `"Hide password"`) and update `aria-pressed` state in `resources/js/views/LoginPage.vue`.
- [x] **AUTH-04 (Interaction):** Add inline field validation feedback and preserve focus management on failed submissions in `resources/js/views/LoginPage.vue`.

### 3. Live Telemetry Stream (`LiveTelemetry.vue`)
- [x] **TEL-01 (a11y):** Replace custom clickable thumbnail `<div>` with semantic `<button>` with descriptive `aria-label` in `resources/js/views/LiveTelemetry.vue`.
- [x] **TEL-02 (a11y & Performance):** Replace single-spinner loading state with a responsive skeleton grid matching the card dimensions to eliminate Cumulative Layout Shift (CLS) in `resources/js/views/LiveTelemetry.vue`.
- [x] **TEL-03 (a11y):** Add accessible labels (`aria-label`) to stream filters, per-page dropdown, and icon-only refresh button in `resources/js/views/LiveTelemetry.vue`.
- [x] **TEL-04 (a11y):** Convert Image Inspection Modal into a WCAG-compliant dialog (`role="dialog"`, `aria-modal="true"`, `aria-labelledby`, Escape key handling, and background inert/focus trap) in `resources/js/views/LiveTelemetry.vue`.
- [x] **TEL-05 (a11y):** Add `role="switch"` and `aria-checked` to the audio alert toggle button in `resources/js/views/LiveTelemetry.vue`.

### 4. Edge Device & Fleet Manager (`DeviceManager.vue`)
- [x] **DEV-01 (a11y):** Associate all `<label>` tags with input/select controls via explicit `for` and `id` attributes in `resources/js/views/DeviceManager.vue`.
- [x] **DEV-02 (a11y):** Add distinct `aria-label` tags to icon-only action buttons (Edit, Delete) incorporating the specific camera name in `resources/js/views/DeviceManager.vue`.
- [x] **DEV-03 (Responsive & UX):** Refactor secondary action buttons grid to a responsive flex-wrap layout to prevent text truncation and ensure minimum 44x44px touch targets in `resources/js/views/DeviceManager.vue`.
- [x] **DEV-04 (a11y):** Refactor modal tab navigation with ARIA tabs pattern (`role="tablist"`, `role="tab"`, `aria-selected`, `aria-controls`) in `resources/js/views/DeviceManager.vue`.
- [x] **DEV-05 (a11y):** Add modal dialog semantics (`role="dialog"`, `aria-modal="true"`, focus trap, Escape listener) and descriptive close button label to the camera configuration modal in `resources/js/views/DeviceManager.vue`.

### 5. Personnel & Face Library (`PersonnelManager.vue`)
- [x] **PERS-01 (a11y):** Add explicit `aria-label` or `<label>` tags to search input and filter dropdowns in `resources/js/views/PersonnelManager.vue`.
- [x] **PERS-02 (a11y):** Add `scope="col"` to all table header cells (`<th>`) and contextual accessible names to action buttons (Edit, Delete, Sync) in `resources/js/views/PersonnelManager.vue`.
- [x] **PERS-03 (Interaction & CLS):** Replace single loading cell with 5 animated skeleton table rows to eliminate jarring table layout shifts in `resources/js/views/PersonnelManager.vue`.
- [x] **PERS-04 (a11y):** Associate form labels with inputs (`for` / `id`) and add accessible label to the file input in `resources/js/views/PersonnelManager.vue`.
- [x] **PERS-05 (Interaction):** Add loading spinner to submit button during photo Base64 encoding and MQTT queue dispatch in `resources/js/views/PersonnelManager.vue`.

### 6. Workforce Directory & Profiles (`EmployeeDirectory.vue` & `EmployeeFormModal.vue`)
- [x] **EMP-01 (a11y):** Add `role="group"` and `aria-pressed` states to the Table vs Grid view mode toggle buttons in `resources/js/components/employees/EmployeeDirectory.vue`.
- [x] **EMP-02 (a11y):** Replace emoji-only action buttons (View Profile, Edit, Assign Shift) with accessible icon buttons featuring explicit `aria-label` in `resources/js/components/employees/EmployeeDirectory.vue`.
- [x] **EMP-03 (a11y & Forms):** Wrap all input fields in `resources/js/components/employees/EmployeeFormModal.vue` with properly mapped `id` and `for` associations and add `aria-required="true"`.
- [x] **EMP-04 (Interaction):** Convert modal wrapper into semantic `<form>` submission so pressing `Enter` submits the form cleanly rather than requiring an explicit mouse click in `resources/js/components/employees/EmployeeFormModal.vue`.
- [x] **EMP-05 (a11y):** Add dialog semantics (`role="dialog"`, `aria-modal="true"`, focus trapping, Escape listener) and accessible label to the webcam capture interface in `resources/js/components/employees/EmployeeFormModal.vue`.

### 7. Visitor Management & Kiosk Check-In (`VisitorCheckInWizard.vue`)
- [x] **VIS-01 (a11y):** Implement semantic step progress indicators (`aria-current="step"`, `role="progressbar"` or numbered breadcrumb list) and announce step transitions via `aria-live="polite"` in `resources/js/components/visitors/VisitorCheckInWizard.vue`.
- [x] **VIS-02 (Interaction):** Add step-by-step form validation to prevent advancing from Step 1 to Step 2/3 when required visitor fields (e.g. First Name) are empty in `resources/js/components/visitors/VisitorCheckInWizard.vue`.
- [x] **VIS-03 (a11y):** Bind all form labels with inputs via `for` and `id` across all three wizard steps in `resources/js/components/visitors/VisitorCheckInWizard.vue`.
- [x] **VIS-04 (Interaction):** Add an accessible SVG spinner and announce status via `aria-live` during async visitor camera provisioning in `resources/js/components/visitors/VisitorCheckInWizard.vue`.

### 8. Live Video Preview & WebRTC/WSS Player (`CameraLivePreviewModal.vue`)
- [x] **CAM-01 (Responsive):** Redesign modal header layout with responsive flex wrap or compact menu on mobile viewports (<640px) to prevent player controls overflow in `resources/js/components/CameraLivePreviewModal.vue`.
- [x] **CAM-02 (a11y):** Add explicit `aria-label` to fullscreen toggle and close buttons in `resources/js/components/CameraLivePreviewModal.vue`.
- [x] **CAM-03 (a11y):** Add `role="group"` and `aria-pressed` to 1080P Main / 720P Sub stream quality switcher buttons in `resources/js/components/CameraLivePreviewModal.vue`.
- [x] **CAM-04 (a11y):** Implement focus trap and Escape key listener on video preview dialog in `resources/js/components/CameraLivePreviewModal.vue`.

### 9. Attendance Stream & Manual Entry (`AttendanceDashboard.vue` & `ManualAttendanceEntry.vue`)
- [x] **ATT-01 (a11y):** Add `aria-live="polite"` and `aria-relevant="additions"` to the Live Attendance Clock-In Stream container so assistive technology announces incoming biometric clock-ins in `resources/js/components/attendance/AttendanceDashboard.vue`.
- [x] **ATT-02 (UX & a11y):** Replace native browser `window.confirm()` with an accessible confirmation dialog component for "Finalize Today's Attendance" in `resources/js/components/attendance/AttendanceDashboard.vue`.
- [x] **ATT-03 (a11y):** Bind all form labels to inputs with `for` / `id` and provide dialog accessibility in `resources/js/components/attendance/ManualAttendanceEntry.vue`.

### 10. Reports & Payroll Export (`PayrollExportModal.vue`)
- [x] **REP-01 (Interaction):** Add an asynchronous loading state with button disabling and spinner during CSV/JSON export generation in `resources/js/components/reports/PayrollExportModal.vue`.
- [x] **REP-02 (a11y):** Wrap export format radio buttons in a semantic `<fieldset>` with `<legend>` for screen reader group context in `resources/js/components/reports/PayrollExportModal.vue`.
- [x] **REP-03 (a11y):** Add `for` and `id` to Payroll Month and Year select inputs in `resources/js/components/reports/PayrollExportModal.vue`.

---

### 11. Stranger Snapshot Monitor (`StrangerSnapsMonitor.vue`)
- [x] **STR-01 (a11y):** Associate filter labels (`Camera Device`, `From Date`, `To Date`) with form controls using explicit `for` and `id` attributes in `resources/js/views/StrangerSnapsMonitor.vue:61-96`. `[ARCHIVED · Jules 11004211380295526672]`
- [x] **STR-02 (a11y & Semantics):** Replace interactive `<div>` containers in the live incoming stream carousel (`line 127`), card grid (`line 168`), and table rows (`line 252`) with semantic `<button>` elements, or equip them with `role="button"`, `tabindex="0"`, descriptive `aria-label`, and `@keydown.enter` handlers. `[ARCHIVED · Jules 11004211380295526672]`
- [x] **STR-03 (CLS & States):** Replace plain text loading placeholders (`"Loading stranger snapshots..."` lines 151, 243) with an 8-card skeleton grid and 5 animated skeleton table rows to prevent Cumulative Layout Shift (CLS). `[ARCHIVED · Jules 11004211380295526672]`
- [x] **STR-04 (a11y):** Upgrade Image Inspection Modal and Enroll Stranger Modal (`lines 320, 392`) to fully compliant dialogs (`role="dialog"`, `aria-modal="true"`, `aria-labelledby`, `@keydown.escape`, and descriptive close button `aria-label="Close dialog"`). `[ARCHIVED · Jules 11004211380295526672]`
- [x] **STR-05 (Forms & a11y):** Bind all inputs in the stranger enrollment modal (`lines 448-520`) with associated `<label for="...">` and `<input id="...">` attributes and add `aria-required="true"`. `[ARCHIVED · Jules 11004211380295526672]`

### 12. AI Safety & Security Alerts Center (`DeviceAlertsCenter.vue`)
- [x] **ALT-01 (a11y):** Associate severity, status, and camera filter labels with select elements via explicit `for` and `id` attributes in `resources/js/views/DeviceAlertsCenter.vue:100-140`. `[ARCHIVED · Jules 9134677463384687766]`
- [x] **ALT-02 (a11y):** Convert interactive incident card media containers (`line 178`) and table thumbnails/titles (`lines 292, 296`) to keyboard-accessible `<button>` triggers with descriptive `aria-label="Inspect incident for [Title]"`. `[ARCHIVED · Jules 9134677463384687766]`
- [x] **ALT-03 (a11y):** Add `scope="col"` to all table header `<th>` cells in `resources/js/views/DeviceAlertsCenter.vue:265-271`. `[ARCHIVED · Jules 9134677463384687766]`
- [x] **ALT-04 (CLS & States):** Replace single text row (`"Loading alerts..."` line 276) with 5 animated skeleton table rows matching the 7-column layout. `[ARCHIVED · Jules 9134677463384687766]`
- [x] **ALT-05 (a11y):** Upgrade Incident Detail Modal (`line 369`) with `role="dialog"`, `aria-modal="true"`, `aria-labelledby="incident-modal-title"`, Escape key dismiss, and an accessible close button name. `[ARCHIVED · Jules 9134677463384687766]`

### 13. Access Telemetry & Audit Logs History (`AccessLogsHistory.vue`)
- [x] **LOG-01 (a11y):** Add explicit `aria-label` or `<label>` tags to search, status, camera, and minimum match percentage filter inputs in `resources/js/views/AccessLogsHistory.vue:21-50`. `[ARCHIVED · Jules 11004211380295526672]`
- [x] **LOG-02 (a11y):** Add `scope="col"` to all table header `<th>` elements in `resources/js/views/AccessLogsHistory.vue:58-64`. `[ARCHIVED · Jules 11004211380295526672]`
- [x] **LOG-03 (CLS & States):** Replace single cell loading text (`line 69`) with 6 animated skeleton rows matching table geometry. `[ARCHIVED · Jules 11004211380295526672]`
- [x] **LOG-04 (a11y):** Convert thumbnail preview `<div>` (`line 76`) to a semantic `<button>` with descriptive `aria-label` and keyboard interaction. `[ARCHIVED · Jules 11004211380295526672]`
- [x] **LOG-05 (a11y):** Upgrade Snapshot Inspection Modal (`line 121`) with `role="dialog"`, `aria-modal="true"`, `aria-labelledby`, Escape listener, and accessible close button. `[ARCHIVED · Jules 11004211380295526672]`

### 14. Edge Device Sync Outbox Queue (`SyncTasksMonitor.vue`)
- [x] **SYN-01 (a11y):** Add explicit `aria-label="Filter sync tasks by status"` or visible `<label>` to status dropdown in `resources/js/views/SyncTasksMonitor.vue:15`. `[ARCHIVED · Jules 9134677463384687766]`
- [x] **SYN-02 (a11y):** Add `scope="col"` to all table header `<th>` elements in `resources/js/views/SyncTasksMonitor.vue:30-37`. `[ARCHIVED · Jules 9134677463384687766]`
- [x] **SYN-03 (CLS & States):** Replace plain text loader (`line 42`) with animated skeleton table rows. `[ARCHIVED · Jules 9134677463384687766]`
- [x] **SYN-04 (Interaction & a11y):** Add contextual `aria-label="Retry sync task #[ID] for [Name]"` and disabled/loading spinner state (`retryingId === task.id`) to task retry buttons in `resources/js/views/SyncTasksMonitor.vue:68-72`. `[ARCHIVED · Jules 9134677463384687766]`

### 15. Hardware Diagnostics & Historical Backfill Modals (`HistoricalBackfillModal.vue` & `DeviceAuditModal.vue`)
- [x] **AUD-01 (a11y):** Convert `HistoricalBackfillModal.vue:2` and `DeviceAuditModal.vue:2` outer containers into semantic dialogs (`role="dialog"`, `aria-modal="true"`, `aria-labelledby`, `@keydown.escape`, and close button `aria-label`). `[ARCHIVED · Jules 13893614969077389184]`
- [x] **AUD-02 (a11y):** Bind all form labels in `HistoricalBackfillModal.vue:29-138` with target controls using `for` and `id` attributes. `[ARCHIVED · Jules 13893614969077389184]`
- [x] **AUD-03 (a11y):** Implement `role="radiogroup"` and `role="radio"` with `aria-checked` on the log type segmented buttons in `HistoricalBackfillModal.vue:44-69`. `[ARCHIVED · Jules 13893614969077389184]`
- [x] **AUD-04 (a11y):** Implement ARIA tabs pattern (`role="tablist"`, `role="tab"`, `aria-selected`, `aria-controls`) for sub-tabs in `DeviceAuditModal.vue:29-51`. `[ARCHIVED · Jules 13893614969077389184]`
- [x] **AUD-05 (a11y):** Add `scope="col"` to roster table header cells in `DeviceAuditModal.vue:136-140` and provide explicit `aria-label`s on search and status filters. `[ARCHIVED · Jules 13893614969077389184]`

### 16. Workforce Leave & Quota Management (`LeaveHub.vue`, `LeaveRequestForm.vue`, `LeaveApprovalQueue.vue`, `LeaveBalanceWidget.vue`)
- [x] **LVE-01 (a11y & Forms):** Wrap `LeaveRequestForm.vue:2-10` in accessible dialog attributes (`role="dialog"`, `aria-modal="true"`, `aria-labelledby`, Escape key handling) and bind all form inputs with explicit `<label for="...">` and `<input id="...">` attributes. `[ARCHIVED · Jules 10038488321736153251]`
- [x] **LVE-02 (Interaction):** Replace emoji spinner `⏳` with an accessible SVG spinner during active submission in `LeaveRequestForm.vue:52`. `[ARCHIVED · Jules 10038488321736153251]`
- [x] **LVE-03 (a11y):** Add `aria-label` to filter dropdowns and `scope="col"` to table header cells in `LeaveApprovalQueue.vue:5-17, 30-36`. `[ARCHIVED · Jules 10038488321736153251]`
- [x] **LVE-04 (Interaction & a11y):** Add contextual `aria-label`s and loading/disabled states to Approve and Reject buttons during async API mutations in `LeaveApprovalQueue.vue:72-77`. `[ARCHIVED · Jules 10038488321736153251]`
- [x] **LVE-05 (a11y & CLS):** Add `role="progressbar"`, `aria-valuenow`, `aria-valuemin="0"`, `aria-valuemax`, and accessible name to the visual quota meter in `LeaveBalanceWidget.vue:21-23`, and provide skeleton cards during async balance queries. `[ARCHIVED · Jules 10038488321736153251]`

### 17. Shift & Schedule Management (`ShiftManager.vue`, `ShiftAssignment.vue`, `HolidayCalendar.vue`)
- [x] **SCH-01 (Zero-State & a11y):** Add an empty-state illustration/card when `store.shifts` has no records, and add contextual `aria-label`s to shift Edit/Delete buttons in `resources/js/components/schedules/ShiftManager.vue`. `[JULES: AWAITING FEEDBACK · 3874137239943605297]`
- [x] **SCH-02 (a11y & Forms):** Wrap Shift modal (`ShiftManager.vue:109`) in dialog semantics, associate all labels with inputs via `for` and `id`, and enclose in semantic `<form @submit.prevent>`. `[JULES: AWAITING FEEDBACK · 3874137239943605297]`
- [x] **SCH-03 (a11y):** Replace clickable `<div>` shift selection cards in `ShiftAssignment.vue:26-41` with accessible radio group elements (`role="radiogroup"`, `role="radio"`, `aria-checked`, keyboard arrow navigation). `[JULES: AWAITING FEEDBACK · 3874137239943605297]`
- [x] **SCH-04 (a11y):** Add `role="group"` and `aria-pressed="form.assigned_days.includes(day.id)"` to assigned working days toggle buttons in `ShiftAssignment.vue:153-163`. `[JULES: AWAITING FEEDBACK · 3874137239943605297]`
- [x] **SCH-05 (a11y):** Add accessible labels (`aria-label="Previous month"`, `aria-label="Next month"`) to month navigation buttons, and convert clickable holiday chips (`HolidayCalendar.vue:121-131`) into accessible `<button>` triggers. `[JULES: AWAITING FEEDBACK · 3874137239943605297]`

### 18. Visitor & Watchlist Management (`VisitorDashboard.vue`, `VisitorBadge.vue`, `WatchlistManager.vue`)
- [x] **VIS-05 (a11y & UX):** Replace native browser `window.confirm()` in `VisitorDashboard.vue:130` and `WatchlistManager.vue:64` with accessible confirmation modal dialogs. `[ARCHIVED · Jules 13610617331227140394]`
- [x] **VIS-06 (a11y):** Add `aria-label` to visit filter select and refresh button, and add `scope="col"` to table header cells in `VisitorDashboard.vue:37-60`. `[ARCHIVED · Jules 13610617331227140394]`
- [x] **VIS-07 (a11y):** Add contextual `aria-label`s to "Pass", "Check Out", and "Check In" action buttons in `VisitorDashboard.vue:87-95`. `[ARCHIVED · Jules 13610617331227140394]`
- [x] **VIS-08 (a11y):** Upgrade `VisitorBadge.vue:2-7` modal with `role="dialog"`, `aria-modal="true"`, `aria-labelledby`, Escape listener, and accessible close button name. `[ARCHIVED · Jules 13610617331227140394]`

### 19. Organization & System Settings (`DepartmentManager.vue`, `SystemSettings.vue`, `AuditLogViewer.vue`)
- [x] **SET-01 (a11y):** Implement ARIA tabs pattern (`role="tablist"`, `role="tab"`, `aria-selected`, `aria-controls`) on navigation bars in `DepartmentManager.vue:38-51`. `[ARCHIVED · Jules 13610617331227140394]`
- [x] **SET-02 (a11y & Forms):** Ensure all Department, Designation, and Location modals in `DepartmentManager.vue` include `role="dialog"`, `aria-modal="true"`, `aria-labelledby`, Escape listeners, and explicit `for` / `id` label mappings. `[ARCHIVED · Jules 13610617331227140394]`
- [x] **SET-03 (a11y):** Add `role="switch"`, `aria-checked`, and explicit `aria-label`s to custom toggle switches in `resources/js/components/settings/SystemSettings.vue:45-56, 128-136`. `[ARCHIVED · Jules 13610617331227140394]`
- [x] **SET-04 (a11y):** Associate number inputs in `SystemSettings.vue:60-91` with `<label for="...">` and `<input id="...">` attributes. `[ARCHIVED · Jules 13610617331227140394]`
- [x] **SET-05 (a11y):** Add explicit `aria-label` or `<label>` tags to search and filter dropdowns in `AuditLogViewer.vue:6-34`. `[ARCHIVED · Jules 13610617331227140394]`
- [x] **SET-06 (a11y):** Upgrade Change Diff modal (`AuditLogViewer.vue:121-129`) with dialog semantics, Escape key listener, and accessible close button name. `[ARCHIVED · Jules 13610617331227140394]`

### 20. Attendance Reports & Analytics (`AttendanceReports.vue`)
- [x] **REP-04 (a11y & Forms):** Associate all filter labels (`Report Period`, `Date`, `Month`, `Year`, `Department`) with select/input elements using explicit `for` and `id` attributes in `resources/js/components/reports/AttendanceReports.vue:7-41`.
- [x] **REP-05 (CLS & States):** Replace plain text loading placeholder (`line 61`) with an 8-column animated skeleton table, and replace raw emoji `⚡` with an accessible SVG spinner during async report generation in `resources/js/components/reports/AttendanceReports.vue:45-66`.
- [x] **REP-06 (Interaction):** Add disabled and loading state feedback to the "Export CSV" button to prevent duplicate triggers during file generation in `resources/js/components/reports/AttendanceReports.vue:48-50`.

### 21. Daily Attendance Roster & Overrides (`DailyAttendanceRoster.vue`)
- [x] **ROST-01 (a11y & UX):** Replace native browser `window.confirm()` in `DailyAttendanceRoster.vue:184` with accessible confirmation modal dialog (`notify.confirm()`).
- [x] **ROST-02 (a11y):** Add explicit `aria-label`s to date input, department filter, status filter, search box, and refresh button in `resources/js/components/attendance/DailyAttendanceRoster.vue:6-42`.
- [x] **ROST-03 (a11y):** Add `scope="col"` to all table header `<th>` cells in `resources/js/components/attendance/DailyAttendanceRoster.vue:52-60`.
- [x] **ROST-04 (CLS & States):** Replace single-cell text loader (`line 64`) with 5 animated skeleton table rows matching table column dimensions in `resources/js/components/attendance/DailyAttendanceRoster.vue:63-65`.
- [x] **ROST-05 (a11y & Forms):** Upgrade Status Override Modal (`lines 121-149`) to a compliant dialog (`role="dialog"`, `aria-modal="true"`, `aria-labelledby`, Escape key handling, and `<label for="...">` mappings).

### 22. Employee Attendance Calendar (`EmployeeAttendanceCalendar.vue`)
- [x] **CAL-01 (a11y):** Convert modal wrapper (`lines 2-14`) into a semantic dialog (`role="dialog"`, `aria-modal="true"`, `aria-labelledby="calendar-modal-title"`, `@keydown.escape="close"`, and close button `aria-label="Close dialog"`).
- [x] **CAL-02 (a11y):** Add descriptive `aria-label="Previous month"` and `aria-label="Next month"` to calendar navigation buttons in `resources/js/components/attendance/EmployeeAttendanceCalendar.vue:18-20`.
- [x] **CAL-03 (a11y & CLS):** Implement accessible calendar grid announcements (`role="grid"`, descriptive `aria-label` with date and status for day cells) and skeleton loading state during async month queries in `resources/js/components/attendance/EmployeeAttendanceCalendar.vue:47-62`.

### 23. Workforce Directory & Shift Modals (`EmployeeDirectory.vue`)
- [x] **EMP-06 (a11y & UX):** Replace native `window.confirm()` on employee deletion in `EmployeeDirectory.vue:606` with accessible confirmation modal (`notify.confirm()`).
- [x] **EMP-07 (CLS & States):** Replace single spinning emoji `⏳` loader (`lines 166-169`) with mode-specific skeleton loaders (skeleton table for table mode, skeleton cards for grid mode) in `resources/js/components/employees/EmployeeDirectory.vue`.
- [x] **EMP-08 (a11y & Forms):** Upgrade Assign Shift Modal (`lines 434-490`) and CSV Bulk Import Modal (`lines 493-525`) to compliant dialogs with `role="dialog"`, `aria-modal="true"`, Escape listeners, and explicit label associations.

### 24. Attendance Dashboard & Sub-Hub Navigation (`AttendanceDashboard.vue` & Sub-Hubs)
- [x] **DASH-01 (Interaction & CLS):** Add skeleton pulse loader to KPI metric cards in `AttendanceDashboard.vue:4-35` during async summary query to eliminate layout shifts.
- [x] **DASH-02 (Visual Polish & a11y):** Add `motion-reduce:animate-none` override to the live attendance stream pulsating indicator in `AttendanceDashboard.vue:43` and alert ping in `App.vue:391`.
- [x] **HUB-01 (a11y & Responsive):** Implement ARIA tabs pattern (`role="tablist"`, `role="tab"`, `aria-selected`, `aria-controls`, `role="tabpanel"`) and responsive flex wrapping on sub-hub navigation bars across `AttendanceHub.vue`, `ScheduleHub.vue`, `VisitorHub.vue`, and `SettingsHub.vue`.
- [x] **LVE-06 (Interaction):** Add loading skeleton state to `LeaveCalendarView.vue:10` during `leaveStore.loading` to prevent premature "No approved leaves" flash.

---

## 📅 Phased Execution Milestones

| Phase | Focus Area | Deliverables | Target Timeline |
| :--- | :--- | :--- | :--- |
| **Phase 1** | **WCAG 2.1 AA Compliance & Non-Semantic Elements** | Fix non-semantic clickable `<div>` tags, missing `aria-label`s on icon-only buttons, modal focus traps, and Escape key dismissal across all views (Live Telemetry, Strangers, Alerts, Schedules). | Sprint 1 (Completed & Ongoing) |
| **Phase 2** | **Form Labeling & Validation Recovery** | Associate all `<label>` tags with input `id`s, add `aria-required`, inline validation states, custom toggle `role="switch"`, and wizard step constraints. | Sprint 1 |
| **Phase 3** | **Interactive States, Skeletons & CLS Prevention** | Replace plain text/single-spinner loaders with structured skeleton loaders across tables, grids, and live streams to eliminate Cumulative Layout Shift (CLS). | Sprint 2 |
| **Phase 4** | **Responsive Layouts & Touch Target Polishing** | Refactor dense button grids, mobile navigation overlays, and video preview controls to satisfy WCAG 2.5.5 / 2.5.8 touch target standards (>=44x44px). | Sprint 2 |
| **Phase 5** | **Native Dialogs & Confirmation Replacements** | Replace native `window.confirm()` calls with accessible custom confirmation dialogs for destructive or critical actions (Visitor Checkout, Attendance Finalization, Watchlist removal). | Sprint 2 |
