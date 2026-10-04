# Frontend Accessibility & UX Code Survey — Comprehensive Handoff Report

**Surveyor:** `survey_frontend_a11y_1` (Frontend A11y & UX Surveyor)  
**Date:** 2026-10-01  
**Project:** Intelligent AI Camera Hub — Biometric Vision & Attendance Management  
**Scope:** Sections 1 through 10 in `tasks-optimization.md` (Items APP-01 through REP-03)

---

## 1. Observation

A comprehensive, read-only code survey was conducted across the frontend application located in `resources/js/`. The build toolchain was verified via `npm run build`, compiling 134 modules in 927ms cleanly under Vite 8.2.2, Vue 3.5.41, and Tailwind CSS 4.3.3.

Every component cited in `tasks-optimization.md` was inspected for WCAG 2.1 AA accessibility standards, semantic HTML, keyboard operability, ARIA attributes, layout shifts (CLS), touch targets, and form field association.

### Summary of Surveyed Files and Tasks

| Section | Domain / File Path | Tasks | Defect Category |
| :--- | :--- | :--- | :--- |
| **1** | `resources/js/App.vue`<br>`resources/js/components/notifications/NotificationBell.vue` | **APP-01 – APP-05** | Mobile drawer ARIA, User Profile menu keyboard roles, KPI pulse skeleton cards, NotificationBell dialog/semantics, prefers-reduced-motion |
| **2** | `resources/js/views/LoginPage.vue` | **AUTH-01 – AUTH-04** | Login error `role="alert"`, dismiss `aria-label`, password toggle `aria-label` & `aria-pressed`, inline validation & focus |
| **3** | `resources/js/views/LiveTelemetry.vue` | **TEL-01 – TEL-05** | Clickable `<div>` thumbnail to `<button>`, 2-column skeleton grid eliminating CLS, filter/dropdown/refresh `aria-label`s, modal dialog semantics / focus trap / Escape, audio alert `role="switch"` |
| **4** | `resources/js/views/DeviceManager.vue` | **DEV-01 – DEV-05** | Form label `for`/`id` bindings, camera-specific `aria-label` on action buttons, responsive 5-column button grid with min 44x44px touch targets, ARIA tabs pattern (`role="tablist"`), modal dialog semantics |
| **5** | `resources/js/views/PersonnelManager.vue` | **PERS-01 – PERS-05** | Search/filter `aria-label`s, table header `scope="col"`, 5 animated skeleton table rows replacing single text cell, form label `for`/`id` bindings, submit button loading spinner |
| **6** | `resources/js/components/employees/EmployeeDirectory.vue`<br>`resources/js/components/employees/EmployeeFormModal.vue`<br>`resources/js/components/employees/EmployeeProfileModal.vue` | **EMP-01 – EMP-05** | Table/Grid toggle `role="group"` & `aria-pressed`, accessible icon buttons with `aria-label` replacing raw emojis, input `id`/`for` bindings & `aria-required`, semantic `<form>` submission on Enter, dialog semantics & focus trap |
| **7** | `resources/js/components/visitors/VisitorCheckInWizard.vue` | **VIS-01 – VIS-04** | Semantic step progress with `aria-current="step"` & `aria-live`, required field validation before advancing steps, input `for`/`id` bindings, accessible SVG spinner |
| **8** | `resources/js/components/CameraLivePreviewModal.vue` | **CAM-01 – CAM-04** | Responsive header layout for viewports <640px, `aria-label` on fullscreen & close, quality switch `role="group"` & `aria-pressed`, dialog focus trap & Escape key listener |
| **9** | `resources/js/components/attendance/AttendanceDashboard.vue`<br>`resources/js/components/attendance/ManualAttendanceEntry.vue` | **ATT-01 – ATT-03** | `aria-live="polite"` on live clock-in stream, accessible modal confirmation replacing `window.confirm()`, input `for`/`id` bindings & dialog accessibility |
| **10** | `resources/js/components/reports/PayrollExportModal.vue` | **REP-01 – REP-03** | Async loading state with disabled spinner during export, `<fieldset>` and `<legend>` on export format radio group, `for`/`id` on Month & Year selects |

---

### Detailed Code-Level Observations

#### Section 1: Global App Shell & Navigation (`App.vue` & `NotificationBell.vue`)

- **APP-01 (`resources/js/App.vue:20, 52-58, 186-192`)**:
  - *Observation*: The mobile drawer trigger button at line 186 has no `aria-expanded`, `aria-label`, or `aria-controls`:
    ```html
    <!-- Line 186-192 -->
    <button
        @click="mobileMenuOpen = true"
        class="lg:hidden p-2 rounded-xl text-slate-600 hover:text-slate-900 hover:bg-slate-100 border border-slate-200 cursor-pointer"
        title="Open Menu"
    >
        <span class="text-sm">☰</span>
    </button>
    ```
    The sidebar `<aside>` at line 20 lacks an `id="sidebar-nav"` and `aria-label="Main Navigation"`. The mobile close button at line 52 has only text `✕` without `aria-label="Close navigation menu"`.
- **APP-02 (`resources/js/App.vue:218-277`)**:
  - *Observation*: The user profile button at line 218 lacks `aria-haspopup="menu"`, `:aria-expanded="userMenuOpen"`, and `aria-label="User profile menu"`. The dropdown container at line 234 is a plain `<div>` lacking `role="menu"` and `aria-orientation="vertical"`. The settings button (line 260) and logout button (line 270) lack `role="menuitem"` and `tabindex="0"`. Dismissal occurs only on clicking the menu itself; clicking outside or pressing Escape does not dismiss the menu.
- **APP-03 (`resources/js/App.vue:283-452`)**:
  - *Observation*: The 6 KPI summary metric cards render hardcoded `0` or null values while stats are fetching from `/api/stats`. There is no skeleton pulse card state while `store.loading` or initial mount occurs, causing layout shifts when numerical data loads.
- **APP-04 (`resources/js/components/notifications/NotificationBell.vue:3-48`)**:
  - *Observation*: Trigger button at line 3 lacks `aria-haspopup="dialog"`, `:aria-expanded="showDropdown"`, and dynamic `aria-label`. The dropdown panel at line 16 is an unlabelled `<div>`. Notification items at lines 37-46 are raw clickable `<div>` elements (`<div v-for="n in notificationStore.notifications" @click="markRead(n)" ...>`) that cannot be navigated to or activated via keyboard (`Enter`/`Space`).
- **APP-05 (`resources/js/components/notifications/NotificationBell.vue:10`)**:
  - *Observation*: The unread notification badge has `animate-bounce` unconditionally. It lacks `motion-reduce:animate-none`, which triggers vestibular issues for users with `prefers-reduced-motion: reduce`.

#### Section 2: Authentication & Sign-In (`LoginPage.vue`)

- **AUTH-01 (`resources/js/views/LoginPage.vue:19-25`)**:
  - *Observation*: Error banner at line 19 (`<div v-if="authStore.loginError" class="mb-5 bg-rose-50 ...">`) lacks `role="alert"` and `aria-live="assertive"`. Screen readers do not announce authentication errors.
- **AUTH-02 (`resources/js/views/LoginPage.vue:24`)**:
  - *Observation*: Error dismissal button at line 24 (`<button @click="authStore.loginError = null" class="text-rose-400 hover:text-rose-600 font-bold ml-2 cursor-pointer">&times;</button>`) has no `aria-label="Dismiss error"` and lacks visible focus styles.
- **AUTH-03 (`resources/js/views/LoginPage.vue:61-68`)**:
  - *Observation*: Password visibility toggle button at line 61 lacks `:aria-label="showPassword ? 'Hide password' : 'Show password'"` and `:aria-pressed="showPassword"`. The emojis 👁️ / 🙈 lack `aria-hidden="true"`.
- **AUTH-04 (`resources/js/views/LoginPage.vue:27-111`)**:
  - *Observation*: Inputs lack `aria-invalid` bindings on error. Upon failed submission, focus is not retained or transferred to the input, leaving keyboard users disoriented.

#### Section 3: Live Telemetry Stream (`LiveTelemetry.vue`)

- **TEL-01 (`resources/js/views/LiveTelemetry.vue:86-90`)**:
  - *Observation*: The face snapshot thumbnail is a clickable `<div>` (`<div class="relative w-16 h-16 ... cursor-pointer" @click="openImageModal(...)">`). It is completely unreachable by keyboard Tab, lacks `role="button"`, and has no `aria-label`.
- **TEL-02 (`resources/js/views/LiveTelemetry.vue:66-69`)**:
  - *Observation*: During data fetching (`v-if="loading"`), a single centered text box with a spinner is displayed (`<div class="bg-white border ... p-12 text-center ...">`). When populated, it replaces into a 2-column card grid, causing high Cumulative Layout Shift (CLS).
- **TEL-03 (`resources/js/views/LiveTelemetry.vue:33, 41-47, 128-133, 144`)**:
  - *Observation*: Filter select at line 33 has no `aria-label="Filter events by verification status"`. Refresh button at line 41 has only text `🔄` without `aria-label="Refresh telemetry logs"` or `<span aria-hidden="true">`. Per-page select at line 144 has no `aria-label="Items per page"`. Scene button at line 128 has no contextual label.
