# Milestone 5 Technical Investigation & Implementation Blueprint
**Tasks:** DASH-01, DASH-02, HUB-01, LVE-06  
**Investigating Agent:** `m5_explorer_1`  
**Target Milestone:** Milestone 5 — Attendance Dashboard & Sub-Hub Navigation  

---

## 1. Observation

Direct code inspections were conducted across all 7 target files, related Pinia stores, and build tools.

### 1.1 `resources/js/components/attendance/AttendanceDashboard.vue`
- **Lines 4–35 (KPI Metrics Grid):**
  Currently renders static KPI metric cards directly interpolating `attendanceStore.stats`:
  ```html
  4:         <!-- KPI Metrics Grid -->
  5:         <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
  6:             <div class="bg-white border border-slate-200/80 p-4 rounded-2xl shadow-xs">
  7:                 <div class="text-[11px] text-slate-500 uppercase font-bold tracking-wider">Total Scheduled</div>
  8:                 <div class="text-2xl font-bold text-slate-900 mt-1 font-mono">{{ attendanceStore.stats.total_employees }}</div>
  9:                 <div class="text-[10px] text-slate-400 mt-1">Total Headcount</div>
  10:            </div>
  ...
  34:             </div>
  35:         </div>
  ```
  There is no `v-if="attendanceStore.loading"` or skeleton loading placeholder. During asynchronous API fetching (`fetchDailyAttendance`), the grid renders default zeroes, causing Cumulative Layout Shift (CLS) when real stats arrive.
  Furthermore, `AttendanceDashboard.vue` does not trigger `fetchDailyAttendance()` in an `onMounted` lifecycle hook (it only defines `showManualModal` and `triggerFinalize` in `<script setup>`), relying on whether other tabs pre-fetched data.
- **Line 43 (Live Stream Pulse Indicator):**
  ```html
  42:                     <div class="flex items-center space-x-2">
  43:                         <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse"></span>
  44:                         <h3 class="font-bold text-slate-900 text-base">Live Attendance Clock-In Stream</h3>
  45:                     </div>
  ```
  The indicator uses `animate-pulse` without `motion-reduce:animate-none`, violating WCAG 2.3.3 / 2.2.2 for users who have requested reduced motion.

### 1.2 `resources/js/App.vue`
- **Lines 387–393 (AI Alerts Ping Indicator):**
  ```html
  387:                             <span
  388:                                 v-if="
  389:                                     store.stats.telemetry?.unresolved_alerts > 0
  390:                                 "
  391:                                 class="w-2 h-2 rounded-full bg-rose-500 animate-ping"
  392:                             ></span>
  ```
  The alert indicator uses `animate-ping` without `motion-reduce:animate-none`.
- Additional motion elements in `App.vue`:
  - Line 130: `class="px-1.5 py-0.5 rounded-full text-[10px] font-mono font-bold animate-pulse"`
  - Line 312: `class="bg-white border border-slate-200/80 rounded-2xl p-4 shadow-xs animate-pulse space-y-2"`

### 1.3 `resources/js/components/attendance/AttendanceHub.vue`
- **Lines 13–29 (Navigation Bar and Content Panels):**
  ```html
  13:             <div class="flex items-center bg-slate-100 p-1.5 rounded-xl border border-slate-200 overflow-x-auto">
  14:                 <button @click="activeSubTab = 'dashboard'"
  15:                     :class="activeSubTab === 'dashboard' ? 'bg-white text-indigo-600 shadow-xs font-bold' : 'text-slate-600 hover:text-slate-900'"
  16:                     class="px-4 py-2 rounded-lg transition-all text-xs cursor-pointer flex items-center gap-1.5 font-medium">
  17:                     <span>📈</span> Overview Dashboard
  18:                 </button>
  19:                 <button @click="activeSubTab = 'roster'"
  20:                     :class="activeSubTab === 'roster' ? 'bg-white text-indigo-600 shadow-xs font-bold' : 'text-slate-600 hover:text-slate-900'"
  21:                     class="px-4 py-2 rounded-lg transition-all text-xs cursor-pointer flex items-center gap-1.5 font-medium">
  22:                     <span>📋</span> Daily Roster &amp; Records
  23:                 </button>
  24:             </div>
  25:         </div>
  26: 
  27:         <!-- Sub Tabs Content -->
  28:         <AttendanceDashboard v-if="activeSubTab === 'dashboard'" />
  29:         <DailyAttendanceRoster v-else-if="activeSubTab === 'roster'" />
  ```
  Missing ARIA tabs pattern: no `role="tablist"`, no `flex-wrap` (has `overflow-x-auto` without wrapping), buttons lack `type="button"`, `role="tab"`, `aria-selected`, `aria-controls`, and `id`. Active view components are not wrapped in containers with `role="tabpanel"`, `id`, `aria-labelledby`, and `tabindex="0"`.

