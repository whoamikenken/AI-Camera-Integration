# Frontend Architecture Survey & Transformation Report
**Intelligent AI Camera Hub → Attendance & Visitor Management System**

- **Date**: 2026-09-29
- **Author**: Frontend Architecture Explorer (Survey Phase)
- **Target Repository**: `/home/wsk-devops2/AI-Camera-Integration`
- **Integrity Mode**: Development / Survey

---

## 1. Executive Summary

The **Intelligent AI Camera Hub** frontend is currently a modern, lightweight single-page application (SPA) built with **Vue 3.5**, **Vite 8.2**, **Tailwind CSS v4.3**, **Pinia 4.0**, and **Laravel Echo + Reverb**. It provides device fleet administration, face enrollment, and live camera vision telemetry monitoring.

To transform this platform into an enterprise-grade **Attendance and Visitor Management System**, the frontend architecture must evolve from a camera-only monitoring tool into an expansive multi-role operational hub. The target platform encompasses:
1. **User Authentication & Role-Based Access Control (RBAC)** (Super-Admin, Admin, HR Manager, Security, Receptionist, Manager, Employee).
2. **Employee Directory & Biometric Linkage** (Organizational hierarchy, departments, designations, face library linkage).
3. **Shift & Schedule Management** (Shift definitions, overnight handling, grace periods, department assignments, holiday calendar).
4. **Biometric Attendance Processing & Live Roster** (Real-time "Who's In / Who's Out", daily roster, punch audit modals, manual overrides, employee attendance calendars).
5. **Leave Management & Approval Workflows** (Leave types, balances, multi-day/half-day requests, manager approval queue).
6. **End-to-End Visitor Lifecycle** (Expected visitors, 5-step receptionist check-in wizard with webcam face capture, temporary camera hardware provisioning, printable badges, checkout revocation, watchlists).
7. **Reporting, Analytics & Payroll Exports** (Daily/monthly timesheets, tardiness reports, CSV/PDF/Excel exports).
8. **System Configuration, Audit Logs & Device Enhancements** (Entry/exit direction tags, attendance rule parameters, system audit viewer).
9. **Employee Self-Service Portal** (Personal clock-in logs, attendance regularization requests, leave applications).

This report presents a thorough analysis of the existing frontend codebase, identifies architectural gaps, and provides concrete specifications, component designs, and migration strategies for all subsequent implementation phases.

---

## 2. Current Frontend Architecture & Asset Inventory

### 2.1 File Tree Structure

```
resources/
├── css/
│   └── app.css                         # Tailwind CSS v4 theme and font definitions
├── images/
│   ├── pinnacle-icon.svg               # Brand favicon and small icon
│   ├── pinnacle-logo-dark.svg          # Dark theme logo asset
│   └── pinnacle-logo-light.svg         # Light theme logo asset
├── js/
│   ├── App.vue                         # Main application shell with 5 KPI cards & 6 tabs
│   ├── app.js                          # Vue 3 createApp entry point & Pinia mount
│   ├── echo.js                         # Laravel Echo configuration for Reverb WebSockets
│   ├── components/
│   │   ├── CameraLivePreviewModal.vue  # WebGL H.264 live stream canvas with HUD
│   │   ├── DeviceAuditModal.vue        # Device face library parity check & diagnostics
│   │   └── HistoricalBackfillModal.vue # Multi-device log backfill with date presets
│   ├── stores/
│   │   └── cameraStore.js              # Pinia store for telemetry buffers & device stats
│   ├── utils/
│   │   ├── cameraHqPlayer.js           # WebGL/WASM hardware player implementation
│   │   ├── date.js                     # PHT (Asia/Manila) date/time formatting utilities
│   │   ├── md5.js                      # MD5 hash implementation for camera auth
│   │   └── notify.js                   # SweetAlert2 mixin wrappers (modals & toasts)
│   └── views/
│       ├── AccessLogsHistory.vue       # Filterable audit log table with image modal
│       ├── DeviceManager.vue           # 5-tab device fleet configuration & controls
│       ├── LiveTelemetry.vue           # Real-time biometric verification event stream
│       ├── PersonnelManager.vue        # Biometric face library CRUD & camera sync
│       ├── StrangerSnapsMonitor.vue    # Stranger capture gallery & quick-enrollment
│       └── SyncTasksMonitor.vue        # Redis camera-sync outbox task inspector
└── views/
    └── welcome.blade.php               # HTML5 host view with CSRF token & Vite assets
```

### 2.2 Package Dependencies & Build Tooling

Extracted from `/home/wsk-devops2/AI-Camera-Integration/package.json`:

| Dependency | Version | Category | Role in System |
| :--- | :--- | :--- | :--- |
| `vue` | `^3.5.41` | Core Framework | Vue 3 Composition API runtime |
| `vite` | `^8.0.0` (8.2.2 active) | Build Tool | Lightning-fast HMR and Rollup bundler |
| `@vitejs/plugin-vue` | `^6.0.8` | Vite Plugin | Single File Component (.vue) compilation |
| `@tailwindcss/vite` | `^4.3.3` | Styling | First-class Tailwind CSS v4 compiler plugin |
| `tailwindcss` | `^4.3.3` | Styling | Utility-first CSS engine |
| `laravel-vite-plugin` | `^3.1` | Integration | Asset manifest bridge between Laravel & Vite |
| `pinia` | `^4.0.3` | State Store | Modular reactive state management |
| `axios` | `^1.19.0` | HTTP Client | REST API requests to Laravel backend |
| `laravel-echo` | `^2.4.0` | Real-Time | Pusher-protocol abstraction for Reverb |
| `pusher-js` | `^8.6.0` | Real-Time | Low-level WebSocket client for Echo |
| `lucide-vue-next` | `^1.0.0` | Iconography | High-quality Vue 3 icon component library |
| `sweetalert2` | `^11.26.25` | UI Feedback | Accessible modal alerts and toast notifications |

