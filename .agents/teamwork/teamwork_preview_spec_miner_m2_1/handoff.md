# Milestone 2 Specification Report: Daily Attendance Roster & Overrides (ROST-01 through ROST-05)

**Target Component:** `resources/js/components/attendance/DailyAttendanceRoster.vue`  
**Host Context:** `resources/js/components/attendance/AttendanceHub.vue` (`activeSubTab === 'roster'`)  
**Data Stores:** `resources/js/stores/attendanceStore.js`, `resources/js/stores/employeeStore.js`  
**Notification / Dialog Service:** `resources/js/utils/notify.js`  
**Date:** 2026-10-07  

---

## Features Discovered

| # | Category | Feature | Description | Inputs | Outputs | Error Behavior | Discovered Via |
|---|----------|---------|-------------|--------|---------|----------------|----------------|
| 1 | Accessibility & UX | ROST-01: Accessible Day Finalization Confirmation | Replace native blocking `window.confirm()` with accessible custom modal dialog via `notify.confirm()` | Click on "Finalize Day" button (`handleFinalize`), `attendanceStore.selectedDate` | Promise-based modal with title, descriptive text, Confirm ("Yes, Finalize") and Cancel buttons; resolves to `boolean` | If user cancels or clicks outside, returns `false` and aborts finalizer trigger; no network request dispatched | `tasks-optimization.md:142`, `DailyAttendanceRoster.vue:183-187`, `notify.js:91-105` |
| 2 | Accessibility & Forms | ROST-02: Date Input Accessible Label | Add explicit `aria-label` to the date picker input control | `<input type="date" v-model="attendanceStore.selectedDate">` | Screen reader announces "Filter attendance by date, date picker" | In absence of visible `<label>`, `aria-label` provides programmatic accessible name per WCAG 4.1.2 | `tasks-optimization.md:143`, `DailyAttendanceRoster.vue:6-8` |
| 3 | Accessibility & Forms | ROST-02: Department Filter Accessible Label | Add explicit `aria-label` to the department dropdown select | `<select v-model="attendanceStore.selectedDepartmentId">` | Screen reader announces "Filter attendance by department, combo box" | In absence of visible `<label>`, `aria-label` provides programmatic accessible name per WCAG 4.1.2 | `tasks-optimization.md:143`, `DailyAttendanceRoster.vue:9-13` |
| 4 | Accessibility & Forms | ROST-02: Status Filter Accessible Label | Add explicit `aria-label` to the attendance status dropdown select | `<select v-model="attendanceStore.selectedStatus">` | Screen reader announces "Filter attendance by status, combo box" | In absence of visible `<label>`, `aria-label` provides programmatic accessible name per WCAG 4.1.2 | `tasks-optimization.md:143`, `DailyAttendanceRoster.vue:15-24` |
| 5 | Accessibility & Forms | ROST-02: Employee Search Box Accessible Label | Add explicit `aria-label` to search text input and mark decorative search icon as hidden | `<input type="text" v-model="attendanceStore.searchQuery">` | Screen reader announces "Search employee by name or code, edit text"; `<span>🔍</span>` ignored via `aria-hidden="true"` | In absence of visible `<label>`, `aria-label` provides programmatic accessible name per WCAG 4.1.2 | `tasks-optimization.md:143`, `DailyAttendanceRoster.vue:26-30` |
| 6 | Accessibility & Forms | ROST-02: Refresh Button Accessible Label | Add explicit `aria-label` to icon-only refresh button and hide emoji from screen readers | `<button @click="attendanceStore.fetchDailyAttendance">🔄</button>` | Screen reader announces "Refresh daily attendance roster, button"; `<span>🔄</span>` ignored via `aria-hidden="true"` | Ensures icon-only control has an accessible name beyond browser tooltip `title="Refresh"` per WCAG 4.1.2 | `tasks-optimization.md:143`, `DailyAttendanceRoster.vue:40-42` |
| 7 | Accessibility & Data Tables | ROST-03: Table Header Scope Attributes | Add `scope="col"` to all 8 header cells in table header row | `<thead>` `<tr>` containing `<th>` elements: Employee, Department, Shift, First In, Last Out, Work Hours, Status, Actions | Screen readers associate data cells in respective columns with correct column header per WCAG 1.3.1 | Without `scope="col"`, complex cell navigation in screen readers can misidentify column headers | `tasks-optimization.md:144`, `DailyAttendanceRoster.vue:50-61` |
| 8 | CLS & Loading States | ROST-04: 8-Column Skeleton Table Loader | Replace single-cell text loader with 5 animated skeleton table rows matching table column dimensions and geometry | `attendanceStore.loading === true`, 8-column layout matching headers | 5 animated rows (`animate-pulse motion-reduce:animate-none`) rendering avatar/name in Col 1, dept in Col 2, shift in Col 3, times in Cols 4-5, hours in Col 6, status badge in Col 7, buttons in Col 8 | Completely eliminates Cumulative Layout Shift (CLS) when data loads | `tasks-optimization.md:145`, `DailyAttendanceRoster.vue:63-65` |
| 9 | Accessibility & Dialogs | ROST-05: Status Override Modal Dialog Semantics | Upgrade Status Override Modal to accessible dialog with ARIA attributes and focus management | `showOverrideModal === true`, container `<div>` | `role="dialog"`, `aria-modal="true"`, `aria-labelledby="override-modal-title"`, `tabindex="-1"` | Screen readers announce modal dialog context and title upon appearance | `tasks-optimization.md:146`, `DailyAttendanceRoster.vue:120-149` |
| 10 | Accessibility & Keyboard | ROST-05: Status Override Modal Escape Handling | Keyboard dismissal via Escape key listener at template and window level | Keyboard event `Escape` while modal is open | Calls `closeOverrideModal()` setting `showOverrideModal = false` | Listener removed on component unmount (`onUnmounted`) to prevent window memory leaks | `tasks-optimization.md:146`, `DailyAttendanceRoster.vue:121` |
| 11 | Accessibility & Forms | ROST-05: Override Form Label & Control Binding | Explicit `<label for="...">` associated with `<select id="...">` and `<textarea id="...">` | Status select (`id="override-status"`), Remarks textarea (`id="override-remarks"`) | Clicking or focusing labels activates corresponding control; screen reader announces field name and purpose | Eliminates unassociated form control a11y violations per WCAG 1.3.1 | `tasks-optimization.md:146`, `DailyAttendanceRoster.vue:128-143` |