### 1.4 `resources/js/components/schedules/ScheduleHub.vue`
*(Note: Correct path in repository is `components/schedules/ScheduleHub.vue`, not `components/shifts/ScheduleHub.vue`)*
- **Lines 15–35 (Navigation Bar and Content Panels):**
  ```html
  15:       <div class="flex items-center bg-slate-100 p-1.5 rounded-xl border border-slate-200 overflow-x-auto">
  16:         <button
  17:           v-for="tab in tabs"
  18:           :key="tab.id"
  19:           type="button"
  20:           @click="activeTab = tab.id"
  21:           class="px-4 py-2 text-xs font-bold rounded-lg transition-all whitespace-nowrap cursor-pointer flex items-center gap-2"
  22:           :class="activeTab === tab.id ? 'bg-white text-indigo-600 shadow-xs' : 'text-slate-600 hover:text-slate-900'"
  23:         >
  ...
  31:     <div>
  32:       <ShiftManager v-if="activeTab === 'shifts'" />
  33:       <ShiftAssignment v-else-if="activeTab === 'assignments'" />
  34:       <HolidayCalendar v-else-if="activeTab === 'holidays'" />
  35:     </div>
  ```
  Missing `role="tablist"` and `flex-wrap`. Buttons lack `role="tab"`, `aria-selected`, `aria-controls`, and `id`. Sub-views lack `role="tabpanel"`, `aria-labelledby`, and `id`.

### 1.5 `resources/js/components/visitors/VisitorHub.vue`
- **Lines 11–27 (Navigation Bar and Content Panels):**
  ```html
  11:             <div class="flex items-center bg-slate-100 p-1.5 rounded-xl border border-slate-200 overflow-x-auto">
  12:                 <button @click="activeSubTab = 'dashboard'"
  ...
  17:                 <button @click="activeSubTab = 'watchlist'"
  ...
  24: 
  25:         <VisitorDashboard v-if="activeSubTab === 'dashboard'" />
  26:         <WatchlistManager v-else-if="activeSubTab === 'watchlist'" />
  ```
  Missing `role="tablist"`, `flex-wrap`, `role="tab"`, `aria-selected`, `aria-controls`, `id`, `role="tabpanel"`, and `aria-labelledby`.

### 1.6 `resources/js/components/settings/SettingsHub.vue`
- **Lines 4, 11–31 (Header, Navigation Bar, and Content Panels):**
  ```html
  4:     <div class="flex items-center justify-between bg-white border border-slate-200/80 p-4 rounded-xl shadow-xs">
  ...
  11:       <div class="flex items-center gap-1.5 bg-slate-100 p-1 rounded-xl border border-slate-200">
  12:         <button
  13:           v-for="tab in tabs"
  14:           :key="tab.id"
  15:           @click="activeTab = tab.id"
  ...
  26:     <div>
  27:       <DepartmentManager v-if="activeTab === 'departments'" />
  28:       <AccessGroupManager v-else-if="activeTab === 'access-groups'" />
  29:       <SystemSettings v-else-if="activeTab === 'system'" />
  30:       <AuditLogViewer v-else-if="activeTab === 'audit'" />
  31:     </div>
  ```
  Header bar does not wrap responsively on mobile (`flex flex-col sm:flex-row sm:items-center`). Pill bar lacks `role="tablist"` and `flex-wrap`. Buttons lack `type="button"`, `role="tab"`, `aria-selected`, `aria-controls`, and `id`. Panels lack `role="tabpanel"`, `aria-labelledby`, and `id`.

### 1.7 `resources/js/components/leave/LeaveCalendarView.vue`
- **Lines 10–13 (Calendar State):**
  ```html
  10:         <div v-if="approvedLeaves.length === 0" class="py-10 text-center text-slate-500 text-xs">
  11:             No approved leaves scheduled for this period.
  12:         </div>
  13:         <div v-else class="space-y-2.5">
  ```
  `LeaveCalendarView.vue` only checks `approvedLeaves.length === 0`. Because `leaveStore.leaveRequests` starts as an empty array `[]`, this causes a premature flash of "No approved leaves scheduled for this period." while `leaveStore.loading === true`.
  Also, if mounted independently, it does not fetch leaves on `onMounted`.

### 1.8 Build & Test Baseline
- `npm run build`: Succeeded with code 0 in 920ms.
- `php artisan test`: Succeeded with code 0 (577 passed, 48 skipped, 0 failures, 3072 assertions).

---

## 2. Logic Chain

1. **DASH-01 (KPI Skeletons & CLS Prevention):**
   - In `AttendanceDashboard.vue`, the KPI metric cards show 6 critical stats (`Total Scheduled`, `Present Today`, `Absent`, `Late Arrivals`, `On Leave`, `Early Out`).
   - During page load or date switching, `attendanceStore.fetchDailyAttendance()` sets `attendanceStore.loading = true`.
   - Without a skeleton loader, either empty content or `0` values appear abruptly before replacing with actual values, shifting card content and layout.
   - By creating a matching 6-card grid with `v-if="attendanceStore.loading"`, `aria-hidden="true"`, and `animate-pulse motion-reduce:animate-none` using the identical geometric footprint (`p-4 rounded-2xl border border-slate-200/80 shadow-xs`), layout shift is eliminated ($CLS = 0$).
   - Adding `onMounted` ensures `attendanceStore.fetchDailyAttendance()` is called when the component mounts if not already loading.

2. **DASH-02 (Motion Reduction):**
   - WCAG 2.3.3 (Animation from Interactions) and WCAG 2.2.2 (Pause, Stop, Hide) require that animated effects respect user preferences for reduced motion (`prefers-reduced-motion: reduce`).
   - Adding Tailwind's `motion-reduce:animate-none` modifier to `animate-pulse` in `AttendanceDashboard.vue:43` and `animate-ping` in `App.vue:391` immediately disables hardware/CSS animations when the user has reduced motion enabled in their OS or browser.