### 2.3 Tailwind CSS v4 Configuration & Typography

The styling uses the modern Tailwind CSS v4 configuration model in `resources/css/app.css`:
- `@import 'tailwindcss';` replaces legacy `@tailwind base; @tailwind components; @tailwind utilities;`.
- There is **no legacy `tailwind.config.js`**; theme customizations are declared directly in CSS using `@theme`:
  ```css
  @theme {
      --font-sans: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji',
          'Segoe UI Symbol', 'Noto Color Emoji';
  }
  ```
- Google Fonts are preloaded in `resources/views/welcome.blade.php`:
  - `Instrument Sans`: Clean, contemporary grotesque sans-serif for UI labels, titles, and body copy.
  - `JetBrains Mono`: Monospace font utilized across IDs (`#1299517`), IP addresses, ports, and timestamps.
- **Design Tokens**:
  - Neutral Base: `slate-50` (page canvas), `slate-100` / `slate-200` (borders/dividers), `slate-700` (body), `slate-900` (headings).
  - Primary Brand: `indigo-600` / `indigo-700` (actions, links, active tab indicator).
  - Success / Allowed: `emerald-500` / `emerald-600` (allowed passes, active cameras, online badges).
  - Warning / Alert: `amber-500` / `amber-600` (stranger alerts, temporary schedules).
  - Danger / Rejection: `rose-500` / `rose-600` (rejected scans, failed sync tasks, destructive buttons).

### 2.4 Build Verification Baseline

Executing `npm run build` verified that the production build pipeline is healthy:
- Execution time: **605ms**
- Output bundle:
  - `public/build/manifest.json`: `0.83 kB`
  - `public/build/assets/app-CuviFbgB.js`: `452.77 kB` (gzip: `129.31 kB`)
  - `public/build/assets/app-BPR2fmBj.css`: `67.34 kB` (gzip: `11.45 kB`)
  - `public/build/assets/pinnacle-icon-8b-r986u.svg`: `1.63 kB`
  - `public/build/assets/pinnacle-logo-light-BKV2MKVJ.svg`: `2.21 kB`

---

## 3. Data Layer & State Management Architecture

### 3.1 Existing Store (`cameraStore.js`) Analysis

The existing store (`resources/js/stores/cameraStore.js`) manages:
- `liveLogs`: In-memory array of the latest 50 access verification events. Includes payload normalization for both stringified JSON and nested event structures.
- `strangerSnaps`: Buffer of the latest 30 stranger capture frames.
- `devices`: List of camera device records with live telemetry counts.
- `stats`: Aggregate counters for telemetry (`total_scans_today`, `allowed_today`, `rejected_today`, `strangers_today`), device health (`total`, `online`, `offline`), personnel library (`total`, `whitelisted`, `blacklisted`), and sync queue (`pending`, `failed`).
- `wsConnected`: Boolean flag tracking Reverb WebSocket connectivity.
- `soundEnabled`: Toggle for Web Audio API synthetic alert tone on rejected verifications (`verify_status === 2`).

### 3.2 Architectural Gaps in API Client Configuration

Currently, views and components directly import raw `axios`:
```javascript
// Found in DeviceAuditModal.vue, HistoricalBackfillModal.vue, AccessLogsHistory.vue,
// DeviceManager.vue, LiveTelemetry.vue, PersonnelManager.vue, StrangerSnapsMonitor.vue, SyncTasksMonitor.vue
import axios from 'axios';
```
**Identified Deficiencies**:
1. **Lack of Base Configuration**: Every call writes relative URLs (e.g., `axios.get('/api/access-logs')`).
2. **Missing Authentication Headers**: No automatic injection of `Authorization: Bearer <token>` or session cookies.
3. **No Centralized Error Interception**:
   - `401 Unauthorized`: No auto-logout or redirect to `/login`.
   - `403 Forbidden`: No generic permission notification.
   - `422 Unprocessable Content`: Validation errors must be parsed manually in each form.
   - `500 Internal Server Error`: No unified toast alert.

### 3.3 Proposed Centralized API Client Architecture

A centralized API service should be placed in `resources/js/api/client.js`:

```javascript
import axios from 'axios';
import { notify } from '../utils/notify';

const apiClient = axios.create({
    baseURL: '/api',
    headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
    withCredentials: true, // For Laravel session / Sanctum CSRF cookies
});

// Request interceptor: attach Sanctum Bearer token if present
apiClient.interceptors.request.use((config) => {
    const token = localStorage.getItem('auth_token');
    if (token) {
        config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
}, (error) => Promise.reject(error));

// Response interceptor: global error handling
apiClient.interceptors.response.use(
    (response) => response,
    (error) => {
        const { status, data } = error.response || {};

        if (status === 401) {
            localStorage.removeItem('auth_token');
            if (window.location.pathname !== '/login') {
                window.dispatchEvent(new CustomEvent('auth:unauthorized'));
            }
        } else if (status === 403) {
            notify.error('Access Denied', data?.message || 'You do not have permission to perform this action.');
        } else if (status === 422) {
            const firstError = Object.values(data?.errors || {})[0]?.[0];
            notify.error('Validation Error', firstError || data?.message || 'Please verify form inputs.');
        } else if (status >= 500) {
            notify.error('Server Error', data?.message || 'An unexpected backend error occurred.');
        }

        return Promise.reject(error);
    }
);

export default apiClient;
```

