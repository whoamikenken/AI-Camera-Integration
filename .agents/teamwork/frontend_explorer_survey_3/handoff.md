# Frontend Architecture Exploration — Handoff Report

## 1. Observation

- **Directory & File Structure**:
  - Main frontend entry point: `/home/wsk-devops2/AI-Camera-Integration/resources/js/app.js` mounts `App.vue` with Pinia (`app.use(pinia)`).
  - Main shell: `/home/wsk-devops2/AI-Camera-Integration/resources/js/App.vue` (297 lines) currently manages state with `currentTab = ref("live")` and switches between 6 views (`LiveTelemetry`, `StrangerSnapsMonitor`, `PersonnelManager`, `DeviceManager`, `AccessLogsHistory`, `SyncTasksMonitor`) using `v-if` / `v-else-if` (lines 183-188).
  - Web host template: `/home/wsk-devops2/AI-Camera-Integration/resources/views/welcome.blade.php` includes CSRF token meta and `@vite(['resources/css/app.css', 'resources/js/app.js'])`.
- **Dependencies (`package.json`)**:
  - Vue: `vue@^3.5.41`
  - Vite: `vite@^8.0.0` (active v8.2.2) with `@vitejs/plugin-vue@^6.0.8`
  - Tailwind CSS: `tailwindcss@^4.3.3` with `@tailwindcss/vite@^4.3.3`
  - State management: `pinia@^4.0.3`
  - HTTP client: `axios@^1.19.0`
  - WebSocket: `laravel-echo@^2.4.0` with `pusher-js@^8.6.0`
  - Icons & Feedback: `lucide-vue-next@^1.0.0` and `sweetalert2@^11.26.25`
  - Router: `vue-router` is **not present** in `package.json`.
- **Styling (`resources/css/app.css`)**:
  - Uses Tailwind CSS v4 `@import 'tailwindcss';` with `@theme` block defining `--font-sans: 'Instrument Sans'`.
  - No legacy `tailwind.config.js` exists in the project root.
- **WebSocket Configuration (`resources/js/echo.js`)**:
  - Configures Laravel Echo with broadcaster `'reverb'` connecting to `window.location.hostname` or port `8080`.
  - Subscribed channels in `App.vue`: `access-logs` (event `.AccessLogReceived`), `stranger-snaps` (event `.StrangerSnapReceived`), and `device-status` (event `.DeviceStatusUpdated`).
- **Data & API Calls**:
  - Raw `axios` is imported directly in 8 separate views and modals (e.g. `DeviceAuditModal.vue:300`, `HistoricalBackfillModal.vue:176`, `AccessLogsHistory.vue:154`, `DeviceManager.vue:627`, `LiveTelemetry.vue:205`, `PersonnelManager.vue:229`, `StrangerSnapsMonitor.vue:579`, `SyncTasksMonitor.vue:86`).
  - There is no central API client with base URL, token authorization headers, or 401/403/422 interceptors.
- **Build Verification**:
  - Command: `npm run build` executed cleanly with exit code 0 in 605ms:
    ```
    public/build/manifest.json                              0.83 kB │ gzip:   0.27 kB
    public/build/assets/pinnacle-icon-8b-r986u.svg          1.63 kB │ gzip:   0.60 kB
    public/build/assets/pinnacle-logo-light-BKV2MKVJ.svg    2.21 kB │ gzip:   0.85 kB
    public/build/assets/app-584n-SZH.css                    0.17 kB │ gzip:   0.14 kB
    public/build/assets/app-BPR2fmBj.css                   67.34 kB │ gzip:  11.45 kB
    public/build/assets/app-CuviFbgB.js                   452.77 kB │ gzip: 129.31 kB
    ✓ built in 605ms
    ```

## 2. Logic Chain