3. **HUB-01 (WAI-ARIA Tablist Pattern & Responsive Wrapping):**
   - Sub-hubs act as tabbed user interfaces displaying mutually exclusive views.
   - Screen readers and assistive technologies require ARIA tab semantics:
     - The container wrapping the tab buttons must have `role="tablist"` and a concise `aria-label`.
     - The container must include `flex-wrap` so tabs wrap gracefully onto multiple lines on small viewports without horizontal clipping.
     - Each interactive tab element must be a semantic `<button type="button">` with `role="tab"`, dynamic `:aria-selected="activeTab === '...' ? 'true' : 'false'"`, `:aria-controls="panelId"`, and unique `id="tabId"`.
     - Each active sub-view container must have `role="tabpanel"`, `id="panelId"`, `:aria-labelledby="tabId"`, and `tabindex="0"` to allow keyboard focus navigation.

4. **LVE-06 (Leave Calendar Loading State):**
   - In `LeaveCalendarView.vue`, `approvedLeaves` is computed from `leaveStore.leaveRequests`.
   - Before the API call returns, `leaveStore.leaveRequests` is empty. The current template unconditionally shows `v-if="approvedLeaves.length === 0"`, giving the false impression that there are no scheduled leaves.
   - Inserting `v-if="leaveStore.loading"` before the empty check with an animated 3-row skeleton loader (`animate-pulse motion-reduce:animate-none` matching the list item dimensions) eliminates the false empty state and prevents layout jumps.
   - Adding an `onMounted` hook in `LeaveCalendarView.vue` guarantees self-contained data fetching if the component is mounted independently.

---

## 3. Caveats

1. **File Location Discrepancy for ScheduleHub:**
   - The original request prompt referred to `resources/js/components/shifts/ScheduleHub.vue`.
   - The actual component file in the repository is `resources/js/components/schedules/ScheduleHub.vue`.
   - Worker must edit `resources/js/components/schedules/ScheduleHub.vue` and must **not** create a duplicate in `components/shifts/`.
2. **Tabpanel Container Wrapping:**
   - In Vue, components like `<ShiftManager />` and `<ShiftAssignment />` can either have root attributes or be wrapped in a `<div role="tabpanel" ...>`. Wrapping them directly in `<div role="tabpanel" id="..." aria-labelledby="..." tabindex="0">` inside the hub template is safest because it avoids modifying the internal implementations of the sub-components while maintaining strict WAI-ARIA compliance.
3. **Pinia Store State Mutability:**
   - `attendanceStore.loading` and `leaveStore.loading` are reactive Pinia boolean flags. The implementation relies directly on these existing properties without requiring any store-level schema changes.
4. **No Other Sub-Hubs Require Changes:**
   - `LeaveHub.vue` does not use segmented tabs (it renders widgets directly in a dashboard layout).
   - `ReportsHub.vue` already utilizes its own tab layout; only the 4 specified sub-hubs (`AttendanceHub.vue`, `ScheduleHub.vue`, `VisitorHub.vue`, and `SettingsHub.vue`) are in scope for HUB-01.

---

## 4. Conclusion & Drop-in Code Snippets

Below are the exact file modifications and drop-in code snippets for each of the 7 files.

### 4.1 `resources/js/components/attendance/AttendanceDashboard.vue`
**Actions:**
1. Import `onMounted` from `'vue'`.
2. Add `onMounted` hook to trigger `attendanceStore.fetchDailyAttendance()`.
3. Wrap KPI Metric Grid in `v-if="attendanceStore.loading"` skeleton cards and `v-else` live data cards.
4. Add `motion-reduce:animate-none` to line 43 pulsating indicator.

```vue
<!-- BEFORE (lines 4-35) -->
        <!-- KPI Metrics Grid -->
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
            <div class="bg-white border border-slate-200/80 p-4 rounded-2xl shadow-xs">
                <div class="text-[11px] text-slate-500 uppercase font-bold tracking-wider">Total Scheduled</div>
                <div class="text-2xl font-bold text-slate-900 mt-1 font-mono">{{ attendanceStore.stats.total_employees }}</div>
                <div class="text-[10px] text-slate-400 mt-1">Total Headcount</div>
            </div>
            <div class="bg-white border border-slate-200/80 p-4 rounded-2xl shadow-xs">
                <div class="text-[11px] text-emerald-700 uppercase font-bold tracking-wider">Present Today</div>
                <div class="text-2xl font-bold text-emerald-600 mt-1 font-mono">{{ attendanceStore.stats.present }}</div>
                <div class="text-[10px] text-emerald-600 font-medium mt-1">{{ attendanceStore.stats.attendance_rate }}% Rate</div>
            </div>
            <div class="bg-white border border-slate-200/80 p-4 rounded-2xl shadow-xs">
                <div class="text-[11px] text-rose-700 uppercase font-bold tracking-wider">Absent</div>
                <div class="text-2xl font-bold text-rose-600 mt-1 font-mono">{{ attendanceStore.stats.absent }}</div>
                <div class="text-[10px] text-rose-500 font-medium mt-1">Unexcused / Missing</div>
            </div>
            <div class="bg-white border border-slate-200/80 p-4 rounded-2xl shadow-xs">
                <div class="text-[11px] text-amber-700 uppercase font-bold tracking-wider">Late Arrivals</div>
                <div class="text-2xl font-bold text-amber-600 mt-1 font-mono">{{ attendanceStore.stats.late }}</div>
                <div class="text-[10px] text-amber-600 font-medium mt-1">Past Grace Period</div>
            </div>
            <div class="bg-white border border-slate-200/80 p-4 rounded-2xl shadow-xs">
                <div class="text-[11px] text-indigo-700 uppercase font-bold tracking-wider">On Leave</div>
                <div class="text-2xl font-bold text-indigo-600 mt-1 font-mono">{{ attendanceStore.stats.on_leave }}</div>
                <div class="text-[10px] text-indigo-600 font-medium mt-1">Approved Leaves</div>
            </div>
            <div class="bg-white border border-slate-200/80 p-4 rounded-2xl shadow-xs">
                <div class="text-[11px] text-sky-700 uppercase font-bold tracking-wider">Early Out</div>
                <div class="text-2xl font-bold text-sky-600 mt-1 font-mono">{{ attendanceStore.stats.early_out }}</div>
                <div class="text-[10px] text-sky-600 font-medium mt-1">Left Before End</div>
            </div>
        </div>
```