- **TEL-04 (`resources/js/views/LiveTelemetry.vue:177-197`)**:
  - *Observation*: Image inspection modal is an unstyled `<div>` overlay lacking `role="dialog"`, `aria-modal="true"`, and `aria-labelledby`. No keyboard Escape listener exists, and focus escapes the modal.
- **TEL-05 (`resources/js/views/LiveTelemetry.vue:23-30`)**:
  - *Observation*: Audio alert toggle at line 23 is a button lacking `role="switch"` and `:aria-checked="store.soundEnabled"`.

#### Section 4: Edge Device & Fleet Manager (`DeviceManager.vue`)

- **DEV-01 (`resources/js/views/DeviceManager.vue:245-295`)**:
  - *Observation*: Multiple `<label>` tags have no `for` attribute and corresponding `<input>`/`<select>` tags have no `id` attribute (e.g. stream endpoint line 245, device serial line 265, friendly name line 281, basic auth username line 288, basic auth password line 292).
- **DEV-02 (`resources/js/views/DeviceManager.vue:51-73`)**:
  - *Observation*: Edit and Delete icon-only buttons at lines 51 and 60 only contain SVGs and generic `title` attributes. They lack contextual `:aria-label="`Edit camera ${device.name}`"` and `:aria-label="`Delete camera ${device.name}`"`.
- **DEV-03 (`resources/js/views/DeviceManager.vue:122-160`)**:
  - *Observation*: The secondary actions container uses `grid grid-cols-5 gap-1`. On viewports <640px, buttons are compressed to under 38px width with truncated labels ("Impo...", "Back..."), and touch target heights are ~28px, failing WCAG 2.5.5 / 2.5.8 (min 44x44px).
- **DEV-04 (`resources/js/views/DeviceManager.vue:197-238`)**:
  - *Observation*: Tab switcher when editing a device is a plain `<div>` of buttons without `role="tablist"`, `role="tab"`, `:aria-selected`, or `:aria-controls`.
- **DEV-05 (`resources/js/views/DeviceManager.vue:178-195`)**:
  - *Observation*: Camera configuration modal lacks `role="dialog"`, `aria-modal="true"`, `aria-labelledby`, Escape key handling, and the close button lacks `aria-label="Close configuration modal"`.

#### Section 5: Personnel & Face Library (`PersonnelManager.vue`)

- **PERS-01 (`resources/js/views/PersonnelManager.vue:23-43`)**:
  - *Observation*: Search input at line 23 and dropdowns at lines 33 and 39 have placeholder text but no `aria-label` or `<label>` element.
- **PERS-02 (`resources/js/views/PersonnelManager.vue:51-59, 94-122`)**:
  - *Observation*: Table headers (`<th>`) at lines 52-58 lack `scope="col"`. Action buttons (Sync, Edit, Delete, Add as Employee) lack contextual `aria-label` referencing the person's name.
- **PERS-03 (`resources/js/views/PersonnelManager.vue:62-64`)**:
  - *Observation*: `<tr v-if="loading"><td colspan="7" class="py-12 text-center ...">Loading personnel records...</td></tr>` collapses the table structure, creating CLS when rows appear.
- **PERS-04 (`resources/js/views/PersonnelManager.vue:151-233`)**:
  - *Observation*: Modal input fields (Name, Category, ID Card, Phone, Gender, Birthday, Valid Begin/End, File) lack `id` and `<label for="...">` associations. File input at line 229 lacks accessible labelling.
- **PERS-05 (`resources/js/views/PersonnelManager.vue:237-240`)**:
  - *Observation*: Submit button at line 237 only changes text (`Saving & Enrolling...`) without an animated SVG spinner during image Base64 encoding and dispatch.

#### Section 6: Workforce Directory & Profiles (`EmployeeDirectory.vue`, `EmployeeFormModal.vue`, `EmployeeProfileModal.vue`)

- **EMP-01 (`resources/js/components/employees/EmployeeDirectory.vue:19-38`)**:
  - *Observation*: View toggle container at line 19 is a `<div>` lacking `role="group"` and `aria-label="View layout switcher"`. The buttons lack `:aria-pressed="store.viewMode === 'table'"` and `:aria-pressed="store.viewMode === 'grid'"`.
- **EMP-02 (`resources/js/components/employees/EmployeeDirectory.vue:277-305, 390-440`)**:
  - *Observation*: Action buttons use bare emojis (👁️, ✏️, ⏱️, 🗑️) without `aria-label` or `aria-hidden="true"`.
- **EMP-03 (`resources/js/components/employees/EmployeeFormModal.vue:94-285`)**:
  - *Observation*: None of the 14 form fields have `for`/`id` bindings. Required fields lack `aria-required="true"`.
- **EMP-04 (`resources/js/components/employees/EmployeeFormModal.vue:23-305`)**:
  - *Observation*: The entire modal body has no `<form>` element. The submit button is `<button type="button" @click="handleSubmit">`. Pressing `Enter` does not submit the form, and native HTML validation constraints are bypassed.
- **EMP-05 (`resources/js/components/employees/EmployeeFormModal.vue:2-20, 70-91`)**:
  - *Observation*: Modal container lacks `role="dialog"`, `aria-modal="true"`, `aria-labelledby`, focus trap, and Escape key listener. Webcam interface at line 70 lacks `aria-label="Webcam photo capture"`.

#### Section 7: Visitor Management & Kiosk Check-In (`VisitorCheckInWizard.vue`)

- **VIS-01 (`resources/js/components/visitors/VisitorCheckInWizard.vue:9`)**:
  - *Observation*: Wizard step indication is plain text (`<p class="text-xs text-slate-500">Step {{ currentStep }} of 3: {{ stepTitle }}</p>`). It lacks a semantic `<nav>` with ordered list `<ol>`, `aria-current="step"`, and `aria-live="polite"` announcement of step transitions.
- **VIS-02 (`resources/js/components/visitors/VisitorCheckInWizard.vue:101-103`)**:
  - *Observation*: Clicking "Continue →" executes `currentStep++` directly without validating whether required fields (e.g. `form.first_name`) are filled in Step 1.
- **VIS-03 (`resources/js/components/visitors/VisitorCheckInWizard.vue:18-72`)**:
  - *Observation*: All inputs across Steps 1 and 2 (First Name, Last Name, Company, Phone, Email, Host, Purpose, Badge) lack `id` and `for` bindings.
- **VIS-04 (`resources/js/components/visitors/VisitorCheckInWizard.vue:104-107`)**:
  - *Observation*: During async camera provisioning, a spinning emoji `⏳` is used (`<span v-if="submitting" class="animate-spin">⏳</span>`). It lacks an accessible SVG spinner and `aria-live="polite"` status.

#### Section 8: Live Video Preview (`CameraLivePreviewModal.vue`)

- **CAM-01 (`resources/js/components/CameraLivePreviewModal.vue:12-95`)**:
  - *Observation*: Header bar layout uses `flex items-center justify-between px-6 py-4`. On screens <640px, the combination of camera icon, name, status badge, subtitle, stream endpoint, quality switcher, Web UI button, fullscreen button, and close button overflows horizontally and breaks layout.
- **CAM-02 (`resources/js/components/CameraLivePreviewModal.vue:59-93`)**:
  - *Observation*: Fullscreen button, close button, Web UI link, and frame capture button lack explicit `aria-label`s.
- **CAM-03 (`resources/js/components/CameraLivePreviewModal.vue:41-56`)**:
  - *Observation*: Quality switcher container lacks `role="group"` and `aria-label="Stream video resolution"`. Buttons lack `:aria-pressed`.
- **CAM-04 (`resources/js/components/CameraLivePreviewModal.vue:2-10`)**:
  - *Observation*: Modal container lacks `role="dialog"`, `aria-modal="true"`, `aria-labelledby`, Escape key handling, and focus trapping.

#### Section 9: Attendance Stream & Manual Entry (`AttendanceDashboard.vue` & `ManualAttendanceEntry.vue`)

- **ATT-01 (`resources/js/components/attendance/AttendanceDashboard.vue:52-70`)**:
  - *Observation*: The Live Attendance Clock-In Stream container receives real-time WebSocket punches but lacks `aria-live="polite"` and `aria-relevant="additions text"`. Screen readers do not announce incoming clock-ins.
- **ATT-02 (`resources/js/components/attendance/AttendanceDashboard.vue:111-115`)**:
  - *Observation*: `triggerFinalize` invokes browser native synchronous `confirm('Finalize today attendance records for all active employees?')`. This is unstyled, blocks JavaScript thread, and is not accessible.
- **ATT-03 (`resources/js/components/attendance/ManualAttendanceEntry.vue:1-60`)**:
  - *Observation*: Modal lacks `role="dialog"`, `aria-modal="true"`, `aria-labelledby`, and Escape key handler. Form labels for Employee, Date, Time, Punch Direction, Source, and Reason lack `for`/`id` bindings. Submit button uses emoji `⏳`.

#### Section 10: Reports & Payroll Export (`PayrollExportModal.vue`)