Modular domain API services will wrap `apiClient`:
- `authApi.js`: `login()`, `logout()`, `getUser()`, `updateProfile()`, `changePassword()`
- `employeeApi.js`: `list()`, `get(id)`, `create(data)`, `update(id, data)`, `delete(id)`, `importCsv(file)`
- `shiftApi.js`: `listShifts()`, `saveShift(data)`, `assignShift(payload)`, `getHolidays()`, `saveHoliday(data)`
- `attendanceApi.js`: `getDaily(date, params)`, `getSummary(params)`, `getPunches(id, date)`, `manualEntry(payload)`, `overrideStatus(id, payload)`
- `leaveApi.js`: `listRequests(params)`, `submitRequest(data)`, `approve(id)`, `reject(id, reason)`, `getBalances(employeeId)`
- `visitorApi.js`: `listVisits(params)`, `checkIn(data)`, `checkOut(id, notes)`, `preRegister(data)`, `getExpected(date)`
- `reportApi.js`: `generate(type, params)`, `exportUrl(type, format, params)`

### 3.4 Target Pinia Store Architecture

```
resources/js/stores/
├── authStore.js          # User identity, roles, permissions, login/logout, tokens
├── attendanceStore.js    # Live punch telemetry, roster counters, who's in/out feed
├── visitorStore.js       # Expected arrivals, checked-in count, active badges
├── notificationStore.js  # Unread badge count, system alert queue, toast stream
└── cameraStore.js        # Device hardware fleet, RTSP/WebGL state, raw MQTT feeds
```

#### Detailed Store Specification: `authStore.js`
```javascript
export const useAuthStore = defineStore('auth', {
    state: () => ({
        user: null,
        token: localStorage.getItem('auth_token') || null,
        roles: [],
        permissions: [],
        loading: false,
    }),
    getters: {
        isAuthenticated: (state) => !!state.token && !!state.user,
        hasRole: (state) => (role) => state.roles.includes('super-admin') || state.roles.includes(role),
        hasPermission: (state) => (permission) => 
            state.roles.includes('super-admin') || state.permissions.includes(permission),
        isSuperAdmin: (state) => state.roles.includes('super-admin'),
        isHrManager: (state) => state.roles.includes('super-admin') || state.roles.includes('hr-manager'),
        isReceptionist: (state) => state.roles.includes('super-admin') || state.roles.includes('receptionist'),
        isSecurity: (state) => state.roles.includes('super-admin') || state.roles.includes('security'),
        isEmployee: (state) => state.roles.includes('employee'),
    },
    actions: {
        async login(credentials) { ... },
        async fetchCurrentUser() { ... },
        async logout() { ... },
    }
});
```

#### Detailed Store Specification: `attendanceStore.js`
```javascript
export const useAttendanceStore = defineStore('attendance', {
    state: () => ({
        todayRoster: [],
        livePunches: [],
        stats: {
            total_employees: 0,
            present_today: 0,
            absent_today: 0,
            late_today: 0,
            on_leave_today: 0,
        },
        whosIn: [],
        whosOut: [],
    }),
    actions: {
        async fetchTodayStats() { ... },
        async fetchDailyRoster(date, departmentId) { ... },
        addRealTimePunch(punch) {
            this.livePunches.unshift(punch);
            if (this.livePunches.length > 50) this.livePunches.pop();
            this.updateRosterWithPunch(punch);
        },
        updateRosterWithPunch(punch) { ... }
    }
});
```

---

## 4. WebSocket & Real-Time Broadcast Integration

### 4.1 Existing Reverb Configuration (`echo.js`)

In `resources/js/echo.js`:
- Connects using `laravel-echo` with broadcaster `'reverb'`.
- Configuration binds to `window.location.hostname` or fallback `localhost:8080`.
- Supports TLS enforcement via `import.meta.env.VITE_REVERB_SCHEME`.
- Currently listens to 3 public channels in `App.vue`:
  1. `access-logs` &rarr; `.AccessLogReceived`
  2. `stranger-snaps` &rarr; `.StrangerSnapReceived`
  3. `device-status` &rarr; `.DeviceStatusUpdated`

### 4.2 Expanding Channels for Attendance & Visitors

To support real-time attendance and visitor synchronization without reloading pages, the following channels and events will be integrated:

| Channel Type | Channel Name | Broadcast Event Class | Frontend Action |
| :--- | :--- | :--- | :--- |
| **Public** | `attendance` | `AttendancePunchReceived` | Dispatches punch to `attendanceStore.addRealTimePunch()`; updates "Who's In / Who's Out" card; triggers optional clock-in toast. |
| **Public** | `attendance` | `AttendanceStatusUpdated` | Updates roster status badge (e.g. status finalized from missing punch to absent or regularized). |
| **Public** | `visitors` | `VisitorCheckedIn` | Updates `visitorStore`; adds visitor to active on-site table; increments Checked In KPI counter. |
| **Public** | `visitors` | `VisitorCheckedOut` | Removes visitor from active on-site table; increments Checked Out KPI counter. |
| **Private** | `private-user.{id}` | `VisitorArrivedNotification` | Pops alert toast and adds notification: *"Your visitor [Name] has arrived at Reception."* |
| **Private** | `private-user.{id}` | `LeaveRequestStatusUpdated` | Notifies employee: *"Your leave request for [Date] has been [Approved/Rejected]."* |
| **Private** | `private-user.{id}` | `RegularizationApproved` | Notifies employee of punch correction approval. |

### 4.3 Echo Private Channel Authentication Setup

For private channels (`private-user.{id}`), Echo requires an authenticated handshake. `echo.js` must be enhanced to forward credentials:

```javascript
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

const echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY || 'camera_hub_key',
    wsHost: window.location.hostname || import.meta.env.VITE_REVERB_HOST || 'localhost',
    wsPort: import.meta.env.VITE_REVERB_PORT ? parseInt(import.meta.env.VITE_REVERB_PORT) : 8080,
    wssPort: import.meta.env.VITE_REVERB_PORT ? parseInt(import.meta.env.VITE_REVERB_PORT) : 8080,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https',
    enabledTransports: ['ws', 'wss'],
    authEndpoint: '/api/broadcasting/auth',
    auth: {
        headers: {
            Authorization: `Bearer ${localStorage.getItem('auth_token') || ''}`,
            Accept: 'application/json',
        }
    }
});

export default echo;
```

---