---

## Edge Cases

| # | Feature | Input | Observed Behavior |
|---|---------|-------|-------------------|
| 1 | ROST-01 | User dismisses or clicks "Cancel" on finalization modal | `notify.confirm` resolves to `false`. Execution exits early from `handleFinalize` without calling `attendanceStore.triggerDailyFinalizer()`. No background job or network request is created. |
| 2 | ROST-01 | `attendanceStore.selectedDate` is empty string or unset | `attendanceStore.selectedDate` defaults to current ISO date in store initialization (`new Date().toISOString().slice(0, 10)`). Even if empty, template interpolation produces valid confirmation text without throwing runtime exceptions. |
| 3 | ROST-01 | User clicks "Finalize Day" while finalization is already in progress | Button has `:disabled="attendanceStore.finalizing"`. Modal dialog backdrop further prevents duplicate background clicks. |
| 4 | ROST-02 | Screen reader encounters decorative emojis `🔍` and `🔄` | When wrapped with `<span aria-hidden="true">` and parent has descriptive `aria-label`, screen readers announce only the accessible text ("Search employee by name or code", "Refresh daily attendance roster") rather than pronouncing Unicode emoji symbols ("Left-pointing magnifying glass", "Counterclockwise arrows button"). |
| 5 | ROST-04 | User has enabled OS system setting `prefers-reduced-motion: reduce` | Skeleton rows include `motion-reduce:animate-none`. The pulse opacity animation is deactivated, rendering static placeholder blocks to prevent vestibular motion sickness. |
| 6 | ROST-04 | Daily attendance API returns zero records (`filteredRoster.length === 0`) | When `attendanceStore.loading` transitions from `true` to `false`, the 5 skeleton rows unmount and are replaced by the existing empty state row `<td colspan="8" ...>No attendance records found for this date.</td>`. |
| 7 | ROST-05 | User presses `Escape` while focused inside `override-remarks` `<textarea>` | Because the keydown listener checks `e.key === 'Escape'` at the dialog and window level, the modal closes cleanly without trapping the user inside the multiline input. |
| 8 | ROST-05 | User clicks modal backdrop (outside the card) | The outer backdrop has `@click.self="closeOverrideModal"`, cleanly closing the modal without modifying data. |
| 9 | ROST-05 | DOM ID collision check across application | Verified via project-wide search that `override-modal-title`, `override-status`, and `override-remarks` are 100% unique across all `.vue` templates in `resources/js/`. |