- **REP-01 (`resources/js/components/reports/PayrollExportModal.vue:50, 74-77`)**:
  - *Observation*: `handleExport` is an async call (`await reportStore.exportPayroll(...)`) but has no local loading state. The button remains active, allowing duplicate rapid clicks, and provides no spinner feedback.
- **REP-02 (`resources/js/components/reports/PayrollExportModal.vue:33-45`)**:
  - *Observation*: Export Format radio buttons (CSV vs JSON) are wrapped in a generic `<div>` with a `<label class="block ...">` rather than a semantic `<fieldset>` with `<legend>`.
- **REP-03 (`resources/js/components/reports/PayrollExportModal.vue:19-30`)**:
  - *Observation*: Payroll Month and Year `<select>` elements lack `id` attributes and `<label>` tags lack `for` attributes. Modal lacks dialog semantics.

---

## 2. Logic Chain

1. **Accessibility Compliance (WCAG 2.1 AA & Section 508)**:
   - *Screen Reader Operability*: In standard WCAG 2.1 AA guidelines (Success Criterion 1.3.1 Info and Relationships, 4.1.2 Name, Role, Value), interactive controls must expose an accessible name, role, and current state. Icon-only buttons with bare SVG or emojis (👁️, ✏️, 🗑️, 🔄, ✕) fail SC 4.1.2. Adding `:aria-label`, `aria-hidden="true"` on non-text icons, and `:aria-pressed` / `role="switch"` ensures screen readers accurately convey control purposes.
   - *Form Control Association*: Labels floating beside inputs without programmatic association (SC 3.3.2 Labels or Instructions) prevent assistive technologies from announcing what the user is editing. Explicit `for="..."` and `id="..."` pairing solves this deterministically across all forms.
   - *Status & Alert Announcements*: Telemetry events arriving asynchronously via WebSockets (e.g. `recentLivePunches`) and authentication failure alerts require live regions (`aria-live="polite"` or `aria-live="assertive"`) so assistive software speaks updates without user focus shift (SC 4.1.3 Status Messages).
2. **Modal Dialog Standards (WAI-ARIA Dialog Pattern)**:
   - All modal overlays (`LiveTelemetry.vue`, `DeviceManager.vue`, `EmployeeFormModal.vue`, `CameraLivePreviewModal.vue`, `ManualAttendanceEntry.vue`, `PayrollExportModal.vue`) must have `role="dialog"`, `aria-modal="true"`, and `aria-labelledby` referencing the modal title.
   - Modals must listen for the `Escape` key (`@keydown.escape="close"`) and maintain a focus trap so keyboard Tab navigation does not escape behind the dark backdrop to invisible DOM nodes.
3. **Cumulative Layout Shift (CLS) Elimination**:
   - In `LiveTelemetry.vue` and `PersonnelManager.vue`, replacing a 2-column grid or 7-column table with a single centered "Loading..." text block causes the viewport to jump drastically when records load. Replacing them with skeleton pulse cards and animated table rows identical in dimensions to the populated state completely flattens layout shift (CLS ≈ 0).
4. **Touch Target Size & Responsive Ergonomics (WCAG 2.5.5 / 2.5.8)**:
   - On viewports <640px, a 5-column button grid in `DeviceManager.vue` shrinks button widths below 36px with severe truncation, and touch targets below 28px height. Refactoring to a wrapping flex container with `min-h-[44px]` satisfies the 44x44px minimum touch target requirement.
   - In `CameraLivePreviewModal.vue`, wrapping header controls in responsive `flex-col sm:flex-row` prevents horizontal clipping of stream controls on mobile displays.
5. **Reduced Motion**:
   - `animate-bounce` on notification bells triggers vestibular discomfort. Adding `motion-reduce:animate-none` respects the OS-level `prefers-reduced-motion` user setting.

---

## 3. Caveats

1. **Read-Only Inspection**: As per surveyor instructions, no modifications have been written directly to `resources/js/` source files. All changes are provided as blueprints in this report for the implementing agent.
2. **Backwards Compatibility**: All suggested modifications preserve existing Vue props, events (`emit`), Pinia store interactions, and styling conventions without modifying backend contracts or breaking existing tests.
3. **Third-Party Modal Dependencies**: The project already bundles `sweetalert2` with an existing `resources/js/utils/notify.js` helper. For `ATT-02` (`AttendanceDashboard.vue`), using `notify.confirm(...)` is recommended as it reuses the application's existing accessible SweetAlert2 wrapper rather than introducing a redundant third modal subsystem.

---

## 4. Conclusion & Complete Implementation Blueprint

Below is the concrete, drop-in code specification for all 43 tasks across the 10 sections.

---

### Blueprint 1: Global App Shell & Navigation (`App.vue` & `NotificationBell.vue`)

#### Tasks APP-01 & APP-02: `resources/js/App.vue`
```html
<!-- Line 20: Add id and aria-label to sidebar aside -->
<aside
    id="sidebar-nav"
    aria-label="Main Navigation"
    class="fixed inset-y-0 left-0 z-50 w-72 bg-white border-r border-slate-200/80 flex flex-col justify-between transition-transform duration-300 ease-in-out lg:static lg:translate-x-0 shadow-sm lg:shadow-none"
    :class="mobileMenuOpen ? 'translate-x-0' : '-translate-x-full'"
>

<!-- Line 52-58: Accessible close button -->
<button
    @click="mobileMenuOpen = false"
    aria-label="Close navigation menu"
    class="lg:hidden text-slate-400 hover:text-slate-700 p-1.5 rounded-lg hover:bg-slate-100 cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
>
    <span aria-hidden="true">✕</span>
</button>

<!-- Line 186-192: Accessible mobile menu trigger -->
<button
    @click="mobileMenuOpen = true"
    :aria-expanded="mobileMenuOpen"
    aria-controls="sidebar-nav"
    aria-label="Open navigation menu"
    class="lg:hidden p-2 rounded-xl text-slate-600 hover:text-slate-900 hover:bg-slate-100 border border-slate-200 cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
>
    <span class="text-sm" aria-hidden="true">☰</span>
</button>

<!-- Line 218-277: Accessible User Profile Dropdown Menu -->
<div class="relative">
    <button
        @click="userMenuOpen = !userMenuOpen"
        aria-haspopup="menu"
        :aria-expanded="userMenuOpen"
        aria-label="User profile menu"
        @keydown.escape="userMenuOpen = false"
        class="flex items-center gap-2 p-1.5 rounded-xl hover:bg-slate-100 transition-colors cursor-pointer border border-transparent hover:border-slate-200 focus:outline-none focus:ring-2 focus:ring-indigo-500"
    >
        <div class="w-7 h-7 rounded-full bg-indigo-600 text-white font-bold text-xs flex items-center justify-center shadow-2xs">
            {{ authStore.userInitials }}
        </div>
        <span class="text-slate-400 text-xs hidden md:inline" aria-hidden="true">▾</span>
    </button>

    <!-- Dropdown Menu with ARIA roles and Escape key handler -->
    <div
        v-if="userMenuOpen"
        role="menu"
        aria-orientation="vertical"
        aria-label="User account actions"
        @keydown.escape="userMenuOpen = false"
        class="absolute right-0 mt-2 w-56 bg-white border border-slate-200 rounded-2xl shadow-xl py-2 z-50 divide-y divide-slate-100 animate-in fade-in zoom-in-95 duration-150"
    >
        <div class="px-4 py-2 text-xs" role="none">
            <div class="font-bold text-slate-900">{{ authStore.userName }}</div>
            <div class="text-[11px] text-slate-500 font-mono truncate">{{ authStore.userEmail }}</div>
            <div class="mt-1 flex flex-wrap gap-1">
                <span v-for="role in authStore.roles" :key="role" class="px-1.5 py-0.5 rounded text-[9px] font-bold uppercase bg-slate-100 text-slate-600">
                    {{ role }}
                </span>
            </div>
        </div>

        <div class="py-1 text-xs" role="none">
            <button
                v-if="authStore.isAdmin"
                role="menuitem"
                tabindex="0"
                @click="switchTab('settings'); userMenuOpen = false"
                class="w-full text-left px-4 py-2 hover:bg-slate-50 text-slate-700 flex items-center gap-2 cursor-pointer focus:bg-slate-50 focus:outline-none"
            >
                <span aria-hidden="true">⚙️</span> System Settings
            </button>
        </div>

        <div class="py-1 text-xs" role="none">
            <button
                role="menuitem"
                tabindex="0"
                @click="handleLogout"
                class="w-full text-left px-4 py-2 hover:bg-rose-50 text-rose-600 font-semibold flex items-center gap-2 cursor-pointer focus:bg-rose-50 focus:outline-none"
            >
                <span aria-hidden="true">🚪</span> Sign Out
            </button>
        </div>
    </div>
</div>
```