## 5. Main Application Shell (`App.vue`) & Layout Architecture

### 5.1 Existing Shell Limitations

In the existing `resources/js/App.vue`:
1. **Single Flat Tab Bar**: 6 tabs are arranged horizontally. Cramming 14+ modules into one horizontal line will break on desktop screens <1440px and cause heavy horizontal scroll fatigue.
2. **Fixed Camera-Centric KPI Banner**: The 5 cards at the top (`Scans Today`, `Alerts & Strangers`, `Active Cameras`, `Enrolled Face Lib`, `Sync Outbox`) consume 150px of vertical height and are irrelevant when an HR manager is reviewing leave requests or an employee is viewing their timesheet.
3. **No User Identity Context**: The header lacks user login info, profile avatar, role indicators, and logout actions.
4. **No Route History / Deep Linking**: Everything relies on `currentTab = ref('live')`. Refreshing the browser or sharing a URL resets the user to `'live'`, precluding direct linking to a specific employee or report.

### 5.2 Layout Transformation Strategy

To accommodate both administrative depth and role-based simplicity, the navigation structure will implement a **Two-Tier Categorized Shell** (or Sidebar Layout) with role filtering:

```
┌────────────────────────────────────────────────────────────────────────────────────────┐
│ HEADER: [Logo] AI Camera Hub & Attendance  │  [🔔 3]  [👤 Jane Doe (HR Manager) ▼] [Logout]│
├────────────────────────────────────────────────────────────────────────────────────────┤
│ CATEGORY NAVIGATION:                                                                   │
│ [📊 Attendance & HR]    [🏢 Visitors]    [📹 Vision & Security]    [📈 Reports & Admin] │
├────────────────────────────────────────────────────────────────────────────────────────┤
│ SUB-MODULE PILLS (e.g. for Attendance & HR):                                            │
│ [Dashboard]  [Daily Roster]  [Employees]  [Schedules]  [Leaves]  [Self-Service]         │
├────────────────────────────────────────────────────────────────────────────────────────┤
│ CONTEXTUAL KPI BAR (Dynamic based on selected Category):                               │
│ [Present: 142]   [Absent: 8]   [Late: 5]   [On Leave: 3]   [Total Fleet: 12 Online]    │
├────────────────────────────────────────────────────────────────────────────────────────┤
│ VIEWPORT CONTENT (Active Module Component):                                            │
│                                                                                        │
│                                                                                        │
│                                                                                        │
└────────────────────────────────────────────────────────────────────────────────────────┘
```

#### Category Breakdown:
1. **📊 Attendance & HR**:
   - `AttendanceDashboard.vue` (Live "Who's In / Who's Out", late arrivals)
   - `DailyAttendanceRoster.vue` (Daily timesheet, punch inspector, manual overrides)
   - `EmployeeDirectory.vue` (Employee records, organization hierarchy, profiles)
   - `ShiftManager.vue` (Shift schedules, overnight rules, holiday calendar)
   - `LeaveManagement.vue` (Leave requests, balances, manager approval queue)
   - `EmployeePortal.vue` (Employee self-service, punch logs, regularization)
2. **🏢 Visitors**:
   - `VisitorDashboard.vue` (Expected today, checked-in on-site, overdue alerts)
   - `VisitorCheckInWizard.vue` (5-step registration, webcam photo, badge issue)
   - `VisitorDirectory.vue` (Historical logs, frequency, duration metrics)
   - `WatchlistManager.vue` (Blocked persons, security alerts)
3. **📹 Vision & Security (Existing Fleet Platform)**:
   - `LiveTelemetry.vue` (Real-time biometric verifications stream)
   - `StrangerSnapsMonitor.vue` (Stranger gallery & fast enrollment)
   - `DeviceManager.vue` (Camera fleet, WebGL preview, diagnostics)
   - `PersonnelManager.vue` (Biometric face library & schedule sync)
   - `AccessLogsHistory.vue` (Full historical audit logs)
   - `SyncTasksMonitor.vue` (Redis queue outbox state)
4. **📈 Reports & Admin**:
   - `ReportsHub.vue` (Daily/monthly attendance, tardiness, visitor logs, payroll CSV)
   - `SystemSettings.vue` (Attendance thresholds, visitor policies, notification settings)
   - `AuditLogViewer.vue` (System activity audit trail)
   - `RolePermissionManager.vue` (RBAC management)

### 5.3 Routing Strategy: Router vs. Tab Navigation

- **Recommendation**:
  - Keep the application responsive and clean by using **`vue-router` v4** with a catch-all route in Laravel (`routes/web.php`):
    ```php
    Route::get('/{any}', function () {
        return view('welcome');
    })->where('any', '^(?!api|action|Subscribe|storage|bore).*$');
    ```
  - `vue-router` enables:
    - Dedicated clean `/login` route that completely replaces the shell.
    - Deep linking to `/attendance/roster?date=2026-09-29` or `/employees/12`.
    - Navigation guards (`router.beforeEach`) checking `authStore.isAuthenticated` and required permissions.
    - History back/forward buttons working naturally.
  - If `vue-router` is omitted during early phases, the **Stateful Tab Shell** can be structured with:
    ```vue
    <template>
      <LoginPage v-if="!authStore.isAuthenticated" />
      <div v-else class="min-h-screen bg-slate-50 flex flex-col">
        <!-- Main Shell with Category Navigation -->
      </div>
    </template>
    ```

---

## 6. Detailed Module-by-Module Frontend Implementation Blueprint

### 6.1 Module 1: Authentication & Role-Based Access Control (Phase 1)

#### Target File Locations:
- `resources/js/views/auth/LoginPage.vue`
- `resources/js/components/auth/UserProfileModal.vue`
- `resources/js/components/auth/RolePermissionManager.vue`
- `resources/js/stores/authStore.js`