---

## 1. Observation

Direct code observations from inspection of `resources/js/components/attendance/DailyAttendanceRoster.vue` (231 lines total) and related project files:

1. **Native Browser `confirm()` in `DailyAttendanceRoster.vue:183-187`**:
   ```javascript
   183: const handleFinalize = async () => {
   184:     if (confirm(`Finalize daily attendance records for ${attendanceStore.selectedDate}?`)) {
   185:         await attendanceStore.triggerDailyFinalizer();
   186:     }
   187: };
   ```
   - Uses native `confirm(...)`, blocking UI thread execution and failing WCAG 2.1 AA dialog accessibility standards.
   - Project-standard custom confirmation utility is implemented in `resources/js/utils/notify.js:91-105` (`notify.confirm(title, text, confirmButtonText, cancelButtonText, isDestructive)`), already used in `AttendanceDashboard.vue:119-123`, `PersonnelManager.vue:459`, and `DeviceManager.vue:1066`.
   - `notify` is not yet imported in `DailyAttendanceRoster.vue`.

2. **Unlabeled Controls in Filter & Action Bar (`DailyAttendanceRoster.vue:6-42`)**:
   - Date picker (lines 6-7): `<input v-model="attendanceStore.selectedDate" @change="handleDateChange" type="date" ... />` lacks an `aria-label` or associated `<label>`.
   - Department filter (lines 9-13): `<select v-model="attendanceStore.selectedDepartmentId" ...>` lacks an `aria-label` or associated `<label>`.
   - Status filter (lines 15-24): `<select v-model="attendanceStore.selectedStatus" ...>` lacks an `aria-label` or associated `<label>`.
   - Search box (lines 27-29): `<input v-model="attendanceStore.searchQuery" type="text" placeholder="Search employee or code..." ... />` has a placeholder and sibling `<span>🔍</span>`, but no `aria-label`.
   - Refresh button (lines 40-42): `<button @click="attendanceStore.fetchDailyAttendance" ... title="Refresh">🔄</button>` contains only raw emoji `🔄` and an HTML `title`, lacking an `aria-label`.

3. **Table Header Cells Lack Scope (`DailyAttendanceRoster.vue:50-61`)**:
   ```vue
   50:                     <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500 font-semibold border-b border-slate-200">
   51:                         <tr>
   52:                             <th class="px-4 py-3">Employee</th>
   53:                             <th class="px-4 py-3">Department</th>
   54:                             <th class="px-4 py-3">Shift</th>
   55:                             <th class="px-4 py-3">First In</th>
   56:                             <th class="px-4 py-3">Last Out</th>
   57:                             <th class="px-4 py-3">Work Hours</th>
   58:                             <th class="px-4 py-3">Status</th>
   59:                             <th class="px-4 py-3 text-right">Actions</th>
   60:                         </tr>
   61:                     </thead>
   ```
   None of the 8 `<th>` cells specify `scope="col"`.

4. **Single-Cell Text Loading Placeholder (`DailyAttendanceRoster.vue:63-65`)**:
   ```vue
   63:                         <tr v-if="attendanceStore.loading" class="text-center">
   64:                             <td colspan="8" class="py-12 text-slate-500 text-xs">Loading daily attendance roster...</td>
   65:                         </tr>
   ```
   During data fetching, the table body collapses into a single centered text cell across 8 columns. When loaded, it abruptly snaps into 8 distinct columns containing avatars, badges, and action buttons, creating substantial layout shifts (CLS).