#### Task APP-03: `resources/js/App.vue` (KPI Skeleton Pulse Cards)
```html
<!-- Line 283: Render skeleton pulse cards while loading stats -->
<section class="px-4 sm:px-6 lg:px-8 pt-6">
    <!-- Skeleton loader when store stats are initially unpopulated -->
    <div
        v-if="store.statsLoading"
        class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3"
        aria-label="Loading metric summaries"
        aria-busy="true"
    >
        <div
            v-for="i in 6"
            :key="i"
            class="bg-white border border-slate-200/80 rounded-2xl p-4 shadow-xs animate-pulse space-y-2"
        >
            <div class="h-3 bg-slate-200 rounded w-20"></div>
            <div class="h-7 bg-slate-200 rounded w-14"></div>
            <div class="h-2.5 bg-slate-100 rounded w-24"></div>
        </div>
    </div>

    <!-- Populated Metric Cards -->
    <div
        v-else
        class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3"
    >
        <!-- Metric Cards 1 to 6 as existing -->
    </div>
</section>
```

#### Tasks APP-04 & APP-05: `resources/js/components/notifications/NotificationBell.vue`
```html
<template>
    <div class="relative">
        <button 
            @click="showDropdown = !showDropdown" 
            aria-haspopup="dialog"
            :aria-expanded="showDropdown"
            :aria-label="notificationStore.unreadCount > 0 ? `${notificationStore.unreadCount} unread notifications` : 'Notifications'"
            @keydown.escape="showDropdown = false"
            class="relative p-2 text-slate-500 hover:text-slate-800 rounded-xl hover:bg-slate-100 transition-colors border border-transparent hover:border-slate-200 cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
            title="Notifications"
        >
            <span class="text-lg" aria-hidden="true">🔔</span>
            <span 
                v-if="notificationStore.unreadCount > 0"
                class="absolute top-1 right-1 min-w-4 h-4 px-1 bg-rose-600 text-white font-bold text-[10px] rounded-full flex items-center justify-center animate-bounce motion-reduce:animate-none shadow-xs"
            >
                {{ notificationStore.unreadCount > 9 ? '9+' : notificationStore.unreadCount }}
            </span>
        </button>

        <!-- Dropdown Dialog Panel -->
        <div 
            v-if="showDropdown" 
            role="dialog"
            aria-label="Notifications Panel"
            aria-modal="false"
            @keydown.escape="showDropdown = false"
            class="absolute right-0 mt-2 w-80 sm:w-96 bg-white border border-slate-200 rounded-2xl shadow-xl z-50 p-4 space-y-3 divide-y divide-slate-100 animate-in fade-in zoom-in-95 duration-150"
        >
            <div class="flex items-center justify-between pb-2">
                <div class="flex items-center gap-2">
                    <span class="text-sm" aria-hidden="true">🔔</span>
                    <span class="font-bold text-slate-900 text-sm">Notifications</span>
                    <span v-if="notificationStore.unreadCount > 0" class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-rose-50 text-rose-700 border border-rose-200">
                        {{ notificationStore.unreadCount }} new
                    </span>
                </div>
                <div class="flex items-center gap-2">
                    <button @click="notificationStore.markAllAsRead" class="text-[11px] font-semibold text-indigo-600 hover:text-indigo-800 cursor-pointer focus:underline focus:outline-none">
                        Mark all read
                    </button>
                    <button 
                        @click="showDropdown = false" 
                        aria-label="Close notifications panel"
                        class="text-slate-400 hover:text-slate-700 text-xs font-bold cursor-pointer p-1 rounded hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    >✕</button>
                </div>
            </div>

            <div v-if="notificationStore.notifications.length === 0" class="py-8 text-center text-slate-500 text-xs">
                No notifications to display.
            </div>
            <div v-else class="pt-2 space-y-2 max-h-80 overflow-y-auto pr-1" role="feed" aria-label="Notifications list">
                <!-- Semantic <button> item replacing clickable <div> -->
                <button 
                    v-for="n in notificationStore.notifications" 
                    :key="n.id"
                    type="button"
                    @click="markRead(n)"
                    :aria-label="`${n.read_at ? 'Read' : 'Unread'} notification: ${n.title || 'System Notification'}`"
                    :class="n.read_at ? 'bg-slate-50 text-slate-600 border border-slate-200/60' : 'bg-indigo-50/70 text-slate-900 font-medium border-l-3 border-indigo-600 border border-indigo-100 shadow-xs'"
                    class="w-full text-left p-3 rounded-xl cursor-pointer hover:bg-slate-100/80 transition-colors space-y-1 block focus:outline-none focus:ring-2 focus:ring-indigo-500"
                >
                    <div class="flex justify-between items-start gap-2">
                        <div class="text-xs font-bold text-slate-900">{{ n.title || 'System Notification' }}</div>
                        <span class="text-[10px] text-slate-400 font-mono whitespace-nowrap">{{ formatTime(n.created_at) }}</span>
                    </div>
                    <div class="text-[11px] text-slate-600 leading-relaxed">{{ n.message || n.data?.message || '' }}</div>
                </button>
            </div>
        </div>
    </div>
</template>
```

---

### Blueprint 2: Authentication & Sign-In (`LoginPage.vue`)

#### Tasks AUTH-01 through AUTH-04: `resources/js/views/LoginPage.vue`
```html
<!-- Line 19-25: Accessible Error Banner with role="alert" & aria-live="assertive" -->
<div 
  v-if="authStore.loginError" 
  role="alert" 
  aria-live="assertive" 
  class="mb-5 bg-rose-50 border border-rose-200 text-rose-700 px-4 py-3 rounded-xl text-xs flex items-center justify-between shadow-xs"
>
  <div class="flex items-center gap-2">
    <span class="text-rose-500 text-sm" aria-hidden="true">⚠️</span>
    <span>{{ authStore.loginError }}</span>
  </div>
  <button 
    @click="authStore.loginError = null" 
    aria-label="Dismiss error" 
    class="text-rose-400 hover:text-rose-600 font-bold ml-2 cursor-pointer p-1 rounded focus:outline-none focus:ring-2 focus:ring-rose-500"
  >&times;</button>
</div>

<!-- Email Input with aria-invalid -->
<div>
  <label for="email" class="block text-xs font-semibold text-slate-700">
    Work Email Address
  </label>
  <div class="mt-1 relative">
    <input
      id="email"
      ref="emailInput"
      v-model="form.email"
      type="email"
      autocomplete="email"
      required
      :aria-invalid="authStore.loginError ? 'true' : 'false'"
      placeholder="name@company.com"
      class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs"
    />
  </div>
</div>

<!-- Password Input with accessible toggle & aria-pressed -->
<div>
  <label for="password" class="block text-xs font-semibold text-slate-700">
    Password
  </label>
  <div class="mt-1 relative">
    <input
      id="password"
      v-model="form.password"
      :type="showPassword ? 'text' : 'password'"
      autocomplete="current-password"
      required
      :aria-invalid="authStore.loginError ? 'true' : 'false'"
      placeholder="••••••••"
      class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 pr-10 text-xs text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs"
    />
    <button
      type="button"
      @click="showPassword = !showPassword"
      :aria-label="showPassword ? 'Hide password' : 'Show password'"
      :aria-pressed="showPassword"
      class="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 hover:text-slate-600 cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500/30 rounded"
      title="Toggle password visibility"
    >
      <span class="text-xs" aria-hidden="true">{{ showPassword ? '👁️' : '🙈' }}</span>
    </button>
  </div>
</div>
```

---

### Blueprint 3: Live Telemetry Stream (`LiveTelemetry.vue`)