```vue
<!-- AFTER (Replacement for lines 4-35) -->
        <!-- KPI Metrics Grid -->
        <div v-if="attendanceStore.loading" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4" aria-hidden="true">
            <div v-for="i in 6" :key="`skel-kpi-${i}`" class="bg-white border border-slate-200/80 p-4 rounded-2xl shadow-xs animate-pulse motion-reduce:animate-none space-y-2">
                <div class="h-3 bg-slate-200 rounded w-20"></div>
                <div class="h-8 bg-slate-200 rounded w-16"></div>
                <div class="h-2.5 bg-slate-100 rounded w-24"></div>
            </div>
        </div>
        <div v-else class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
            <div class="bg-white border border-slate-200/80 p-4 rounded-2xl shadow-xs">
                <div class="text-[11px] text-slate-500 uppercase font-bold tracking-wider">Total Scheduled</div>
                <div class="text-2xl font-bold text-slate-900 mt-1 font-mono">{{ attendanceStore.stats.total_employees }}</div>
                <div class="text-[10px] text-slate-400 mt-1">Total Headcount</div>
            </div>
            <div class="bg-white border border-slate-200/80 p-4 rounded-2xl shadow-xs">
                <div class="text-[11px] text-emerald-700 uppercase font-bold tracking-wider">Present Today</div>
                <div class="text-2xl font-bold text-emerald-600 mt-1 font-mono">{{ attendanceStore.stats.present }}</div>
                <div class="text-[10px] text-emerald-600 font-medium mt-1">{{ attendanceStore.stats.attendance_rate }}% Rate</div>
            </div>
            <div class="bg-white border border-slate-200/80 p-4 rounded-2xl shadow-xs">
                <div class="text-[11px] text-rose-700 uppercase font-bold tracking-wider">Absent</div>
                <div class="text-2xl font-bold text-rose-600 mt-1 font-mono">{{ attendanceStore.stats.absent }}</div>
                <div class="text-[10px] text-rose-500 font-medium mt-1">Unexcused / Missing</div>
            </div>
            <div class="bg-white border border-slate-200/80 p-4 rounded-2xl shadow-xs">
                <div class="text-[11px] text-amber-700 uppercase font-bold tracking-wider">Late Arrivals</div>
                <div class="text-2xl font-bold text-amber-600 mt-1 font-mono">{{ attendanceStore.stats.late }}</div>
                <div class="text-[10px] text-amber-600 font-medium mt-1">Past Grace Period</div>
            </div>
            <div class="bg-white border border-slate-200/80 p-4 rounded-2xl shadow-xs">
                <div class="text-[11px] text-indigo-700 uppercase font-bold tracking-wider">On Leave</div>
                <div class="text-2xl font-bold text-indigo-600 mt-1 font-mono">{{ attendanceStore.stats.on_leave }}</div>
                <div class="text-[10px] text-indigo-600 font-medium mt-1">Approved Leaves</div>
            </div>
            <div class="bg-white border border-slate-200/80 p-4 rounded-2xl shadow-xs">
                <div class="text-[11px] text-sky-700 uppercase font-bold tracking-wider">Early Out</div>
                <div class="text-2xl font-bold text-sky-600 mt-1 font-mono">{{ attendanceStore.stats.early_out }}</div>
                <div class="text-[10px] text-sky-600 font-medium mt-1">Left Before End</div>
            </div>
        </div>
```

```vue
<!-- BEFORE (line 43) -->
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse"></span>

<!-- AFTER (Replacement for line 43) -->
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse motion-reduce:animate-none"></span>
```

```javascript
// BEFORE (lines 109-117)
<script setup>
import { ref } from 'vue';
import { useAttendanceStore } from '../../stores/attendanceStore';
import ManualAttendanceEntry from './ManualAttendanceEntry.vue';
import notify from '../../utils/notify';

const attendanceStore = useAttendanceStore();
const showManualModal = ref(false);

// AFTER (Replacement for lines 109-117)
<script setup>
import { ref, onMounted } from 'vue';
import { useAttendanceStore } from '../../stores/attendanceStore';
import ManualAttendanceEntry from './ManualAttendanceEntry.vue';
import notify from '../../utils/notify';

const attendanceStore = useAttendanceStore();
const showManualModal = ref(false);

onMounted(() => {
    if (!attendanceStore.dailyRoster.length && !attendanceStore.loading) {
        attendanceStore.fetchDailyAttendance();
    }
});
```

---

### 4.2 `resources/js/App.vue`
**Action:** Add `motion-reduce:animate-none` to line 391 (and lines 130 and 312 for full reduced motion coverage).