#### UI Specifications & Design:
- **`LoginPage.vue`**:
  - Centered card layout on soft gradient canvas (`bg-gradient-to-br from-slate-900 via-indigo-950 to-slate-900`).
  - Company branding (`pinnacle-logo-light.svg`) with title *"Intelligent Attendance & Visitor Management"*.
  - Form fields: Email input with icon, password input with toggle show/hide eye icon, "Remember Me" checkbox.
  - Quick-switch demo account buttons for fast development testing (Super-Admin, HR Manager, Receptionist, Security, Employee).
  - Validation error alert with shake animation.
- **Permission Directive / Helper**:
  - Global helper `v-permission="'attendance.manage'"` or `@can('visitors.checkin')` to conditionally hide buttons, actions, and tabs.

### 6.2 Module 2: Employee Management (Phase 2)

#### Target File Locations:
- `resources/js/views/employees/EmployeeDirectory.vue`
- `resources/js/components/employees/EmployeeFormModal.vue`
- `resources/js/components/employees/EmployeeProfileModal.vue`
- `resources/js/components/employees/DepartmentManagerModal.vue`

#### Key Capabilities:
- **`EmployeeDirectory.vue`**:
  - Data table with toggleable Card/Grid view.
  - Filter bar: Search (name, employee code, email), Department dropdown, Designation dropdown, Status filter (`Active`, `On Leave`, `Suspended`, `Terminated`).
  - Actions: "➕ Add Employee", "📥 Import CSV/Excel", "📤 Export Directory".
  - Columns: Avatar, Employee Code, Name, Department & Designation, Default Shift, Status Badge, Linked Biometric Face Badge (`Synced`, `Pending`, `No Face`), Actions (Profile, Edit, Delete).
- **`EmployeeFormModal.vue`**:
  - Tab 1: **Personal Info** (First/Last Name, Gender, Birthday, Personal Email, Phone, Address, Emergency Contact).
  - Tab 2: **Employment Details** (Employee Code, Department, Designation, Location, Reporting Manager dropdown, Employment Type [Full-time/Part-time/Contract], Date of Joining, Assigned Shift).
  - Tab 3: **Biometrics & Hardware Provisioning**:
    - Upload face photo or trigger live webcam capture.
    - Real-time preview with face crop guides.
    - Checkbox: *"Automatically sync biometric face to all active entry/exit cameras"*.

### 6.3 Module 3: Shifts & Schedule Management (Phase 3)

#### Target File Locations:
- `resources/js/views/schedules/ShiftManager.vue`
- `resources/js/views/schedules/ShiftAssignment.vue`
- `resources/js/views/schedules/HolidayCalendar.vue`

#### Key Capabilities:
- **`ShiftManager.vue`**:
  - Visual cards for shift templates with color tags (e.g., Morning Shift: 08:00 - 17:00, Night Shift: 22:00 - 07:00).
  - Shift Form Modal:
    - Name, Code, Color picker.
    - Time pickers: Start Time, End Time.
    - Overnight shift checkbox (`is_overnight`: spans past midnight).
    - Thresholds: Grace period minutes (e.g. 15 min), Early-out threshold minutes (e.g. 30 min), Minimum hours for full-day (e.g. 8h), Half-day threshold hours (e.g. 4h).
    - Break duration minutes (deducted from total calculation).
- **`ShiftAssignment.vue`**:
  - Department roster calendar matrix.
  - Bulk assignment: Select employees &rarr; Assign shift &rarr; Date range &rarr; Assigned working days (Mon-Fri checkboxes).
- **`HolidayCalendar.vue`**:
  - Monthly calendar showing public and company holidays.
  - Add Holiday modal: Name, Date, Type (`Public`, `Company`, `Optional`), Annual recurrence toggle.

### 6.4 Module 4: Biometric Attendance Processing & Live Roster (Phase 4)

#### Target File Locations:
- `resources/js/views/attendance/AttendanceDashboard.vue`
- `resources/js/views/attendance/DailyAttendanceRoster.vue`
- `resources/js/views/attendance/EmployeeAttendanceCalendar.vue`
- `resources/js/components/attendance/PunchLogModal.vue`
- `resources/js/components/attendance/ManualAttendanceModal.vue`
- `resources/js/stores/attendanceStore.js`

#### Key Capabilities:
- **`AttendanceDashboard.vue`**:
  - Real-time KPI summary:
    - `Total Active Employees`
    - `Present Today` (green)
    - `Absent Today` (red)
    - `Late Arrivals` (amber)
    - `On Leave` (blue)
  - **Live "Who's In / Who's Out" split view**:
    - Left column: Checked-In Employees (Photo, Name, Department, Clock-In Time, On-Time/Late pill).
    - Right column: Not Clocked-In / Expected Employees (Photo, Name, Expected Start Time, Status).
  - Real-time late arrivals ticker showing recent tardy punches as they arrive via WebSocket.
- **`DailyAttendanceRoster.vue`**:
  - Date navigation: Date picker with "Previous Day", "Today", "Next Day" shortcuts.
  - Department & Status filters (`All`, `Present`, `Late`, `Absent`, `Early Out`, `On Leave`).
  - Table columns:
    - Employee (Photo, Name, Code)
    - Department & Designation
    - Assigned Shift
    - First In (Time + Camera Name)
    - Last Out (Time + Camera Name)
    - Work Hours & Overtime Hours
    - Status Badge (`Present`, `Late (18m)`, `Half Day`, `On Leave`, `Absent`)
    - Source Pill (`Camera Auto`, `HR Override`, `Regularized`)
    - Actions: "Inspect Punches", "Manual Override"
- **`PunchLogModal.vue`**:
  - Visual timeline displaying all raw verification scans for that employee on that date, showing camera name, direction (`in`/`out`), similarity score, and captured face thumbnail.
- **`ManualAttendanceModal.vue`**:
  - HR tool to manually add or adjust clock-in / clock-out times with mandatory audit reason notes.

### 6.5 Module 5: Leave Management (Phase 5)