5. **Inaccessible Status Override Modal (`DailyAttendanceRoster.vue:120-149`)**:
   ```vue
   120:         <!-- Status Override Modal -->
   121:         <div v-if="showOverrideModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm" @click.self="showOverrideModal = false">
   122:             <div class="bg-white border border-slate-200 rounded-2xl max-w-md w-full p-6 shadow-2xl space-y-4">
   123:                 <div class="flex items-center justify-between border-b border-slate-100 pb-3">
   124:                     <h3 class="text-base font-bold text-slate-900">Override Attendance Status</h3>
   125:                     <button @click="showOverrideModal = false" class="text-slate-400 hover:text-slate-700 font-bold p-1 cursor-pointer">✕</button>
   126:                 </div>
   127:                 <div class="space-y-3">
   128:                     <div>
   129:                         <label class="block text-xs font-semibold text-slate-700 mb-1">Status</label>
   130:                         <select v-model="overrideForm.status" class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs cursor-pointer">
   ...
   138:                     </div>
   139:                     <div>
   140:                         <label class="block text-xs font-semibold text-slate-700 mb-1">HR Remarks</label>
   141:                         <textarea v-model="overrideForm.remarks" rows="2" class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 placeholder-slate-400 focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs" placeholder="Reason for override..."></textarea>
   142:                     </div>
   143:                 </div>
   ```
   - Container lacks `role="dialog"`, `aria-modal="true"`, `aria-labelledby`, and `tabindex="-1"`.
   - No Escape key listener (`@keydown.escape` or window keydown listener).
   - Heading `<h3>` lacks an `id` to link with `aria-labelledby`.
   - Close button lacks `aria-label` and `type="button"`.
   - Neither `<label>` has a `for` attribute, and neither control (`<select>`, `<textarea>`) has an `id`.

6. **Current Build Verification**:
   - `npm run build` exits 0 (verified in 705ms).
   - Component builds into `public/build/assets/AttendanceHub-*.js`.

---

## 2. Logic Chain

1. **ROST-01: Native `window.confirm()` Replacement**:
   - Observation: `confirm(...)` on line 184 blocks script execution and triggers a browser-level modal.
   - Logic: Replacing this with `const confirmed = await notify.confirm(...)` provides a styled, non-blocking, accessible dialog matching the application's SweetAlert2 theme.
   - Step: Import `notify` from `../../utils/notify`. Define title as `'Finalize Daily Attendance'`, message as `` `Finalize daily attendance records for ${attendanceStore.selectedDate}?` ``, and confirm button as `'Yes, Finalize'`. If `confirmed` is true, invoke `await attendanceStore.triggerDailyFinalizer()`.

2. **ROST-02: Precise Accessible Labels**:
   - Observation: The filter bar contains 5 controls that have visual affordances (placeholders, date icons, options, emoji) but lack programmatic accessible names for screen readers.
   - Logic: Adding explicit `aria-label` attributes satisfies WCAG 2.1 AA Success Criterion 4.1.2 (Name, Role, Value).
   - Pairings:
     1. Date picker: `aria-label="Filter attendance by date"`
     2. Department select: `aria-label="Filter attendance by department"`
     3. Status select: `aria-label="Filter attendance by status"`
     4. Search box: `aria-label="Search employee by name or code"`
     5. Refresh button: `aria-label="Refresh daily attendance roster"`
   - Marking decorative emoji icons (`<span>🔍</span>`, `<span>🔄</span>`) with `aria-hidden="true"` prevents extraneous screen reader verbosity.

3. **ROST-03: Table Header Scope Attributes**:
   - Observation: Table headers in line 52-60 lack `scope` attributes.
   - Logic: Per WCAG 1.3.1 (Info and Relationships), table header cells in data tables must define `scope="col"` so screen readers navigate columns unambiguously.
   - Step: Add `scope="col"` to all 8 `<th>` cells (`Employee`, `Department`, `Shift`, `First In`, `Last Out`, `Work Hours`, `Status`, `Actions`).

4. **ROST-04: 8-Column Skeleton Table Loader**:
   - Observation: Replacing table body with a single `colspan="8"` text cell causes layout shift and perceived latency.
   - Logic: A skeleton loader that mirrors the 8-column layout and geometry of the populated row maintains layout height and structure during network fetching, achieving zero CLS.
   - Step: Create 5 skeleton rows (`v-for="i in 5" :key="`skel-roster-${i}`"`):
     - Col 1: Circular avatar (`w-9 h-9 rounded-full bg-slate-200`) and two text lines (`h-4 w-28`, `h-3 w-16`).
     - Col 2: Department bar (`h-4 w-20`).
     - Col 3: Shift bar (`h-4 w-16`).
     - Col 4: Clock-in time bar (`h-4 w-12`).
     - Col 5: Clock-out time bar (`h-4 w-12`).
     - Col 6: Work hours bar (`h-4 w-14`).
     - Col 7: Status pill (`h-5 w-16 rounded-full`).
     - Col 8: Two action buttons (`h-7 w-20`, `h-7 w-14`).
   - Add `animate-pulse` and `motion-reduce:animate-none` to honor reduced motion preferences.