```html
<!-- BEFORE (lines 387-393) -->
                            <span
                                v-if="
                                    store.stats.telemetry?.unresolved_alerts > 0
                                "
                                class="w-2 h-2 rounded-full bg-rose-500 animate-ping"
                            ></span>

<!-- AFTER (Replacement for lines 387-393) -->
                            <span
                                v-if="
                                    store.stats.telemetry?.unresolved_alerts > 0
                                "
                                class="w-2 h-2 rounded-full bg-rose-500 animate-ping motion-reduce:animate-none"
                            ></span>
```

*(Recommended polish in App.vue)*:
- Line 130: `class="px-1.5 py-0.5 rounded-full text-[10px] font-mono font-bold animate-pulse motion-reduce:animate-none"`
- Line 312: `class="bg-white border border-slate-200/80 rounded-2xl p-4 shadow-xs animate-pulse motion-reduce:animate-none space-y-2"`

---

### 4.3 `resources/js/components/attendance/AttendanceHub.vue`
**Action:** Implement ARIA tabs pattern and `flex-wrap` responsive wrapping.

```vue
<!-- BEFORE (lines 12-30) -->
            <!-- Segmented Pill Tabs -->
            <div class="flex items-center bg-slate-100 p-1.5 rounded-xl border border-slate-200 overflow-x-auto">
                <button @click="activeSubTab = 'dashboard'"
                    :class="activeSubTab === 'dashboard' ? 'bg-white text-indigo-600 shadow-xs font-bold' : 'text-slate-600 hover:text-slate-900'"
                    class="px-4 py-2 rounded-lg transition-all text-xs cursor-pointer flex items-center gap-1.5 font-medium">
                    <span>📈</span> Overview Dashboard
                </button>
                <button @click="activeSubTab = 'roster'"
                    :class="activeSubTab === 'roster' ? 'bg-white text-indigo-600 shadow-xs font-bold' : 'text-slate-600 hover:text-slate-900'"
                    class="px-4 py-2 rounded-lg transition-all text-xs cursor-pointer flex items-center gap-1.5 font-medium">
                    <span>📋</span> Daily Roster &amp; Records
                </button>
            </div>
        </div>

        <!-- Sub Tabs Content -->
        <AttendanceDashboard v-if="activeSubTab === 'dashboard'" />
        <DailyAttendanceRoster v-else-if="activeSubTab === 'roster'" />
```

```vue
<!-- AFTER (Replacement for lines 12-30) -->
            <!-- Segmented Pill Tabs -->
            <div role="tablist" aria-label="Attendance navigation tabs" class="flex flex-wrap items-center bg-slate-100 p-1.5 rounded-xl border border-slate-200 gap-1">
                <button
                    type="button"
                    role="tab"
                    id="attendance-tab-dashboard"
                    :aria-selected="activeSubTab === 'dashboard' ? 'true' : 'false'"
                    aria-controls="attendance-panel-dashboard"
                    @click="activeSubTab = 'dashboard'"
                    :class="activeSubTab === 'dashboard' ? 'bg-white text-indigo-600 shadow-xs font-bold' : 'text-slate-600 hover:text-slate-900'"
                    class="px-4 py-2 rounded-lg transition-all text-xs cursor-pointer flex items-center gap-1.5 font-medium"
                >
                    <span>📈</span> Overview Dashboard
                </button>
                <button
                    type="button"
                    role="tab"
                    id="attendance-tab-roster"
                    :aria-selected="activeSubTab === 'roster' ? 'true' : 'false'"
                    aria-controls="attendance-panel-roster"
                    @click="activeSubTab = 'roster'"
                    :class="activeSubTab === 'roster' ? 'bg-white text-indigo-600 shadow-xs font-bold' : 'text-slate-600 hover:text-slate-900'"
                    class="px-4 py-2 rounded-lg transition-all text-xs cursor-pointer flex items-center gap-1.5 font-medium"
                >
                    <span>📋</span> Daily Roster &amp; Records
                </button>
            </div>
        </div>

        <!-- Sub Tabs Content -->
        <div
            v-if="activeSubTab === 'dashboard'"
            id="attendance-panel-dashboard"
            role="tabpanel"
            aria-labelledby="attendance-tab-dashboard"
            tabindex="0"
        >
            <AttendanceDashboard />
        </div>
        <div
            v-else-if="activeSubTab === 'roster'"
            id="attendance-panel-roster"
            role="tabpanel"
            aria-labelledby="attendance-tab-roster"
            tabindex="0"
        >
            <DailyAttendanceRoster />
        </div>
```

---

### 4.4 `resources/js/components/schedules/ScheduleHub.vue`
**Action:** Implement ARIA tabs pattern and `flex-wrap` responsive wrapping.

```vue
<!-- BEFORE (lines 14-36) -->
      <!-- Segmented Pill Tabs -->
      <div class="flex items-center bg-slate-100 p-1.5 rounded-xl border border-slate-200 overflow-x-auto">
        <button
          v-for="tab in tabs"
          :key="tab.id"
          type="button"
          @click="activeTab = tab.id"
          class="px-4 py-2 text-xs font-bold rounded-lg transition-all whitespace-nowrap cursor-pointer flex items-center gap-2"
          :class="activeTab === tab.id ? 'bg-white text-indigo-600 shadow-xs' : 'text-slate-600 hover:text-slate-900'"
        >
          <span>{{ tab.icon }}</span>
          <span>{{ tab.label }}</span>
        </button>
      </div>
    </div>

    <!-- Active Sub-View -->
    <div>
      <ShiftManager v-if="activeTab === 'shifts'" />
      <ShiftAssignment v-else-if="activeTab === 'assignments'" />
      <HolidayCalendar v-else-if="activeTab === 'holidays'" />
    </div>
```