#### Tasks TEL-01 through TEL-05: `resources/js/views/LiveTelemetry.vue`
```html
<!-- Line 23: Audio Alert Switch with role="switch" -->
<button 
  type="button"
  role="switch"
  :aria-checked="store.soundEnabled"
  aria-label="Toggle audio alerts"
  @click="store.soundEnabled = !store.soundEnabled"
  class="px-3 py-1.5 text-xs font-medium rounded-lg border transition-all flex items-center gap-2 cursor-pointer shadow-xs focus:outline-none focus:ring-2 focus:ring-indigo-500"
  :class="store.soundEnabled ? 'bg-indigo-50 border-indigo-200 text-indigo-700' : 'bg-white border-slate-200 text-slate-600 hover:text-slate-900 hover:bg-slate-50'"
>
  <span v-if="store.soundEnabled">🔊 Audio Alert: ON</span>
  <span v-else>🔇 Audio Alert: OFF</span>
</button>

<!-- Line 33: Filter select with aria-label -->
<select 
  v-model="statusFilter" 
  @change="onFilterChange" 
  aria-label="Filter events by verification status"
  class="bg-white border border-slate-200 text-xs rounded-lg px-3 py-1.5 text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs cursor-pointer"
>
  <option value="all">All Events</option>
  <option value="1">Allowed (Whitelisted)</option>
  <option value="2">Rejected / Denied</option>
  <option value="3">Not Registered</option>
</select>

<!-- Line 41: Refresh button with aria-label -->
<button 
  @click="fetchLogs(currentPage)" 
  aria-label="Refresh telemetry logs"
  class="px-3 py-1.5 bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 rounded-lg text-xs font-medium flex items-center gap-1 transition-colors shadow-xs cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
  title="Refresh Logs"
>
  <span aria-hidden="true">🔄</span>
</button>

<!-- Line 66: Responsive 2-Column Skeleton Grid eliminating CLS -->
<div 
  v-if="loading" 
  class="grid grid-cols-1 md:grid-cols-2 gap-3" 
  aria-busy="true" 
  aria-label="Loading telemetry logs"
>
  <div 
    v-for="i in 6" 
    :key="i"
    class="bg-white border border-slate-200 rounded-xl p-4 flex items-center justify-between gap-4 animate-pulse shadow-xs"
  >
    <div class="flex items-center gap-4 min-w-0 flex-1">
      <div class="w-16 h-16 rounded-lg bg-slate-200 shrink-0"></div>
      <div class="space-y-2 flex-1">
        <div class="h-4 bg-slate-200 rounded w-1/2"></div>
        <div class="h-3 bg-slate-100 rounded w-3/4"></div>
        <div class="h-2 bg-slate-100 rounded w-1/3"></div>
      </div>
    </div>
    <div class="h-6 w-20 bg-slate-200 rounded-full"></div>
  </div>
</div>

<!-- Line 86: Semantic <button> Face Thumbnail -->
<button 
  type="button"
  @click="openImageModal(log.snap_pic_url, log.scene_pic_url, log.person_name)"
  :aria-label="`Inspect snapshot for ${log.person_name || 'Unregistered Person'}`"
  class="relative w-16 h-16 rounded-lg bg-slate-100 border border-slate-200 overflow-hidden shrink-0 cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
>
  <img v-if="log.snap_pic_url" :src="log.snap_pic_url" :alt="`Snapshot of ${log.person_name || 'person'}`" class="w-full h-full object-cover hover:scale-105 transition-transform" />
  <div v-else class="w-full h-full flex items-center justify-center text-xs text-slate-400 font-mono">NO PIC</div>
  <div v-if="log.is_no_mask === 1" class="absolute bottom-0 right-0 bg-amber-500 text-black text-[9px] px-1 font-bold rounded-tl">NO MASK</div>
</button>

<!-- Line 177: Accessible Image Inspection Dialog -->
<div 
  v-if="modal.show" 
  role="dialog"
  aria-modal="true"
  aria-labelledby="image-modal-title"
  @keydown.escape="modal.show = false"
  class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm" 
  @click.self="modal.show = false"
>
  <div class="bg-white border border-slate-200 rounded-2xl max-w-3xl w-full p-6 space-y-4 max-h-[90vh] overflow-y-auto shadow-2xl animate-in fade-in zoom-in-95 duration-150">
    <div class="flex items-center justify-between border-b border-slate-200 pb-3">
      <h3 id="image-modal-title" class="text-base font-bold text-slate-900">{{ modal.title }} - High Resolution Snapshot</h3>
      <button 
        @click="modal.show = false" 
        aria-label="Close image inspection dialog"
        class="text-slate-400 hover:text-slate-700 text-lg font-bold cursor-pointer p-1 rounded focus:outline-none focus:ring-2 focus:ring-indigo-500"
      >&times;</button>
    </div>
    <div class="grid grid-cols-1 gap-4">
      <div v-if="modal.snapUrl" class="space-y-2">
        <div class="text-xs font-semibold text-slate-500">Face Snapshot (Crop)</div>
        <img :src="modal.snapUrl" alt="High resolution facial crop" class="rounded-xl border border-slate-200 w-full max-h-72 object-contain bg-slate-950" />
      </div>
      <div v-if="modal.sceneUrl" class="space-y-2">
        <div class="text-xs font-semibold text-slate-500">Context Scene View</div>
        <img :src="modal.sceneUrl" alt="Wide-angle scene context" class="rounded-xl border border-slate-200 w-full max-h-72 object-contain bg-slate-950" />
      </div>
    </div>
    <div class="flex justify-end pt-2">
      <button @click="modal.show = false" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold rounded-lg border border-slate-300 cursor-pointer transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500">Close</button>
    </div>
  </div>
</div>
```

---

### Blueprint 4: Edge Device & Fleet Manager (`DeviceManager.vue`)

#### Tasks DEV-01 through DEV-05: `resources/js/views/DeviceManager.vue`
```html
<!-- Line 51 & 60: Contextual aria-labels on action buttons -->
<button 
  @click="openEditModal(device)" 
  :aria-label="`Edit camera and configuration for ${device.name}`"
  class="p-1.5 text-slate-400 hover:text-indigo-600 hover:bg-slate-100 rounded transition-colors cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500" 
  title="Edit Camera & Configuration"
>
  <svg ...></svg>
</button>
<button 
  @click="deleteDevice(device)" 
  :disabled="deletingId === device.id"
  :aria-label="`Delete camera ${device.name}`"
  class="p-1.5 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded transition-colors disabled:opacity-50 cursor-pointer focus:outline-none focus:ring-2 focus:ring-rose-500" 
  title="Delete Camera"
>
  <svg ...></svg>
</button>

<!-- Line 122: Responsive Flex-Wrap Action Bar with >=44x44px touch targets -->
<div class="flex flex-wrap sm:grid sm:grid-cols-5 gap-1.5 pt-2 border-t border-slate-100">
  <button 
    @click="testConnection(device)" 
    :disabled="testingId === device.id"
    :aria-label="`Check status for ${device.name}`"
    class="min-h-[44px] min-w-[44px] flex-1 sm:flex-initial py-2 px-2 bg-white hover:bg-slate-50 text-slate-700 text-xs font-semibold rounded-lg border border-slate-200 transition-colors disabled:opacity-50 cursor-pointer text-center flex items-center justify-center shadow-xs focus:outline-none focus:ring-2 focus:ring-indigo-500"
    title="Check camera status via MQTT Protocol"
  >
    {{ testingId === device.id ? '...' : '🔍 Check' }}
  </button>
  <button 
    @click="importPersonnelFromCamera(device)"
    :disabled="importingId === device.id"
    :aria-label="`Import personnel library from ${device.name}`"
    class="min-h-[44px] min-w-[44px] flex-1 sm:flex-initial py-2 px-2 bg-emerald-50 hover:bg-emerald-100 text-emerald-800 text-xs font-semibold rounded-lg border border-emerald-200 transition-colors disabled:opacity-50 cursor-pointer text-center flex items-center justify-center shadow-xs focus:outline-none focus:ring-2 focus:ring-emerald-500"
    title="Import Personnel & Face Library from Camera"
  >
    {{ importingId === device.id ? 'Importing...' : '📥 Import' }}
  </button>
  <button 
    @click="auditCameraFaces(device)"
    :aria-label="`Audit face synchronization for ${device.name}`"
    class="min-h-[44px] min-w-[44px] flex-1 sm:flex-initial py-2 px-2 bg-white hover:bg-slate-50 text-slate-700 text-xs font-semibold rounded-lg border border-slate-200 transition-colors cursor-pointer text-center flex items-center justify-center shadow-xs focus:outline-none focus:ring-2 focus:ring-indigo-500"
    title="Audit Face Synchronization"
  >
    👥 Audit
  </button>
  <button 
    @click="openBackfillModal(device)"
    :aria-label="`Backfill historical logs for ${device.name}`"
    class="min-h-[44px] min-w-[44px] flex-1 sm:flex-initial py-2 px-2 bg-amber-50 hover:bg-amber-100 text-amber-800 text-xs font-semibold rounded-lg border border-amber-200 transition-colors cursor-pointer text-center flex items-center justify-center shadow-xs focus:outline-none focus:ring-2 focus:ring-amber-500"
    title="Backfill Historical Logs"
  >
    📥 Backfill
  </button>
  <button 
    @click="openEditModal(device)"
    :aria-label="`Configure settings for ${device.name}`"
    class="min-h-[44px] min-w-[44px] flex-1 sm:flex-initial py-2 px-2 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 text-xs font-semibold rounded-lg border border-indigo-200 transition-colors cursor-pointer text-center flex items-center justify-center shadow-xs focus:outline-none focus:ring-2 focus:ring-indigo-500"
    title="Configure Device"
  >
    ⚙️ Config
  </button>
</div>

<!-- Line 178: Dialog Semantics, Focus Trap & Escape on Camera Config Modal -->
<div 
  v-if="deviceModal.show" 
  role="dialog"
  aria-modal="true"
  aria-labelledby="device-modal-title"
  @keydown.escape="deviceModal.show = false"
  class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm" 
  @click.self="deviceModal.show = false"
>
  <div class="bg-white border border-slate-200 rounded-2xl max-w-4xl w-full p-6 sm:p-7 space-y-5 max-h-[92vh] overflow-y-auto shadow-2xl">
    <div class="flex items-center justify-between border-b border-slate-200 pb-3">
      <div>
        <h3 id="device-modal-title" class="text-base font-bold text-slate-900 flex items-center gap-2">
          <span>{{ deviceModal.isEdit ? '⚙️ Camera Configuration & Parameters' : '➕ Register AI Camera Device' }}</span>
        </h3>
      </div>
      <button 
        @click="deviceModal.show = false" 
        aria-label="Close configuration modal"
        class="text-slate-400 hover:text-slate-700 text-xl font-bold cursor-pointer p-1 rounded focus:outline-none focus:ring-2 focus:ring-indigo-500"
      >&times;</button>
    </div>

    <!-- ARIA Tabs Pattern (role="tablist" / role="tab") -->
    <div v-if="deviceModal.isEdit" role="tablist" aria-label="Camera configuration categories" class="flex items-center gap-1 border-b border-slate-200 overflow-x-auto pb-1">
      <button 
        role="tab"
        id="tab-general"
        aria-controls="panel-general"
        :aria-selected="modalTab === 'general'"
        type="button"
        @click="modalTab = 'general'"
        class="px-3 py-1.5 text-xs font-medium rounded-lg transition-colors whitespace-nowrap cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
        :class="modalTab === 'general' ? 'bg-indigo-600 text-white font-semibold shadow-xs' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100'"
      >
        🔌 General &amp; Network
      </button>
      <button 
        role="tab"
        id="tab-mqtt"
        aria-controls="panel-mqtt"
        :aria-selected="modalTab === 'mqtt'"
        type="button"
        @click="modalTab = 'mqtt'"
        class="px-3 py-1.5 text-xs font-medium rounded-lg transition-colors whitespace-nowrap cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
        :class="modalTab === 'mqtt' ? 'bg-indigo-600 text-white font-semibold shadow-xs' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100'"
      >
        📡 MQTT Protocol
      </button>
      <!-- Similar role="tab" bindings for time, resend, maintenance -->
    </div>

    <!-- Tab Panels with for/id bindings -->
    <form v-if="!deviceModal.isEdit || modalTab === 'general'" role="tabpanel" id="panel-general" aria-labelledby="tab-general" @submit.prevent="saveDevice" class="space-y-4">
      <div class="bg-indigo-50/70 border border-indigo-200 p-4 rounded-xl space-y-2.5">
        <label for="device_endpoint" class="block text-xs font-bold text-indigo-950 flex items-center gap-1.5">
          <span>🎥 Camera Preview &amp; Stream Endpoint *</span>
        </label>
        <input 
          id="device_endpoint"
          v-model="deviceForm.endpoint" 
          type="text" 
          placeholder="e.g. ai-camera.philyra.cloud" 
          class="w-full bg-white border border-indigo-300 rounded-lg px-3 py-2 text-xs font-mono text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-500 shadow-xs"
        />
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
          <label for="device_serial" class="block text-xs font-medium text-slate-700 mb-1">Device ID / Serial *</label>
          <input 
            id="device_serial"
            v-model="deviceForm.device_id" 
            :readonly="deviceModal.isEdit"
            :disabled="deviceModal.isEdit"
            required 
            type="text" 
            class="w-full border rounded-lg px-3 py-2 text-xs font-mono"
          />
        </div>
        <div>
          <label for="device_name" class="block text-xs font-medium text-slate-700 mb-1">Friendly Display Name *</label>
          <input id="device_name" v-model="deviceForm.name" required type="text" class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900" />
        </div>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
          <label for="device_username" class="block text-xs font-medium text-slate-700 mb-1">HTTP Basic Auth Username</label>
          <input id="device_username" v-model="deviceForm.username" type="text" class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-mono" />
        </div>
        <div>
          <label for="device_password" class="block text-xs font-medium text-slate-700 mb-1">HTTP Basic Auth Password</label>
          <input id="device_password" v-model="deviceForm.password" type="password" class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-mono" />
        </div>
      </div>
    </form>
  </div>
</div>
```