5. **ROST-05: Status Override Modal Semantics & Form Association**:
   - Observation: The modal is an unannotated `<div>`, has no Escape listener, and form inputs lack label associations.
   - Logic: A WCAG-compliant dialog requires:
     - Outer container: `role="dialog"`, `aria-modal="true"`, `aria-labelledby="override-modal-title"`, `tabindex="-1"`.
     - Heading: `<h3 id="override-modal-title">Override Attendance Status</h3>`.
     - Close button: `type="button"`, `aria-label="Close dialog"`.
     - Escape dismissal: Both template `@keydown.escape="closeOverrideModal"` and a window-level keydown event listener attached during `onMounted` and removed during `onUnmounted`.
     - Form associations: `<label for="override-status">` <-> `<select id="override-status">`, and `<label for="override-remarks">` <-> `<textarea id="override-remarks">`.

---

## 3. Caveats

1. **Child Modals Independence**: `ManualAttendanceEntry.vue` and `EmployeeAttendanceCalendar.vue` are instantiated inside `DailyAttendanceRoster.vue`. `ManualAttendanceEntry.vue` is already an accessible modal. `EmployeeAttendanceCalendar.vue` is scoped separately under Milestone 3 (CAL-01..CAL-03).
2. **Store Action Contracts**: `attendanceStore.overrideStatus` and `attendanceStore.triggerDailyFinalizer` already handle toast notifications via `notify.success` / `notify.error`. The roster component should not duplicate toasts upon action completion.
3. **No Implementation in this Turn**: This specification miner subagent produces only authoritative requirements, exact code replacements, and verification instructions without modifying the codebase.

---

## 4. Conclusion & Precise Specification

### 1. ROST-01: Exact Code Replacement for `window.confirm()`

#### A. Import Addition (`resources/js/components/attendance/DailyAttendanceRoster.vue`)
Add import of `notify` utility to `<script setup>`:
```javascript
import notify from '../../utils/notify';
```

#### B. Replace `handleFinalize` Method
Replace lines 183-187:
```javascript
// BEFORE:
const handleFinalize = async () => {
    if (confirm(`Finalize daily attendance records for ${attendanceStore.selectedDate}?`)) {
        await attendanceStore.triggerDailyFinalizer();
    }
};

// AFTER:
const handleFinalize = async () => {
    const confirmed = await notify.confirm(
        'Finalize Daily Attendance',
        `Finalize daily attendance records for ${attendanceStore.selectedDate}?`,
        'Yes, Finalize'
    );
    if (confirmed) {
        await attendanceStore.triggerDailyFinalizer();
    }
};
```

---

### 2. ROST-02: Precise `aria-label` Text for the 5 Filter/Action Controls

In `resources/js/components/attendance/DailyAttendanceRoster.vue:6-43`:

| # | Control | Element Type | Exact `aria-label` Attribute | Decorative Icon Treatment |
|---|---------|--------------|------------------------------|---------------------------|
| 1 | Date Picker | `<input type="date">` | `aria-label="Filter attendance by date"` | N/A |
| 2 | Department Filter | `<select>` | `aria-label="Filter attendance by department"` | N/A |
| 3 | Status Filter | `<select>` | `aria-label="Filter attendance by status"` | N/A |
| 4 | Search Box | `<input type="text">` | `aria-label="Search employee by name or code"` | `<span ... aria-hidden="true">🔍</span>` |
| 5 | Refresh Button | `<button>` | `aria-label="Refresh daily attendance roster"` | `<span aria-hidden="true">🔄</span>` |