```vue
<!-- AFTER (Replacement for lines 14-36) -->
      <!-- Segmented Pill Tabs -->
      <div role="tablist" aria-label="Schedule navigation tabs" class="flex flex-wrap items-center bg-slate-100 p-1.5 rounded-xl border border-slate-200 gap-1">
        <button
          v-for="tab in tabs"
          :key="tab.id"
          type="button"
          role="tab"
          :id="'schedule-tab-' + tab.id"
          :aria-selected="activeTab === tab.id ? 'true' : 'false'"
          :aria-controls="'schedule-panel-' + tab.id"
          @click="activeTab = tab.id"
          class="px-4 py-2 text-xs font-bold rounded-lg transition-all whitespace-nowrap cursor-pointer flex items-center gap-2"
          :class="activeTab === tab.id ? 'bg-white text-indigo-600 shadow-xs' : 'text-slate-600 hover:text-slate-900'"
        >
          <span>{{ tab.icon }}</span>
          <span>{{ tab.label }}</span>
        </button>
      </div>
    </div>

    <!-- Active Sub-View -->
    <div>
      <div
        v-if="activeTab === 'shifts'"
        id="schedule-panel-shifts"
        role="tabpanel"
        aria-labelledby="schedule-tab-shifts"
        tabindex="0"
      >
        <ShiftManager />
      </div>
      <div
        v-else-if="activeTab === 'assignments'"
        id="schedule-panel-assignments"
        role="tabpanel"
        aria-labelledby="schedule-tab-assignments"
        tabindex="0"
      >
        <ShiftAssignment />
      </div>
      <div
        v-else-if="activeTab === 'holidays'"
        id="schedule-panel-holidays"
        role="tabpanel"
        aria-labelledby="schedule-tab-holidays"
        tabindex="0"
      >
        <HolidayCalendar />
      </div>
    </div>
```

---

### 4.5 `resources/js/components/visitors/VisitorHub.vue`
**Action:** Implement ARIA tabs pattern and `flex-wrap` responsive wrapping.

```vue
<!-- BEFORE (lines 11-27) -->
            <div class="flex items-center bg-slate-100 p-1.5 rounded-xl border border-slate-200 overflow-x-auto">
                <button @click="activeSubTab = 'dashboard'"
                    :class="activeSubTab === 'dashboard' ? 'bg-white text-indigo-600 shadow-xs font-bold' : 'text-slate-600 hover:text-slate-900'"
                    class="px-4 py-2 rounded-lg transition-all text-xs cursor-pointer flex items-center gap-1.5 font-medium">
                    <span>🪪</span> Visitor Overview
                </button>
                <button @click="activeSubTab = 'watchlist'"
                    :class="activeSubTab === 'watchlist' ? 'bg-white text-indigo-600 shadow-xs font-bold' : 'text-slate-600 hover:text-slate-900'"
                    class="px-4 py-2 rounded-lg transition-all text-xs cursor-pointer flex items-center gap-1.5 font-medium">
                    <span>🛡️</span> Security Watchlist
                </button>
            </div>
        </div>

        <VisitorDashboard v-if="activeSubTab === 'dashboard'" />
        <WatchlistManager v-else-if="activeSubTab === 'watchlist'" />
```

```vue
<!-- AFTER (Replacement for lines 11-27) -->
            <div role="tablist" aria-label="Visitor management tabs" class="flex flex-wrap items-center bg-slate-100 p-1.5 rounded-xl border border-slate-200 gap-1">
                <button
                    type="button"
                    role="tab"
                    id="visitor-tab-dashboard"
                    :aria-selected="activeSubTab === 'dashboard' ? 'true' : 'false'"
                    aria-controls="visitor-panel-dashboard"
                    @click="activeSubTab = 'dashboard'"
                    :class="activeSubTab === 'dashboard' ? 'bg-white text-indigo-600 shadow-xs font-bold' : 'text-slate-600 hover:text-slate-900'"
                    class="px-4 py-2 rounded-lg transition-all text-xs cursor-pointer flex items-center gap-1.5 font-medium"
                >
                    <span>🪪</span> Visitor Overview
                </button>
                <button
                    type="button"
                    role="tab"
                    id="visitor-tab-watchlist"
                    :aria-selected="activeSubTab === 'watchlist' ? 'true' : 'false'"
                    aria-controls="visitor-panel-watchlist"
                    @click="activeSubTab = 'watchlist'"
                    :class="activeSubTab === 'watchlist' ? 'bg-white text-indigo-600 shadow-xs font-bold' : 'text-slate-600 hover:text-slate-900'"
                    class="px-4 py-2 rounded-lg transition-all text-xs cursor-pointer flex items-center gap-1.5 font-medium"
                >
                    <span>🛡️</span> Security Watchlist
                </button>
            </div>
        </div>

        <!-- Sub Tabs Content -->
        <div
            v-if="activeSubTab === 'dashboard'"
            id="visitor-panel-dashboard"
            role="tabpanel"
            aria-labelledby="visitor-tab-dashboard"
            tabindex="0"
        >
            <VisitorDashboard />
        </div>
        <div
            v-else-if="activeSubTab === 'watchlist'"
            id="visitor-panel-watchlist"
            role="tabpanel"
            aria-labelledby="visitor-tab-watchlist"
            tabindex="0"
        >
            <WatchlistManager />
        </div>
```