#### Target File Locations:
- `resources/js/views/leaves/LeaveManagement.vue`
- `resources/js/components/leaves/LeaveRequestModal.vue`
- `resources/js/components/leaves/LeaveBalanceWidget.vue`

#### Key Capabilities:
- **`LeaveManagement.vue`**:
  - Tab 1: **Pending Approvals Queue** (for Managers & HR):
    - Table of pending requests: Employee, Leave Type, Dates, Total Days, Reason, Attachment link.
    - One-click "Approve" (with confirmation) and "Reject" (prompts for rejection reason).
  - Tab 2: **All Leave Requests**: Full historical table with date/status filters.
  - Tab 3: **Leave Balances**: Overview of every employee's allocated, used, and remaining days per leave type.
  - Tab 4: **Team Leave Calendar**: Heatmap/timeline of upcoming leaves.
- **`LeaveRequestModal.vue`**:
  - Leave Type dropdown (Vacation, Sick, Emergency, Maternity, etc.).
  - Date range picker.
  - Half-day checkbox (`First Half` / `Second Half`).
  - Auto-calculated total days.
  - Reason text area and file attachment upload (e.g. medical certificate).

### 6.6 Module 6: Comprehensive Visitor Management (Phase 6)

#### Target File Locations:
- `resources/js/views/visitors/VisitorDashboard.vue`
- `resources/js/views/visitors/VisitorDirectory.vue`
- `resources/js/views/visitors/WatchlistManager.vue`
- `resources/js/components/visitors/VisitorCheckInWizard.vue`
- `resources/js/components/visitors/VisitorCheckOutModal.vue`
- `resources/js/components/visitors/VisitorBadge.vue`
- `resources/js/stores/visitorStore.js`

#### Key Capabilities:
- **`VisitorDashboard.vue`**:
  - Metrics: `Expected Today`, `Currently On-Site`, `Checked Out Today`, `Overdue Visits`.
  - Action buttons: `"➕ New Walk-In Check-In"`, `"📋 Pre-Register Visitor"`.
  - Real-time Active Visitors Table: Photo, Name, Company, Host Employee, Purpose, Badge #, Check-In Time, On-Site Duration (live timer).
  - Quick action: "Check-Out" button next to each active visitor.