1. From **Observation 1 & 2**, the existing frontend is tightly scoped around camera hardware telemetry (6 views in `App.vue`), using static component imports and a single flat tab bar (`currentTab = ref('live')`).
2. Expanding the system to fulfill the user request and `tasks.md` (Authentication, Employees, Shifts & Schedules, Attendance Processing, Leaves, Visitors, Reports, Settings, Self-Service) will add at least 11 major functional views and over 15 modals/subcomponents.
3. Keeping all new views as static imports in `App.vue` would double or triple initial bundle size beyond 1.5MB and overcrowd the single horizontal navigation bar, violating responsive usability.
4. From **Observation 4**, every component directly calls raw `axios` without authorization headers or 401 handling. Because Phase 1 introduces Sanctum authentication and RBAC, continuing with decentralized axios calls would require manually adding headers in dozens of places and would fail to handle expired tokens or permission errors cleanly.
5. Therefore, a centralized API client (`resources/js/api/client.js`) and dedicated domain Pinia stores (`authStore`, `attendanceStore`, `visitorStore`, `notificationStore`) must precede or accompany the implementation of UI views.
6. Furthermore, Reverb integration in `echo.js` must be extended with an authenticated `authEndpoint` and channel listeners for `attendance` (`AttendancePunchReceived`), `visitors` (`VisitorCheckedIn`, `VisitorCheckedOut`), and private user notifications (`private-user.{id}`).
7. With asynchronous component loading (`defineAsyncComponent`), the build output can remain split into lightweight chunks, preserving the sub-second build and fast page load times confirmed in **Observation 5**.

## 3. Caveats

- **Vue Router vs. Stateful Shell**: Currently, `vue-router` is not installed. The system can either:
  1. Continue with an expanded two-tiered category tab switcher in `App.vue` (with an auth gate rendering `LoginPage.vue` when not authenticated), or
  2. Install `vue-router` 4.x and configure Laravel's catch-all route in `routes/web.php`.
  Both paths are viable and supported by Vite; installing `vue-router` offers clean URLs and navigation guards, while the stateful tab switcher requires zero new npm packages.
- **Webcam Access for Face Capture**: Visitor check-in (`VisitorCheckInWizard.vue`) and employee enrollment (`EmployeeFormModal.vue`) specify photo capture via webcam. In browser environments without HTTPS (or localhost), modern browsers block `navigator.mediaDevices.getUserMedia()`. Both webcam capture and standard file upload fallbacks must be supported.
- **Network / Mode**: Build testing was performed locally in development mode.

## 4. Conclusion

The frontend foundation is modern, clean, and ready for transformation. To systematically execute the phases defined in `tasks.md`:
1. **Foundation (Phase 1)**: Implement `resources/js/api/client.js` with Bearer auth and interceptors, create `authStore.js`, build `LoginPage.vue`, and wrap `App.vue` with an authentication gate.
2. **Navigation & Shell**: Upgrade `App.vue` into a two-tiered categorized shell (Attendance & HR, Visitors, Vision & Security, Reports & Admin) with contextual KPI cards and user profile header.
3. **Domain Stores**: Add `attendanceStore.js` and `visitorStore.js` hooked into Laravel Echo channels (`attendance`, `visitors`).
4. **View Modules**: Implement views and modals across Phases 2 through 11 using the established Tailwind CSS v4 design tokens (`slate-50`, `indigo-600`, `emerald-500`, `amber-500`, `rose-500`, `Instrument Sans` + `JetBrains Mono`) and load them asynchronously via `defineAsyncComponent`.

Detailed specifications, component mappings, and code architectures are fully documented in:
`/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/frontend_explorer_survey_3/survey_frontend_report.md`.

## 5. Verification Method

To independently verify all observations and conclusions:
1. **Verify Project Dependencies & Tailwind v4**:
   - Inspect `/home/wsk-devops2/AI-Camera-Integration/package.json`
   - Inspect `/home/wsk-devops2/AI-Camera-Integration/resources/css/app.css`
2. **Verify Frontend Build**:
   - Run `npm run build` in `/home/wsk-devops2/AI-Camera-Integration`. Ensure exit code 0 and review chunk output in `public/build/`.
3. **Verify API Invocations**:
   - Run ripgrep: `rg "import axios" resources/js` to confirm decentralized direct axios usage.
4. **Verify Main Shell & WebSocket Subscriptions**:
   - Inspect `/home/wsk-devops2/AI-Camera-Integration/resources/js/App.vue` lines 162-189 (navigation tabs and view rendering) and lines 256-267 (Echo subscriptions).
   - Inspect `/home/wsk-devops2/AI-Camera-Integration/resources/js/echo.js` for Reverb broadcaster setup.
5. **Verify Full Architecture Report**:
   - View `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/frontend_explorer_survey_3/survey_frontend_report.md`.