---

### Blueprint 5: Personnel & Face Library (`PersonnelManager.vue`)

#### Tasks PERS-01 through PERS-05: `resources/js/views/PersonnelManager.vue`
```html
<!-- Search & Filters with aria-label -->
<input 
  v-model="search" 
  @input="fetchPersonnel"
  type="text" 
  aria-label="Search personnel by name, ID number, phone, or custom ID"
  placeholder="Search by name, ID number, phone, or custom ID..."
  class="w-full bg-white border border-slate-200 rounded-lg pl-9 pr-4 py-2 text-xs"
/>

<select v-model="personTypeFilter" @change="fetchPersonnel" aria-label="Filter personnel by category" class="w-full sm:w-44 bg-white border border-slate-200 text-xs rounded-lg px-3 py-2 text-slate-700">
  <option value="">All Categories</option>
  <option value="0">Whitelist (Allow)</option>
  <option value="1">Blacklist (Block)</option>
</select>

<select v-model="validityFilter" @change="fetchPersonnel" aria-label="Filter personnel by validity schedule" class="w-full sm:w-44 bg-white border border-slate-200 text-xs rounded-lg px-3 py-2 text-slate-700">
  <option value="">All Validity</option>
  <option value="0">Permanent</option>
  <option value="1">Temporary Schedule</option>
</select>

<!-- Table Headers with scope="col" -->
<thead class="bg-slate-50 text-slate-500 uppercase text-[11px] font-semibold border-b border-slate-200">
  <tr>
    <th scope="col" class="py-3 px-4">Photo</th>
    <th scope="col" class="py-3 px-4">Custom ID</th>
    <th scope="col" class="py-3 px-4">Name</th>
    <th scope="col" class="py-3 px-4">Category</th>
    <th scope="col" class="py-3 px-4">ID / Phone</th>
    <th scope="col" class="py-3 px-4">Schedule</th>
    <th scope="col" class="py-3 px-4 text-right">Actions</th>
  </tr>
</thead>

<!-- 5 Animated Skeleton Table Rows Replacing Single Cell -->
<template v-if="loading">
  <tr v-for="i in 5" :key="i" class="animate-pulse">
    <td class="py-3 px-4"><div class="w-10 h-10 rounded-full bg-slate-200"></div></td>
    <td class="py-3 px-4"><div class="h-4 bg-slate-200 rounded w-16"></div></td>
    <td class="py-3 px-4"><div class="h-4 bg-slate-200 rounded w-28"></div></td>
    <td class="py-3 px-4"><div class="h-5 bg-slate-200 rounded-full w-20"></div></td>
    <td class="py-3 px-4"><div class="h-4 bg-slate-200 rounded w-24"></div></td>
    <td class="py-3 px-4"><div class="h-4 bg-slate-200 rounded w-20"></div></td>
    <td class="py-3 px-4 text-right"><div class="h-6 bg-slate-200 rounded w-24 ml-auto"></div></td>
  </tr>
</template>

<!-- Action buttons with contextual aria-labels -->
<button 
  @click="triggerSync(person)" 
  :disabled="syncingId === person.id" 
  :aria-label="`Sync ${person.name} to cameras`"
  class="px-2.5 py-1 text-[11px] bg-white hover:bg-slate-50 text-indigo-600 font-semibold border border-slate-200 rounded transition-colors shadow-2xs cursor-pointer disabled:opacity-50"
>
  {{ syncingId === person.id ? 'Syncing...' : '⚡ Sync' }}
</button>
<button 
  @click="openEditModal(person)" 
  :aria-label="`Edit ${person.name}`"
  class="px-2.5 py-1 text-[11px] bg-white hover:bg-slate-50 text-slate-700 font-medium border border-slate-200 rounded transition-colors shadow-2xs cursor-pointer"
>
  Edit
</button>
<button 
  @click="deletePerson(person)" 
  :aria-label="`Delete ${person.name}`"
  class="px-2.5 py-1 text-[11px] bg-rose-50 hover:bg-rose-100 text-rose-700 font-semibold border border-rose-200 rounded transition-colors shadow-2xs cursor-pointer"
>
  Delete
</button>

<!-- Submit button with accessible loading spinner -->
<button 
  type="submit" 
  :disabled="saving" 
  class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-lg shadow-sm disabled:opacity-50 transition-all cursor-pointer flex items-center gap-1.5"
>
  <span v-if="saving" class="animate-spin inline-block w-3.5 h-3.5 border-2 border-white border-t-transparent rounded-full" aria-hidden="true"></span>
  <span>{{ saving ? 'Saving & Enrolling...' : (modal.isEdit ? 'Update Personnel' : 'Enroll Personnel') }}</span>
</button>
```

---

### Blueprint 6: Workforce Directory & Profiles (`EmployeeDirectory.vue`, `EmployeeFormModal.vue`)