- **`VisitorCheckInWizard.vue` (5-Step Stepper)**:
  - **Step 1: Visitor Identity**:
    - Auto-complete search for returning visitors (by phone, name, or email).
    - If new: First/Last Name, Phone, Email, Company, ID Type (Driver's License, Passport, National ID), ID Number.
    - Real-time watchlist verification (blocks check-in if visitor is on the blacklist).
  - **Step 2: Visit Purpose & Host**:
    - Host Employee search dropdown.
    - Purpose selector (`Meeting`, `Interview`, `Delivery`, `Contractor`, `Maintenance`, `VIP`).
    - Remarks, vehicle plate number, items brought on-site (laptops/tools).
  - **Step 3: Biometric Face Capture**:
    - Webcam interface with snapshot capture button or image file upload.
    - Preview with automatic face framing.
    - Toggle: *"Provision temporary face credential to entrance cameras for the visit duration"*.
  - **Step 4: NDA & Digital Acknowledgment**:
    - Visitor policy/NDA agreement text.
    - Digital signature pad or acknowledgment checkbox.
  - **Step 5: Badge Assignment & Completion**:
    - Assign badge number (or scan RFID card / print paper badge).
    - Confirmation summary & Print Badge preview (`VisitorBadge.vue`).
- **`VisitorBadge.vue`**:
  - Print-optimized CSS (`@media print`) card with organization logo, visitor photo, visitor name, company, host employee name, date of visit, badge number, and QR code for rapid barcode checkout.

### 6.7 Module 7: Notifications & Alerts Engine (Phase 7)

#### Target File Locations:
- `resources/js/components/notifications/NotificationBell.vue`
- `resources/js/views/notifications/NotificationsPage.vue`
- `resources/js/stores/notificationStore.js`

#### Key Capabilities:
- **`NotificationBell.vue`**:
  - Integrated into top right header.
  - Bell icon with reactive unread badge counter (`bg-rose-500 text-white animate-pulse`).
  - Dropdown panel showing the 5 most recent notifications with relative timestamps ("2m ago", "1h ago").
  - Click-through to view details or "Mark all as read".
- Alert triggers:
  - Visitor arrived at reception (alerts host).
  - Leave request submitted / approved / rejected.
  - Attendance regularization requests.
  - Stranger alert / Blacklist detection on cameras.

### 6.8 Module 8: Reporting, Analytics & Payroll Exports (Phase 8)

#### Target File Locations:
- `resources/js/views/reports/ReportsHub.vue`
- `resources/js/components/reports/AttendanceTrendChart.vue`
- `resources/js/components/reports/DepartmentComparisonChart.vue`

#### Key Capabilities:
- Report Types:
  1. **Daily Attendance Summary** (Present/Absent/Late rates by department).
  2. **Monthly Employee Timesheet** (Comprehensive 31-day grid per employee).
  3. **Tardiness & Late Arrivals Report** (Roster of late incidents with minutes lost).
  4. **Overtime Report** (Accrued overtime hours per employee/department).
  5. **Visitor Traffic & Duration Report** (Visitor counts, average stay duration, host metrics).
  6. **Payroll Export File** (CSV format tailored with Employee Code, Regular Days, Overtime Hours, Late Minutes, Unpaid Leave Days).
- Filter Bar: Date Range (presets: Today, Yesterday, This Week, This Month, Last Month, Custom Range), Department selector, Employee search.
- Export Buttons:
  - `📥 Export CSV` (Instant browser download via backend streaming response).
  - `📥 Export Excel (.xlsx)`
  - `🖨️ Export PDF` (Print-ready formatted document).

### 6.9 Module 9: Employee Self-Service Portal (Phase 9)

#### Target File Locations:
- `resources/js/views/selfservice/EmployeePortal.vue`
- `resources/js/components/selfservice/RegularizationRequestModal.vue`

#### Key Capabilities:
- When an employee logs in, they land on a streamlined self-service interface:
  - **Today's Status Card**: Current clock-in time, working hours accrued, assigned shift today.
  - **Monthly Attendance Calendar**: Visual calendar highlighting own on-time, late, absent, and leave days.
  - **My Punch Log**: Expandable list of all daily biometric scans.
  - **Request Punch Regularization**: Form to request correction for a missed scan (Date, Requested Time In/Out, Reason, Attachment).
  - **My Leave Balances Card**: Remaining Vacation, Sick, and Emergency days.
  - **Apply for Leave Button**: Launches `LeaveRequestModal.vue`.

### 6.10 Module 10: System Settings & Audit Trail (Phase 10)

#### Target File Locations:
- `resources/js/views/settings/SystemSettings.vue`
- `resources/js/views/settings/AuditLogViewer.vue`

#### Key Capabilities:
- **`SystemSettings.vue`**:
  - Organized into distinct configuration tabs:
    - **General**: Company Name, Timezone (default `Asia/Manila`), Logo upload.
    - **Attendance Engine**: Default grace period minutes, overtime qualification threshold, half-day hour threshold, weekend definition (e.g. Saturday/Sunday), scheduled auto-finalizer execution time (e.g. 23:59).
    - **Visitor Rules**: Photo requirement toggle, NDA requirement toggle, auto-expire active visits time, auto-enroll face to cameras toggle.
    - **Notifications**: Email SMTP toggle, SMS gateway config, WebSocket alerts.
- **`AuditLogViewer.vue`**:
  - Filterable log of all administrative actions (User, Action, Model Affected, IP Address, Timestamp, JSON diff of changes).

### 6.11 Module 11: Device Enhancements for Attendance (Phase 11)

#### Enhancements to `resources/js/views/DeviceManager.vue`:
- Add **Device Role / Direction** selector in the Camera Configuration Modal:
  - Direction: `Entry Only`, `Exit Only`, `Bidirectional (Alternating In/Out)`, `Visitor Kiosk`.
  - Location / Site assignment dropdown (e.g. Main Lobby Gate, Service Entrance, 2nd Floor Office).
  - Department scoping (optional tag for cameras dedicated to specific teams).
- Live preview modal and hardware operation controls preserved without regression.

---

## 7. UI Component Design System & Consistency Guidelines

### 7.1 Component Design Tokens

| Element | Tailwind Classes / Tokens | Examples |
| :--- | :--- | :--- |
| **Page Canvas** | `min-h-screen bg-slate-50 text-slate-900 font-sans antialiased` | Root container |
| **Card Surface** | `bg-white border border-slate-200/80 rounded-xl p-5 shadow-xs` | KPI cards, data containers |
| **Interactive Card**| `hover:shadow-md hover:border-slate-300 transition-all cursor-pointer` | Device cards, shift cards |
| **Primary Button** | `px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-lg shadow-sm shadow-indigo-600/20 transition-all cursor-pointer disabled:opacity-50` | Enroll, Save, Submit |
| **Secondary Button**| `px-4 py-2 bg-white hover:bg-slate-50 text-slate-700 text-xs font-medium border border-slate-200 rounded-lg shadow-xs transition-colors cursor-pointer` | Cancel, Filter, Refresh |
| **Destructive Button**| `px-4 py-2 bg-rose-50 hover:bg-rose-100 text-rose-700 text-xs font-semibold border border-rose-200 rounded-lg transition-colors cursor-pointer` | Delete, Revoke, Block |
| **Input Field** | `w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs` | Text, Number, Date, Time |
| **Select Dropdown** | `bg-white border border-slate-200 text-xs rounded-lg px-3 py-2 text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs cursor-pointer` | Filters, dropdown forms |
| **Table Container**| `bg-white border border-slate-200 rounded-xl overflow-hidden shadow-xs` | Data tables wrapper |
| **Table Header** | `bg-slate-50 text-slate-500 uppercase text-[11px] font-semibold border-b border-slate-200 py-3 px-4` | Column titles |
| **Table Row** | `hover:bg-slate-50 transition-colors border-b border-slate-100 py-3 px-4 text-xs text-slate-700` | Data rows |
| **Modal Backdrop** | `fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4` | Overlay |
| **Modal Container**| `bg-white rounded-2xl border border-slate-200 max-w-2xl w-full p-6 shadow-2xl max-h-[90vh] overflow-y-auto` | Dialog box |

### 7.2 Status Badges Palette

```
- Present / Whitelist / Online / Approved:
  class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-emerald-50 text-emerald-700 border border-emerald-200"

- Late / Warning / Temporary / Expected:
  class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-amber-50 text-amber-700 border border-amber-200"

- Absent / Rejected / Offline / Overdue / Blocked:
  class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-rose-50 text-rose-700 border border-rose-200"

- On Leave / Neutral Info / Department:
  class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-indigo-50 text-indigo-700 border border-indigo-200"

- Rest Day / Holiday / Cancelled:
  class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-slate-100 text-slate-600 border border-slate-200"
```

---

## 8. Performance, Code Splitting & Build Strategy

### 8.1 Current Static Import Bottleneck

Currently, `App.vue` imports all views synchronously:
```javascript
import LiveTelemetry from "./views/LiveTelemetry.vue";
import StrangerSnapsMonitor from "./views/StrangerSnapsMonitor.vue";
import PersonnelManager from "./views/PersonnelManager.vue";
import DeviceManager from "./views/DeviceManager.vue";
import AccessLogsHistory.vue from "./views/AccessLogsHistory.vue";
import SyncTasksMonitor from "./views/SyncTasksMonitor.vue";
```
As 10+ new views and modals are introduced, bundling them into a single monolithic JavaScript file will push the bundle size beyond 1.5MB.

### 8.2 Asynchronous Component Strategy

Implement asynchronous code splitting using Vue 3 `defineAsyncComponent`:

```javascript
import { defineAsyncComponent } from 'vue';

// Core default view loaded synchronously
import AttendanceDashboard from './views/attendance/AttendanceDashboard.vue';

// Heavy secondary modules loaded on demand
const EmployeeDirectory = defineAsyncComponent(() => import('./views/employees/EmployeeDirectory.vue'));
const ShiftManager = defineAsyncComponent(() => import('./views/schedules/ShiftManager.vue'));
const DailyAttendanceRoster = defineAsyncComponent(() => import('./views/attendance/DailyAttendanceRoster.vue'));
const LeaveManagement = defineAsyncComponent(() => import('./views/leaves/LeaveManagement.vue'));
const VisitorDashboard = defineAsyncComponent(() => import('./views/visitors/VisitorDashboard.vue'));
const ReportsHub = defineAsyncComponent(() => import('./views/reports/ReportsHub.vue'));
const DeviceManager = defineAsyncComponent(() => import('./views/DeviceManager.vue'));
const LiveTelemetry = defineAsyncComponent(() => import('./views/LiveTelemetry.vue'));
const SystemSettings = defineAsyncComponent(() => import('./views/settings/SystemSettings.vue'));
```

**Benefits**:
- Generates separate chunks in `public/build/assets/` during `npm run build`.
- Initial page payload drops significantly (under 150kB gzipped).
- The heavy WebGL video player (`cameraHqPlayer.js`) is only downloaded when a user opens the Camera Devices view.

---

## 9. Phased Frontend Implementation Roadmap

```
┌──────────────────────────────────────────────────────────────────────────────────┐
│ PHASE 1: Authentication, RBAC & API Client Layer                                 │
│ - Create api/client.js with Bearer token & CSRF interceptors                     │
│ - Build authStore.js (login, logout, RBAC getters)                               │
│ - Build LoginPage.vue and wire up auth gate in App.vue                           │
├──────────────────────────────────────────────────────────────────────────────────┤
│ PHASE 2: Employee Management Directory                                           │
│ - Build EmployeeDirectory.vue (table, filters, search, CSV import)               │
│ - Build EmployeeFormModal.vue (multi-tab: info, job, face enrollment)            │
│ - Build EmployeeProfileModal.vue                                                 │
├──────────────────────────────────────────────────────────────────────────────────┤
│ PHASE 3: Shift & Schedule Management                                             │
│ - Build ShiftManager.vue (shift definitions, overnight & grace period forms)     │
│ - Build ShiftAssignment.vue (department shift calendar)                          │
│ - Build HolidayCalendar.vue (visual holiday calendar)                            │
├──────────────────────────────────────────────────────────────────────────────────┤
│ PHASE 4: Attendance Processing Dashboard & Roster                                │
│ - Build attendanceStore.js with Echo 'attendance' channel listener               │
│ - Build AttendanceDashboard.vue ("Who's In / Who's Out" split view)              │
│ - Build DailyAttendanceRoster.vue with punch inspector & manual override modal   │
│ - Build EmployeeAttendanceCalendar.vue                                           │
├──────────────────────────────────────────────────────────────────────────────────┤
│ PHASE 5: Leave Management & Approval Queues                                      │
│ - Build LeaveManagement.vue (approval queue, requests history, balances)         │
│ - Build LeaveRequestModal.vue (date calculation, half-days, attachments)         │
├──────────────────────────────────────────────────────────────────────────────────┤
│ PHASE 6: Visitor Management System                                               │
│ - Build visitorStore.js with Echo 'visitors' channel listener                    │
│ - Build VisitorDashboard.vue (active on-site table, live duration ticker)        │
│ - Build VisitorCheckInWizard.vue (5-step registration, webcam face capture)      │
│ - Build VisitorBadge.vue (printable pass) & WatchlistManager.vue                 │
├──────────────────────────────────────────────────────────────────────────────────┤
│ PHASE 7: Notifications & Alerts Engine                                           │
│ - Build NotificationBell.vue header dropdown & unread badge                      │
│ - Setup private-user WebSocket listeners in Echo                                 │
├──────────────────────────────────────────────────────────────────────────────────┤
│ PHASE 8: Reporting, Analytics & Exports                                          │
│ - Build ReportsHub.vue (date presets, filters, preview tables)                   │
│ - Wire up CSV, PDF, and Payroll export buttons                                   │
├──────────────────────────────────────────────────────────────────────────────────┤
│ PHASE 9: Employee Self-Service Portal                                            │
│ - Build EmployeePortal.vue (timesheet, regularization form, balance widget)      │
├──────────────────────────────────────────────────────────────────────────────────┤
│ PHASE 10: System Settings & Audit Trail                                          │
│ - Build SystemSettings.vue (attendance thresholds, visitor policies)             │
│ - Build AuditLogViewer.vue                                                       │
├──────────────────────────────────────────────────────────────────────────────────┤
│ PHASE 11: Camera Device Direction & Kiosk Enhancements                           │
│ - Enhance DeviceManager.vue with Direction (in/out/bidirectional) & location     │
└──────────────────────────────────────────────────────────────────────────────────┘
```

---

## 10. Conclusion

The existing frontend foundation is exceptionally fast, modern, and well-structured, but strictly limited to camera fleet management. Transforming it into an Attendance and Visitor Management System requires establishing:
1. A centralized API client with automatic token and error handling (`api/client.js`).
2. Authentication and domain Pinia stores (`authStore`, `attendanceStore`, `visitorStore`, `notificationStore`).
3. An expanded application shell supporting multi-tiered navigation and contextual KPIs.
4. Modular asynchronous component loading to ensure `npm run build` maintains peak performance.

All proposed modules leverage the existing Tailwind CSS v4 design language, ensuring 100% visual consistency and enterprise reliability.