#### Exact Template Replacement for Controls Bar:
```vue
        <!-- Controls & Filters Bar -->
        <div class="flex flex-wrap items-center justify-between gap-3 bg-white border border-slate-200/80 p-4 rounded-2xl shadow-xs">
            <div class="flex flex-wrap items-center gap-3">
                <input v-model="attendanceStore.selectedDate" @change="handleDateChange" type="date"
                    aria-label="Filter attendance by date"
                    class="bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 cursor-pointer shadow-xs" />

                <select v-model="attendanceStore.selectedDepartmentId" @change="attendanceStore.fetchDailyAttendance"
                    aria-label="Filter attendance by department"
                    class="bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 cursor-pointer shadow-xs">
                    <option value="">All Departments</option>
                    <option v-for="dept in employeeStore.departments" :key="dept.id" :value="dept.id">{{ dept.name }}</option>
                </select>

                <select v-model="attendanceStore.selectedStatus" @change="attendanceStore.fetchDailyAttendance"
                    aria-label="Filter attendance by status"
                    class="bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 cursor-pointer shadow-xs">
                    <option value="">All Statuses</option>
                    <option value="present">Present</option>
                    <option value="late">Late</option>
                    <option value="absent">Absent</option>
                    <option value="on_leave">On Leave</option>
                    <option value="early_out">Early Out</option>
                    <option value="half_day">Half Day</option>
                </select>

                <div class="relative">
                    <input v-model="attendanceStore.searchQuery" type="text" placeholder="Search employee or code..."
                        aria-label="Search employee by name or code"
                        class="bg-white border border-slate-200 rounded-lg pl-8 pr-3 py-1.5 text-xs text-slate-900 placeholder-slate-400 focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 w-56 shadow-xs" />
                    <span class="absolute left-2.5 top-2 text-slate-400 text-xs" aria-hidden="true">🔍</span>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <button @click="showManualModal = true" class="px-3.5 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-lg transition-colors flex items-center gap-1.5 shadow-xs cursor-pointer">
                    <span aria-hidden="true">✍️</span> Manual Punch
                </button>
                <button @click="handleFinalize" :disabled="attendanceStore.finalizing" class="px-3.5 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 text-xs font-semibold rounded-lg transition-colors flex items-center gap-1.5 cursor-pointer disabled:opacity-50">
                    <span :class="{'animate-spin': attendanceStore.finalizing}" aria-hidden="true">⚙️</span> Finalize Day
                </button>
                <button @click="attendanceStore.fetchDailyAttendance"
                    aria-label="Refresh daily attendance roster"
                    class="p-2 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-lg border border-slate-200 text-xs cursor-pointer transition-colors" title="Refresh">
                    <span aria-hidden="true">🔄</span>
                </button>
            </div>
        </div>
```

---

### 3. ROST-03: Complete List of Table Header Cells with `scope="col"`

In `resources/js/components/attendance/DailyAttendanceRoster.vue:50-61`:
All 8 table header `<th>` cells must include `scope="col"`.

```vue
                    <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500 font-semibold border-b border-slate-200">
                        <tr>
                            <th scope="col" class="px-4 py-3">Employee</th>
                            <th scope="col" class="px-4 py-3">Department</th>
                            <th scope="col" class="px-4 py-3">Shift</th>
                            <th scope="col" class="px-4 py-3">First In</th>
                            <th scope="col" class="px-4 py-3">Last Out</th>
                            <th scope="col" class="px-4 py-3">Work Hours</th>
                            <th scope="col" class="px-4 py-3">Status</th>
                            <th scope="col" class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
```

---

### 4. ROST-04: Skeleton Loader Design Matching Table Columns and Layout

In `resources/js/components/attendance/DailyAttendanceRoster.vue:63-68`:
Replace the single row `<tr v-if="attendanceStore.loading">` with 5 animated skeleton rows spanning all 8 columns with geometry matching the data rows:

```vue
                    <tbody class="divide-y divide-slate-100">
                        <!-- Skeleton Loading State (5 Rows x 8 Columns) -->
                        <template v-if="attendanceStore.loading">
                            <tr v-for="i in 5" :key="`skel-roster-${i}`" class="animate-pulse motion-reduce:animate-none">
                                <td class="px-4 py-3">
                                    <div class="flex items-center space-x-3">
                                        <div class="w-9 h-9 rounded-full bg-slate-200 shrink-0"></div>
                                        <div class="space-y-1.5">
                                            <div class="h-4 bg-slate-200 rounded w-28"></div>
                                            <div class="h-3 bg-slate-100 rounded w-16"></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="h-4 bg-slate-200 rounded w-20"></div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="h-4 bg-slate-200 rounded w-16"></div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="h-4 bg-slate-200 rounded w-12"></div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="h-4 bg-slate-200 rounded w-12"></div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="h-4 bg-slate-200 rounded w-14"></div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="h-5 bg-slate-200 rounded-full w-16"></div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end space-x-2">
                                        <div class="h-7 bg-slate-200 rounded-lg w-20"></div>
                                        <div class="h-7 bg-slate-200 rounded-lg w-14"></div>
                                    </div>
                                </td>
                            </tr>
                        </template>
                        <tr v-else-if="attendanceStore.filteredRoster.length === 0" class="text-center">
                            <td colspan="8" class="py-12 text-slate-500 text-xs">No attendance records found for this date.</td>
                        </tr>
                        <tr v-else v-for="item in attendanceStore.filteredRoster" :key="item.id || item.employee_id" class="hover:bg-slate-50/80 transition-colors">
```

---

### 5. ROST-05: Status Override Modal Specification

#### A. Template Markup
In `DailyAttendanceRoster.vue:120-149`:
```vue
        <!-- Status Override Modal -->
        <div
            v-if="showOverrideModal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="override-modal-title"
            tabindex="-1"
            class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm"
            @click.self="closeOverrideModal"
            @keydown.escape="closeOverrideModal"
        >
            <div class="bg-white border border-slate-200 rounded-2xl max-w-md w-full p-6 shadow-2xl space-y-4">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                    <h3 id="override-modal-title" class="text-base font-bold text-slate-900">Override Attendance Status</h3>
                    <button
                        type="button"
                        @click="closeOverrideModal"
                        aria-label="Close dialog"
                        class="text-slate-400 hover:text-slate-700 font-bold p-1 cursor-pointer rounded focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    >
                        ✕
                    </button>
                </div>
                <div class="space-y-3">
                    <div>
                        <label for="override-status" class="block text-xs font-semibold text-slate-700 mb-1">Status</label>
                        <select
                            id="override-status"
                            v-model="overrideForm.status"
                            class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs cursor-pointer"
                        >
                            <option value="present">Present</option>
                            <option value="late">Late</option>
                            <option value="early_out">Early Out</option>
                            <option value="half_day">Half Day</option>
                            <option value="on_leave">On Leave</option>
                            <option value="absent">Absent</option>
                        </select>
                    </div>
                    <div>
                        <label for="override-remarks" class="block text-xs font-semibold text-slate-700 mb-1">HR Remarks</label>
                        <textarea
                            id="override-remarks"
                            v-model="overrideForm.remarks"
                            rows="2"
                            class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 placeholder-slate-400 focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs"
                            placeholder="Reason for override..."
                        ></textarea>
                    </div>
                </div>
                <div class="flex justify-end space-x-2 pt-2 border-t border-slate-100">
                    <button
                        type="button"
                        @click="closeOverrideModal"
                        class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 rounded-xl text-xs font-semibold transition-colors cursor-pointer"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        @click="saveOverride"
                        class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-semibold transition-colors shadow-xs cursor-pointer"
                    >
                        Save
                    </button>
                </div>
            </div>
        </div>
```

#### B. Script Setup Additions
Update imports, helper methods, and lifecycle hooks in `<script setup>`:
```javascript
import { ref, onMounted, onUnmounted, reactive } from 'vue';
import { useAttendanceStore } from '../../stores/attendanceStore';
import { useEmployeeStore } from '../../stores/employeeStore';
import ManualAttendanceEntry from './ManualAttendanceEntry.vue';
import EmployeeAttendanceCalendar from './EmployeeAttendanceCalendar.vue';
import notify from '../../utils/notify';

// ... other reactive state ...

const closeOverrideModal = () => {
    showOverrideModal.value = false;
};

const handleGlobalKeydown = (e) => {
    if (e.key === 'Escape' && showOverrideModal.value) {
        closeOverrideModal();
    }
};

onMounted(() => {
    employeeStore.fetchMetadata();
    attendanceStore.fetchDailyAttendance();
    window.addEventListener('keydown', handleGlobalKeydown);
});

onUnmounted(() => {
    window.removeEventListener('keydown', handleGlobalKeydown);
});

const saveOverride = async () => {
    if (currentRecord.value?.id) {
        await attendanceStore.overrideStatus(currentRecord.value.id, overrideForm);
        closeOverrideModal();
    }
};
```