---

### 4.6 `resources/js/components/settings/SettingsHub.vue`
**Action:** Implement responsive header wrapping, ARIA tabs pattern, and `role="tabpanel"` containers.

```vue
<!-- BEFORE (lines 4-32) -->
    <div class="flex items-center justify-between bg-white border border-slate-200/80 p-4 rounded-xl shadow-xs">
      <div>
        <h2 class="text-lg font-bold text-slate-900">System Administration &amp; Settings</h2>
        <p class="text-xs text-slate-500">Manage organization hierarchy, biometric parameters, security policies, and audit logs</p>
      </div>

      <!-- Settings Sub-Navigation Pill Bar -->
      <div class="flex items-center gap-1.5 bg-slate-100 p-1 rounded-xl border border-slate-200">
        <button
          v-for="tab in tabs"
          :key="tab.id"
          @click="activeTab = tab.id"
          class="px-3.5 py-1.5 text-xs font-semibold rounded-lg transition-all flex items-center gap-1.5 cursor-pointer"
          :class="activeTab === tab.id ? 'bg-white text-indigo-600 shadow-xs font-bold' : 'text-slate-600 hover:text-slate-900'"
        >
          <span>{{ tab.icon }}</span>
          <span>{{ tab.label }}</span>
        </button>
      </div>
    </div>

    <!-- Active Sub-View Component -->
    <div>
      <DepartmentManager v-if="activeTab === 'departments'" />
      <AccessGroupManager v-else-if="activeTab === 'access-groups'" />
      <SystemSettings v-else-if="activeTab === 'system'" />
      <AuditLogViewer v-else-if="activeTab === 'audit'" />
    </div>
```

```vue
<!-- AFTER (Replacement for lines 4-32) -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-white border border-slate-200/80 p-4 rounded-xl shadow-xs">
      <div>
        <h2 class="text-lg font-bold text-slate-900">System Administration &amp; Settings</h2>
        <p class="text-xs text-slate-500">Manage organization hierarchy, biometric parameters, security policies, and audit logs</p>
      </div>

      <!-- Settings Sub-Navigation Pill Bar -->
      <div role="tablist" aria-label="Settings navigation tabs" class="flex flex-wrap items-center gap-1.5 bg-slate-100 p-1 rounded-xl border border-slate-200">
        <button
          v-for="tab in tabs"
          :key="tab.id"
          type="button"
          role="tab"
          :id="'settings-tab-' + tab.id"
          :aria-selected="activeTab === tab.id ? 'true' : 'false'"
          :aria-controls="'settings-panel-' + tab.id"
          @click="activeTab = tab.id"
          class="px-3.5 py-1.5 text-xs font-semibold rounded-lg transition-all flex items-center gap-1.5 cursor-pointer"
          :class="activeTab === tab.id ? 'bg-white text-indigo-600 shadow-xs font-bold' : 'text-slate-600 hover:text-slate-900'"
        >
          <span>{{ tab.icon }}</span>
          <span>{{ tab.label }}</span>
        </button>
      </div>
    </div>

    <!-- Active Sub-View Component -->
    <div>
      <div
        v-if="activeTab === 'departments'"
        id="settings-panel-departments"
        role="tabpanel"
        aria-labelledby="settings-tab-departments"
        tabindex="0"
      >
        <DepartmentManager />
      </div>
      <div
        v-else-if="activeTab === 'access-groups'"
        id="settings-panel-access-groups"
        role="tabpanel"
        aria-labelledby="settings-tab-access-groups"
        tabindex="0"
      >
        <AccessGroupManager />
      </div>
      <div
        v-else-if="activeTab === 'system'"
        id="settings-panel-system"
        role="tabpanel"
        aria-labelledby="settings-tab-system"
        tabindex="0"
      >
        <SystemSettings />
      </div>
      <div
        v-else-if="activeTab === 'audit'"
        id="settings-panel-audit"
        role="tabpanel"
        aria-labelledby="settings-tab-audit"
        tabindex="0"
      >
        <AuditLogViewer />
      </div>
    </div>
```

---

### 4.7 `resources/js/components/leave/LeaveCalendarView.vue`
**Actions:**
1. Insert animated 3-row skeleton loader when `leaveStore.loading` is `true`.
2. Add `onMounted` hook in script setup to fetch leave requests if empty.

```vue
<!-- BEFORE (lines 10-28) -->
        <div v-if="approvedLeaves.length === 0" class="py-10 text-center text-slate-500 text-xs">
            No approved leaves scheduled for this period.
        </div>
        <div v-else class="space-y-2.5">
            <div v-for="leave in approvedLeaves" :key="leave.id" class="flex items-center justify-between p-3.5 bg-slate-50 border border-slate-200/80 rounded-xl hover:bg-slate-100/70 transition-colors">
                <div class="flex items-center space-x-3">
                    <span class="text-lg">🏖️</span>
                    <div>
                        <div class="text-sm font-semibold text-slate-900">{{ leave.employee?.first_name }} {{ leave.employee?.last_name || '' }}</div>
                        <div class="text-xs text-slate-500">{{ leave.leave_type?.name }} • {{ leave.employee?.department?.name || 'General' }}</div>
                    </div>
                </div>
                <div class="text-right">
                    <div class="text-xs font-mono text-indigo-600 font-bold">{{ leave.start_date }} → {{ leave.end_date }}</div>
                    <div class="text-[11px] text-slate-500 font-medium">{{ leave.total_days }} day(s)</div>
                </div>
            </div>
        </div>
```