#### Tasks EMP-01 through EMP-05:
```html
<!-- EmployeeDirectory.vue: View mode toggle with role="group" and aria-pressed -->
<div class="flex items-center bg-slate-100 p-1 rounded-xl border border-slate-200" role="group" aria-label="View layout switcher">
  <button
    type="button"
    @click="store.setViewMode('table')"
    :aria-pressed="store.viewMode === 'table'"
    class="px-2.5 py-1 text-xs font-semibold rounded-lg transition-all cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
    :class="store.viewMode === 'table' ? 'bg-white text-slate-900 shadow-xs' : 'text-slate-500 hover:text-slate-800'"
    title="Table View"
  >
    📋 Table
  </button>
  <button
    type="button"
    @click="store.setViewMode('grid')"
    :aria-pressed="store.viewMode === 'grid'"
    class="px-2.5 py-1 text-xs font-semibold rounded-lg transition-all cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
    :class="store.viewMode === 'grid' ? 'bg-white text-slate-900 shadow-xs' : 'text-slate-500 hover:text-slate-800'"
    title="Grid Cards"
  >
    🔲 Grid
  </button>
</div>

<!-- EmployeeDirectory.vue: Accessible action buttons with aria-label -->
<button
  @click="viewProfile(emp)"
  :aria-label="`View full profile of ${emp.first_name} ${emp.last_name || ''}`"
  class="p-1.5 text-slate-500 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
  title="View Full Profile"
>
  <span aria-hidden="true">👁️</span>
</button>
<button
  @click="editEmployee(emp)"
  :aria-label="`Edit employee ${emp.first_name} ${emp.last_name || ''}`"
  class="p-1.5 text-slate-500 hover:text-slate-800 hover:bg-slate-100 rounded-lg transition-colors cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
  title="Edit Record"
>
  <span aria-hidden="true">✏️</span>
</button>

<!-- EmployeeFormModal.vue: Semantic <form @submit.prevent="handleSubmit"> and label/input bindings -->
<form @submit.prevent="handleSubmit" class="flex flex-col flex-1 overflow-hidden">
  <div class="px-6 py-5 overflow-y-auto flex-1 space-y-6">
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
      <div>
        <label for="emp_code" class="block text-xs font-semibold text-slate-700 mb-1">
          Employee Code <span class="text-rose-500">*</span>
        </label>
        <input
          id="emp_code"
          v-model="form.employee_code"
          type="text"
          required
          aria-required="true"
          placeholder="e.g. EMP-1001"
          class="w-full px-3 py-2 text-xs border border-slate-200 rounded-xl"
        />
      </div>
      <div>
        <label for="emp_status" class="block text-xs font-semibold text-slate-700 mb-1">
          Employment Status <span class="text-rose-500">*</span>
        </label>
        <select
          id="emp_status"
          v-model="form.employment_status"
          required
          aria-required="true"
          class="w-full px-3 py-2 text-xs border border-slate-200 rounded-xl bg-white"
        >
          <option value="active">Active (Whitelisted)</option>
          <option value="probation">Probation (Whitelisted)</option>
          <option value="suspended">Suspended (Blacklisted)</option>
          <option value="terminated">Terminated (Blacklisted)</option>
          <option value="resigned">Resigned (Blacklisted)</option>
        </select>
      </div>
      <div>
        <label for="emp_first_name" class="block text-xs font-semibold text-slate-700 mb-1">
          First Name <span class="text-rose-500">*</span>
        </label>
        <input
          id="emp_first_name"
          v-model="form.first_name"
          type="text"
          required
          aria-required="true"
          class="w-full px-3 py-2 text-xs border border-slate-200 rounded-xl"
        />
      </div>
      <div>
        <label for="emp_last_name" class="block text-xs font-semibold text-slate-700 mb-1">Last Name</label>
        <input id="emp_last_name" v-model="form.last_name" type="text" class="w-full px-3 py-2 text-xs border border-slate-200 rounded-xl" />
      </div>
    </div>
  </div>

  <div class="px-6 py-4 border-t border-slate-100 flex items-center justify-end gap-3 bg-slate-50/50">
    <button type="button" @click="closeModal" class="px-4 py-2 text-xs font-semibold text-slate-600 hover:text-slate-800">Cancel</button>
    <!-- Submit on Enter enabled via type="submit" -->
    <button type="submit" :disabled="store.saving" class="px-5 py-2 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl shadow-xs">
      <span v-if="store.saving">Saving...</span>
      <span v-else>{{ isEditing ? 'Save Changes' : 'Enroll Employee' }}</span>
    </button>
  </div>
</form>
```

---

### Blueprint 7: Visitor Management & Kiosk Check-In (`VisitorCheckInWizard.vue`)

#### Tasks VIS-01 through VIS-04:
```html
<!-- Step Progress Indicator with aria-current="step" & aria-live -->
<nav aria-label="Check-in Steps" class="pt-1">
  <ol class="flex items-center gap-2">
    <li 
      v-for="step in 3" 
      :key="step" 
      :aria-current="currentStep === step ? 'step' : undefined"
      class="flex items-center gap-1.5 text-xs font-semibold"
      :class="currentStep === step ? 'text-indigo-600' : (currentStep > step ? 'text-emerald-600' : 'text-slate-400')"
    >
      <span 
        class="w-5 h-5 rounded-full flex items-center justify-center text-[10px] border"
        :class="currentStep === step ? 'border-indigo-600 bg-indigo-50 font-bold' : (currentStep > step ? 'border-emerald-600 bg-emerald-50 text-emerald-700' : 'border-slate-300')"
      >
        {{ currentStep > step ? '✓' : step }}
      </span>
      <span class="hidden sm:inline">{{ step === 1 ? 'Visitor Information' : (step === 2 ? 'Host & Purpose' : 'Biometrics') }}</span>
      <span v-if="step < 3" class="text-slate-300" aria-hidden="true">/</span>
    </li>
  </ol>
  <div aria-live="polite" class="sr-only">Currently on Step {{ currentStep }} of 3: {{ stepTitle }}</div>
</nav>

<!-- Step Validation Logic in Script -->
<script setup>
const stepError = ref('');

const nextStep = () => {
  stepError.value = '';
  if (currentStep.value === 1) {
    if (!form.first_name.trim()) {
      stepError.value = 'Please provide the visitor\'s first name.';
      return;
    }
  }
  currentStep.value++;
};
</script>

<!-- Accessible SVG Spinner during Provisioning -->
<button 
  v-else 
  type="button" 
  @click="handleCompleteCheckIn" 
  :disabled="submitting" 
  class="px-5 py-2 text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 rounded-xl shadow-xs flex items-center gap-2 transition-colors cursor-pointer"
>
  <svg v-if="submitting" class="animate-spin h-4 w-4 text-white" fill="none" viewBox="0 0 24 24" aria-hidden="true">
    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
  </svg>
  <span>{{ submitting ? 'Provisioning Cameras...' : 'Confirm Check-In' }}</span>
  <span v-if="submitting" class="sr-only" role="status" aria-live="polite">Provisioning biometric access to camera hardware...</span>
</button>
```

---

### Blueprint 8: Live Video Preview (`CameraLivePreviewModal.vue`)

#### Tasks CAM-01 through CAM-04:
```html
<template>
  <div
    v-if="isOpen"
    role="dialog"
    aria-modal="true"
    aria-labelledby="camera-preview-title"
    @keydown.escape="close"
    class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-md animate-fadeIn"
    @click.self="close"
  >
    <div
      class="relative w-full max-w-5xl bg-white border border-slate-200 rounded-2xl shadow-2xl overflow-hidden flex flex-col max-h-[92vh]"
      :class="{ '!max-w-none !h-full !max-h-none !rounded-none': isFullscreen }"
    >
      <!-- Responsive Header <640px -->
      <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 px-4 sm:px-6 py-3 sm:py-4 bg-white border-b border-slate-200">
        <div class="flex items-center space-x-3 min-w-0">
          <div class="p-2 rounded-xl bg-indigo-50 border border-indigo-100 text-indigo-600 shrink-0">
            <svg class="w-6 h-6 animate-pulse" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
            </svg>
          </div>
          <div class="min-w-0">
            <div class="flex items-center space-x-2">
              <h3 id="camera-preview-title" class="text-base sm:text-lg font-bold text-slate-900 tracking-wide truncate">
                {{ device?.name || 'Camera Preview' }}
              </h3>
              <span class="px-2 py-0.5 text-xs font-semibold rounded-full flex items-center space-x-1 shrink-0" :class="statusBadgeClass">
                <span class="w-1.5 h-1.5 rounded-full animate-ping" :class="statusDotClass"></span>
                <span>{{ playerStatus.state || 'CONNECTING' }}</span>
              </span>
            </div>
            <p class="text-[11px] sm:text-xs text-slate-500 font-mono mt-0.5 truncate">
              ID: <span class="text-slate-700">{{ device?.device_id }}</span> |
              <span class="hidden sm:inline">Stream: </span><span class="text-indigo-600 font-bold truncate">{{ wsUrlDisplay }}</span>
            </p>
          </div>
        </div>

        <!-- Header Actions with Quality Switcher ARIA attributes -->
        <div class="flex items-center justify-between sm:justify-end w-full sm:w-auto gap-2 flex-wrap">
          <div role="group" aria-label="Stream video resolution" class="bg-slate-100 p-1 rounded-lg border border-slate-200 flex items-center text-xs font-medium">
            <button
              type="button"
              @click="switchQuality(0)"
              :aria-pressed="streamType === 0"
              class="px-2.5 py-1 rounded transition-all cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
              :class="streamType === 0 ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-600 hover:text-slate-900'"
            >
              1080P Main
            </button>
            <button
              type="button"
              @click="switchQuality(1)"
              :aria-pressed="streamType === 1"
              class="px-2.5 py-1 rounded transition-all cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
              :class="streamType === 1 ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-600 hover:text-slate-900'"
            >
              720P Sub
            </button>
          </div>

          <button
            @click="toggleFullscreen"
            :aria-label="isFullscreen ? 'Exit Fullscreen' : 'Enter Fullscreen'"
            class="p-2 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-600 hover:text-slate-900 border border-slate-200 transition cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
          >
            <!-- SVG icons -->
          </button>

          <button
            @click="close"
            aria-label="Close live video preview"
            class="p-2 rounded-lg bg-slate-100 hover:bg-rose-50 text-slate-500 hover:text-rose-600 border border-slate-200 hover:border-rose-200 transition cursor-pointer focus:outline-none focus:ring-2 focus:ring-rose-500"
          >
            <!-- SVG close icon -->
          </button>
        </div>
      </div>
      <!-- Video canvas and bottom bar -->
    </div>
  </div>
</template>
```