---

### 6. Acceptance Criteria Checklist

- [ ] **Zero Native Dialogs**: `grep -rn "confirm(" resources/js/components/attendance/DailyAttendanceRoster.vue` returns zero occurrences.
- [ ] **ROST-01 Functional Execution**: Calling `handleFinalize` displays the `notify.confirm` modal with title `'Finalize Daily Attendance'` and message mentioning `attendanceStore.selectedDate`.
- [ ] **ROST-01 Decision Logic**: Clicking "Cancel" drops execution without calling `attendanceStore.triggerDailyFinalizer()`; clicking "Yes, Finalize" executes `triggerDailyFinalizer()`.
- [ ] **ROST-02 ARIA Labels**: Date input, department select, status select, search input, and refresh button contain their specified `aria-label`s.
- [ ] **ROST-02 Icon Masking**: Search icon `🔍` and refresh icon `🔄` include `aria-hidden="true"`.
- [ ] **ROST-03 Table Header Semantics**: All 8 `<th>` cells in `<thead>` contain `scope="col"`.
- [ ] **ROST-04 Skeleton Geometry**: During `attendanceStore.loading`, 5 animated rows render with 8 columns matching the layout dimensions of real data rows.
- [ ] **ROST-04 Reduced Motion**: Skeleton rows include `motion-reduce:animate-none`.
- [ ] **ROST-05 Dialog Semantics**: Status Override modal container has `role="dialog"`, `aria-modal="true"`, `aria-labelledby="override-modal-title"`, and `tabindex="-1"`.
- [ ] **ROST-05 Title Association**: Header has `id="override-modal-title"`.
- [ ] **ROST-05 Close Button**: Close button has `type="button"` and `aria-label="Close dialog"`.
- [ ] **ROST-05 Form Bindings**: Status `<label for="override-status">` matches `<select id="override-status">`; Remarks `<label for="override-remarks">` matches `<textarea id="override-remarks">`.
- [ ] **ROST-05 Keyboard Dismissal**: Pressing `Escape` anywhere closes the modal; clicking backdrop (`@click.self`) closes the modal.
- [ ] **ROST-05 Cleanup**: Window `keydown` event listener is unregistered on `onUnmounted`.
- [ ] **Build Integrity**: `npm run build` succeeds with exit code 0.

---

## 5. Verification Method

1. **Static Analysis & Grep Checks**:
   ```bash
   # Check zero native confirm remaining
   grep -rn "confirm(" resources/js/components/attendance/DailyAttendanceRoster.vue
   # Expected: 0 matches (or only notify.confirm)

   # Check aria-labels in controls
   grep -rn "aria-label=" resources/js/components/attendance/DailyAttendanceRoster.vue
   # Expected: at least 6 matches (date, dept, status, search, refresh, modal close)

   # Check scope="col" in th
   grep -c 'scope="col"' resources/js/components/attendance/DailyAttendanceRoster.vue
   # Expected: 8

   # Check dialog semantics
   grep -rn 'role="dialog"' resources/js/components/attendance/DailyAttendanceRoster.vue
   # Expected: 1 match for override modal

   # Check form label associations
   grep -rn 'for="override-' resources/js/components/attendance/DailyAttendanceRoster.vue
   # Expected: 2 matches (override-status, override-remarks)
   ```

2. **Frontend Production Build**:
   ```bash
   npm run build
   ```
   Must exit with code 0 without any warnings or compilation errors.

3. **Behavioral Testing**:
   - Navigate to Attendance Hub -> Daily Roster.
   - Click "Finalize Day": Verify custom SweetAlert confirmation modal appears instead of browser alert dialog.
   - Click "Cancel": Confirm no network request sent and modal closes.
   - Click "Edit" on any employee row: Verify Status Override modal opens.
   - Press "Escape": Verify modal closes immediately.
   - Click "Status" label text: Verify status dropdown receives focus.
   - Click "HR Remarks" label text: Verify textarea receives focus.
   - Toggle simulated network throttle (slow 3G): Refresh page and verify 5 animated skeleton rows render before data arrives without visual layout shift.