```vue
<!-- AFTER (Replacement for lines 10-28) -->
        <!-- Skeleton Loading State (prevents premature "No approved leaves" flash & CLS) -->
        <div v-if="leaveStore.loading" class="space-y-2.5" aria-hidden="true">
            <div v-for="i in 3" :key="`skel-leave-${i}`" class="flex items-center justify-between p-3.5 bg-slate-50 border border-slate-200/80 rounded-xl animate-pulse motion-reduce:animate-none">
                <div class="flex items-center space-x-3">
                    <div class="w-8 h-8 bg-slate-200 rounded-lg shrink-0"></div>
                    <div class="space-y-1.5">
                        <div class="h-4 bg-slate-200 rounded w-28"></div>
                        <div class="h-3 bg-slate-100 rounded w-36"></div>
                    </div>
                </div>
                <div class="space-y-1.5 text-right">
                    <div class="h-3 bg-slate-200 rounded w-24 ml-auto"></div>
                    <div class="h-3 bg-slate-100 rounded w-14 ml-auto"></div>
                </div>
            </div>
        </div>

        <div v-else-if="approvedLeaves.length === 0" class="py-10 text-center text-slate-500 text-xs">
            No approved leaves scheduled for this period.
        </div>
        <div v-else class="space-y-2.5">
            <div v-for="leave in approvedLeaves" :key="leave.id" class="flex items-center justify-between p-3.5 bg-slate-50 border border-slate-200/80 rounded-xl hover:bg-slate-100/70 transition-colors">
                <div class="flex items-center space-x-3">
                    <span class="text-lg">🏖️</span>
                    <div>
                        <div class="text-sm font-semibold text-slate-900">{{ leave.employee?.first_name }} {{ leave.employee?.last_name || '' }}</div>
                        <div class="text-xs text-slate-500">{{ leave.leave_type?.name }} • {{ leave.employee?.department?.name || 'General' }}</div>
                    </div>
                </div>
                <div class="text-right">
                    <div class="text-xs font-mono text-indigo-600 font-bold">{{ leave.start_date }} → {{ leave.end_date }}</div>
                    <div class="text-[11px] text-slate-500 font-medium">{{ leave.total_days }} day(s)</div>
                </div>
            </div>
        </div>
```

```javascript
// BEFORE (lines 31-40)
<script setup>
import { computed } from 'vue';
import { useLeaveStore } from '../../stores/leaveStore';

const leaveStore = useLeaveStore();

const approvedLeaves = computed(() => {
    return leaveStore.leaveRequests.filter(r => r.status === 'approved');
});
</script>

// AFTER (Replacement for lines 31-40)
<script setup>
import { computed, onMounted } from 'vue';
import { useLeaveStore } from '../../stores/leaveStore';

const leaveStore = useLeaveStore();

const approvedLeaves = computed(() => {
    return leaveStore.leaveRequests.filter(r => r.status === 'approved');
});

onMounted(() => {
    if (!leaveStore.leaveRequests.length && !leaveStore.loading) {
        leaveStore.fetchLeaveRequests(1);
    }
});
</script>
```

---

## 5. Verification Method

To independently verify the implementation after application by the worker:

1. **Frontend Compilation:**
   Execute:
   ```bash
   npm run build
   ```
   **Expected Result:** Vite builds without any syntax errors, unresolved components, template syntax errors, or bundling issues (exit code 0).

2. **WAI-ARIA Tab Semantics Verification:**
   Execute:
   ```bash
   grep -rn 'role="tablist"' resources/js/components/
   grep -rn 'role="tabpanel"' resources/js/components/
   ```
   **Expected Result:** All 4 sub-hubs (`AttendanceHub.vue`, `ScheduleHub.vue`, `VisitorHub.vue`, `SettingsHub.vue`) appear with `role="tablist"` on navigation bars and `role="tabpanel"` on sub-view containers.

3. **Motion-Reduction Verification:**
   Execute:
   ```bash
   grep -rn 'motion-reduce:animate-none' resources/js/components/attendance/AttendanceDashboard.vue resources/js/App.vue
   ```
   **Expected Result:** Matches found for the live attendance stream indicator in `AttendanceDashboard.vue` and the alert ping in `App.vue`.

4. **Skeleton & CLS Verification:**
   Execute:
   ```bash
   grep -rn 'v-if="attendanceStore.loading"' resources/js/components/attendance/AttendanceDashboard.vue
   grep -rn 'v-if="leaveStore.loading"' resources/js/components/leave/LeaveCalendarView.vue
   ```
   **Expected Result:** Loading skeleton state branches exist prior to the real data and empty states.

5. **PHP Backend & Regression Verification:**
   Execute:
   ```bash
   php artisan test
   ```
   **Expected Result:** Complete test suite passes with 0 failures and 0 errors.

6. **Invalidation Conditions:**
   - Any failure in `npm run build` invalidates the proposed Vue changes.
   - Any missing `aria-selected`, `aria-controls`, or `aria-labelledby` binding in tab sub-hubs invalidates HUB-01 compliance.
   - Any render of "No approved leaves scheduled for this period." while `leaveStore.loading === true` invalidates LVE-06.