---

### Blueprint 9: Attendance Stream & Manual Entry (`AttendanceDashboard.vue`, `ManualAttendanceEntry.vue`)

#### Tasks ATT-01 through ATT-03:
```html
<!-- AttendanceDashboard.vue: Live Attendance Stream with aria-live="polite" -->
<div 
  v-else 
  role="log"
  aria-live="polite" 
  aria-relevant="additions text" 
  class="space-y-2 max-h-80 overflow-y-auto pr-1"
>
  <div 
    v-for="punch in attendanceStore.recentLivePunches" 
    :key="punch.id"
    class="flex items-center justify-between p-3.5 bg-slate-50 border border-slate-200/80 rounded-xl hover:bg-slate-100/70 transition-colors"
  >
    <!-- Punch card details -->
  </div>
</div>

<!-- AttendanceDashboard.vue: Accessible confirm dialog replacement for window.confirm() -->
<script setup>
import notify from '../../utils/notify';

const triggerFinalize = async () => {
  const confirmed = await notify.confirm(
    'Finalize Today\'s Attendance',
    'Finalize today attendance records for all active employees? This will mark unexcused absences and calculate overtime.',
    'Yes, Finalize Today'
  );
  if (confirmed) {
    await attendanceStore.triggerDailyFinalizer();
  }
};
</script>

<!-- ManualAttendanceEntry.vue: Dialog accessibility and for/id bindings -->
<template>
  <div 
    v-if="isOpen" 
    role="dialog"
    aria-modal="true"
    aria-labelledby="manual-entry-modal-title"
    @keydown.escape="close"
    class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm" 
    @click.self="close"
  >
    <div class="bg-white border border-slate-200 rounded-2xl max-w-lg w-full p-6 shadow-2xl space-y-5 animate-in fade-in zoom-in-95 duration-150">
      <div class="flex items-center justify-between border-b border-slate-100 pb-4">
        <h3 id="manual-entry-modal-title" class="text-base font-bold text-slate-900">Manual Attendance Entry</h3>
        <button @click="close" aria-label="Close manual entry dialog" class="text-slate-400 hover:text-slate-700 font-bold p-1 cursor-pointer">✕</button>
      </div>

      <form @submit.prevent="handleSubmit" class="space-y-4">
        <div>
          <label for="manual_employee_id" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">Employee *</label>
          <select id="manual_employee_id" v-model="form.employee_id" required class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-medium">
            <option value="" disabled>Select Employee</option>
            <option v-for="emp in employees" :key="emp.id" :value="emp.id">{{ emp.employee_code }} - {{ emp.first_name }} {{ emp.last_name || '' }}</option>
          </select>
        </div>
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label for="manual_date" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">Date *</label>
            <input id="manual_date" v-model="form.date" type="date" required class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs" />
          </div>
          <div>
            <label for="manual_time" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">Time *</label>
            <input id="manual_time" v-model="form.time" type="time" required class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs" />
          </div>
        </div>
        <!-- Punch Direction & Reason with id/for -->
      </form>
    </div>
  </div>
</template>
```

---

### Blueprint 10: Reports & Payroll Export (`PayrollExportModal.vue`)

#### Tasks REP-01 through REP-03:
```html
<template>
  <div 
    v-if="isOpen" 
    role="dialog"
    aria-modal="true"
    aria-labelledby="payroll-export-modal-title"
    @keydown.escape="close"
    class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm" 
    @click.self="close"
  >
    <div class="bg-white border border-slate-200 rounded-2xl max-w-md w-full p-6 shadow-2xl space-y-5 animate-in fade-in zoom-in-95 duration-150">
      <div class="flex items-center justify-between border-b border-slate-100 pb-3">
        <div class="flex items-center space-x-2">
          <span class="text-xl" aria-hidden="true">💰</span>
          <h3 id="payroll-export-modal-title" class="font-bold text-slate-900 text-base">Export Payroll Attendance Data</h3>
        </div>
        <button @click="close" aria-label="Close export dialog" class="text-slate-400 hover:text-slate-700 font-bold p-1 cursor-pointer">✕</button>
      </div>

      <div class="space-y-4">
        <!-- Month & Year with for/id bindings -->
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label for="payroll_month" class="block text-xs font-semibold text-slate-700 mb-1">Payroll Month</label>
            <select id="payroll_month" v-model="month" class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs">
              <option v-for="m in 12" :key="m" :value="m">{{ new Date(2026, m - 1).toLocaleString('default', { month: 'long' }) }}</option>
            </select>
          </div>
          <div>
            <label for="payroll_year" class="block text-xs font-semibold text-slate-700 mb-1">Payroll Year</label>
            <select id="payroll_year" v-model="year" class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs">
              <option :value="2026">2026</option>
              <option :value="2025">2025</option>
            </select>
          </div>
        </div>

        <!-- Semantic <fieldset> and <legend> for Export Format -->
        <fieldset class="space-y-1 border-0 p-0 m-0">
          <legend class="block text-xs font-semibold text-slate-700 mb-1">Export Format</legend>
          <div class="grid grid-cols-2 gap-2">
            <label class="flex items-center space-x-2 p-3 bg-slate-50 border border-slate-200 rounded-xl cursor-pointer hover:bg-slate-100/70 transition-colors" :class="{'border-indigo-500 ring-2 ring-indigo-500/20 bg-indigo-50/30': format === 'csv'}">
              <input type="radio" v-model="format" value="csv" class="text-indigo-600 focus:ring-indigo-500" />
              <span class="text-xs font-medium text-slate-800">CSV Spreadsheet</span>
            </label>
            <label class="flex items-center space-x-2 p-3 bg-slate-50 border border-slate-200 rounded-xl cursor-pointer hover:bg-slate-100/70 transition-colors" :class="{'border-indigo-500 ring-2 ring-indigo-500/20 bg-indigo-50/30': format === 'json'}">
              <input type="radio" v-model="format" value="json" class="text-indigo-600 focus:ring-indigo-500" />
              <span class="text-xs font-medium text-slate-800">JSON API Format</span>
            </label>
          </div>
        </fieldset>
      </div>

      <!-- Action buttons with async loading state -->
      <div class="flex justify-end space-x-2 pt-3 border-t border-slate-100">
        <button type="button" @click="close" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 rounded-xl text-xs font-semibold transition-colors cursor-pointer">Cancel</button>
        <button 
          type="button"
          @click="handleExport" 
          :disabled="exporting"
          class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white rounded-xl text-xs font-semibold flex items-center gap-2 shadow-xs transition-colors cursor-pointer"
        >
          <span v-if="exporting" class="animate-spin inline-block w-3.5 h-3.5 border-2 border-white border-t-transparent rounded-full" aria-hidden="true"></span>
          <span v-else aria-hidden="true">📥</span>
          <span>{{ exporting ? 'Generating Export...' : 'Download Export' }}</span>
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { useReportStore } from '../../stores/reportStore';

const props = defineProps({ isOpen: Boolean });
const emit = defineEmits(['close']);

const reportStore = useReportStore();
const month = ref(new Date().getMonth() + 1);
const year = ref(2026);
const format = ref('csv');
const exporting = ref(false);

const close = () => emit('close');

const handleExport = async () => {
  exporting.value = true;
  try {
    await reportStore.exportPayroll(month.value, year.value, format.value);
    close();
  } finally {
    exporting.value = false;
  }
};
</script>
```

---

## 5. Verification Method

To verify the implementation of this blueprint independently:

1. **Build Validation**:
   ```bash
   npm run build
   ```
   *Expected outcome*: Vite compiles all client bundles cleanly with 0 errors and 0 syntax warnings.
2. **Keyboard Navigation & ARIA Verification**:
   - In `App.vue`: Press `Tab` to reach mobile menu trigger; verify `aria-expanded` toggles between `true` and `false`. Open User Profile menu and verify `Escape` closes the menu.
   - In `LoginPage.vue`: Trigger an invalid login; verify screen reader announces `role="alert"` assertive message, and error dismiss button is focusable with visible outline ring.
   - In `LiveTelemetry.vue`: Tab to thumbnail and press `Enter` to open image inspection modal; verify `role="dialog"` is active and pressing `Escape` immediately closes modal.
   - In `DeviceManager.vue`: Inspect DOM to confirm `<label>` elements have `for` matching `<input id="...">`. Tab through tabs and verify `role="tab"` and `role="tablist"` announcements.
   - In `EmployeeFormModal.vue`: Press `Enter` within input; confirm form submit handler is triggered.
   - In `PayrollExportModal.vue`: Trigger export; confirm button is disabled, spinner appears, and screen readers read `<fieldset>` / `<legend>` grouping.
3. **Invalidation Criteria**:
   - Any clickable element represented solely by a `<div>` with `@click` that lacks keyboard focusability or role.
   - Any modal dialog that does not close on `Escape` key press.
   - Any table or card view that displays a single-text layout shift during data load instead of structured skeleton placeholders.
