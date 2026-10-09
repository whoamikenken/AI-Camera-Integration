# Investigation & Handoff Report: Milestone 3 (CAL-01, CAL-02, CAL-03)

**Component Target:** `resources/js/components/attendance/EmployeeAttendanceCalendar.vue`  
**Investigator:** `explorer_m3_1`  
**Date:** 2026-10-07  
**Status:** Complete

---

## 1. Observation

Direct code examination of `resources/js/components/attendance/EmployeeAttendanceCalendar.vue` (173 lines total) revealed the following findings across template and script:

### 1.1 CAL-01 (Modal Wrapper & Dialog Accessibility)
Lines 1–14 and 64–66 currently state:
```html
1: <template>
2:     <div v-if="isOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm" @click.self="close">
3:         <div class="bg-white border border-slate-200 rounded-2xl max-w-3xl w-full p-6 shadow-2xl space-y-5">
4:             <div class="flex items-center justify-between border-b border-slate-100 pb-4">
5:                 <div>
6:                     <h3 class="text-base font-bold text-slate-900 flex items-center gap-2">
7:                         <span>📅</span> Employee Attendance Calendar
8:                     </h3>
9:                     <p class="text-xs text-slate-500 mt-0.5">
10:                         {{ employee?.first_name }} {{ employee?.last_name || '' }} ({{ employee?.employee_code }}) — {{ currentMonthName }} {{ currentYear }}
11:                     </p>
12:                 </div>
13:                 <button @click="close" class="text-slate-400 hover:text-slate-700 font-bold p-1 cursor-pointer">✕</button>
14:             </div>
...
64:             <div class="flex justify-end pt-3 border-t border-slate-100">
65:                 <button @click="close" class="px-5 py-2 text-xs font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 border border-slate-200 rounded-xl transition-colors cursor-pointer">Close</button>
66:             </div>
67:         </div>
68:     </div>
69: </template>
```
- **Deficiencies:**
  - The modal root `<div>` lacks `role="dialog"`, `aria-modal="true"`, `aria-labelledby="calendar-modal-title"`, `tabindex="-1"`, and `@keydown.escape="close"`.
  - The `<h3>` title lacks the corresponding `id="calendar-modal-title"`, and the calendar emoji `📅` is unshielded from screen readers.
  - The top close button lacks `type="button"`, `aria-label="Close dialog"`, and accessible focus outline styles.
  - The bottom close button lacks explicit `type="button"`.

### 1.2 CAL-02 (Month Navigation Buttons Accessibility)
Lines 17–21 currently state:
```html
17:             <!-- Month Navigator & Summary Stats -->
18:             <div class="flex items-center justify-between bg-slate-50 border border-slate-200 p-3 rounded-xl">
19:                 <button @click="prevMonth" class="px-3 py-1 bg-white border border-slate-200 hover:bg-slate-100 text-xs font-semibold text-slate-700 rounded-lg cursor-pointer transition-colors shadow-2xs">← Prev</button>
20:                 <div class="text-sm font-bold text-slate-900">{{ currentMonthName }} {{ currentYear }}</div>
21:                 <button @click="nextMonth" class="px-3 py-1 bg-white border border-slate-200 hover:bg-slate-100 text-xs font-semibold text-slate-700 rounded-lg cursor-pointer transition-colors shadow-2xs">Next →</button>
22:             </div>
```
- **Deficiencies:**
  - Buttons lack `type="button"`.
  - Buttons lack `aria-label="Previous month"` and `aria-label="Next month"`.
  - The raw arrow characters (`←` and `→`) are read verbatim by screen readers without `aria-hidden="true"`.
  - Buttons have no `:disabled="loading"` or loading visual state, allowing rapid spam clicks during asynchronous queries.
  - The month/year heading container lacks `aria-live="polite"` / `aria-atomic="true"` announcements for dynamic month navigation.

### 1.3 CAL-03 (Calendar Grid Announcements & Skeleton Loading State)
Lines 42–62 currently state:
```html
42:             <!-- Calendar Grid -->
43:             <div class="space-y-1">
44:                 <div class="grid grid-cols-7 gap-1 text-center text-xs font-semibold text-slate-500 pb-1 uppercase tracking-wider">
45:                     <span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span>
46:                 </div>
47:                 <div class="grid grid-cols-7 gap-1">
48:                     <div v-for="(day, idx) in calendarDays" :key="idx"
49:                         :class="[
50:                             'h-16 p-1.5 rounded-lg border text-xs flex flex-col justify-between transition',
51:                             day.isCurrentMonth ? getStatusClass(day.status) : 'bg-slate-50/50 border-slate-100 text-slate-300'
52:                         ]">
53:                         <div class="flex justify-between items-center font-bold">
54:                             <span>{{ day.dayNum }}</span>
55:                             <span v-if="day.status && day.isCurrentMonth" class="text-[9px] uppercase tracking-tighter font-mono">{{ day.status }}</span>
56:                         </div>
57:                         <div v-if="day.firstIn && day.isCurrentMonth" class="text-[10px] text-slate-600 font-mono truncate font-medium">
58:                             In: {{ day.firstIn }}
59:                         </div>
60:                     </div>
61:                 </div>
62:             </div>
```
Lines 81–83, 97–125 in `<script setup>`:
```javascript
81: const currentDate = ref(new Date());
82: const monthRecords = ref([]);
...
97: const prevMonth = () => {
98:     const d = new Date(currentDate.value);
99:     d.setMonth(d.getMonth() - 1);
100:     currentDate.value = d;
101:     fetchMonthData();
102: };
103: 
104: const nextMonth = () => {
105:     const d = new Date(currentDate.value);
106:     d.setMonth(d.getMonth() + 1);
107:     currentDate.value = d;
108:     fetchMonthData();
109: };
110: 
111: const fetchMonthData = async () => {
112:     if (!props.employee?.id) return;
113:     try {
114:         const res = await apiClient.get('/attendance/records', {
115:             params: {
116:                 employee_id: props.employee.id,
117:                 month: currentMonth.value,
118:                 year: currentYear.value,
119:             }
120:         });
121:         monthRecords.value = res.data.data || res.data || [];
122:     } catch (e) {
123:         monthRecords.value = [];
124:     }
125: };
```
- **Deficiencies:**
  - There is no `loading` state ref in script; `fetchMonthData` runs without setting any loading indicator.
  - The calendar grid has no `role="grid"` or accessible name.
  - The day cells have no `role="gridcell"`, no `tabindex`, and no descriptive `aria-label`. A screen reader user navigating the cells only hears numbers like "1", "2" or terse text like "present", without date context or full status description.
  - Empty padding cells before the 1st of the month are rendered without `aria-hidden="true"`, causing screen reader focus noise.
  - There is no skeleton loading state during async queries. When switching months, the grid does not show any loading skeleton matching the grid geometry, leading to layout shifts (CLS) and lack of feedback.

---

## 2. Logic Chain

1. **Premise (CAL-01):** Under WCAG 2.1 Success Criterion 4.1.2 (Name, Role, Value) and 2.1.2 (No Keyboard Trap), modal dialogs must declare `role="dialog"`, `aria-modal="true"`, be labelled by their title via `aria-labelledby`, support dismissal via the Escape key, and provide an explicit accessible name on the close trigger.
   - *Direct inference:* Adding `role="dialog"`, `aria-modal="true"`, `aria-labelledby="calendar-modal-title"`, `tabindex="-1"`, and `@keydown.escape="close"` to the modal container, along with `id="calendar-modal-title"` on the `<h3>` and `aria-label="Close dialog"` on the button, satisfies CAL-01 and matches sibling dialogs (`ManualAttendanceEntry.vue`, `DailyAttendanceRoster.vue`).

2. **Premise (CAL-02):** Under WCAG 2.1 SC 1.3.1 (Info and Relationships) and SC 2.4.4 (Link/Button Purpose), navigation controls must clearly communicate their purpose.
   - *Direct inference:* The labels "← Prev" and "Next →" are ambiguous without month context. Adding `aria-label="Previous month"` and `aria-label="Next month"` with `aria-hidden="true"` on the arrows provides unambiguous accessible names.
   - Adding `:disabled="loading"` disables repeated asynchronous requests.
   - Adding `aria-live="polite"` on the month/year label informs assistive technology when the month changes.

3. **Premise (CAL-03):** Under WCAG 2.1 SC 4.1.2 and Core Web Vitals (CLS):
   - Grid announcements require `role="grid"` on the calendar container and `role="gridcell"` on each cell.
   - Descriptive `aria-label`s on day cells should announce the full date, humanized attendance status, and clock-in time (e.g., `October 7, 2026: Present, Clock-in at 08:58`).
   - Skeletons must match the dimensions and geometry of the content to prevent Cumulative Layout Shift (CLS).
   - In accordance with `SCOPE.md`, all pulsating animations must include `motion-reduce:animate-none`.
   - *Direct inference:* Introducing `const loading = ref(false)` in `fetchMonthData()` and rendering a 35-cell skeleton grid (`grid-cols-7 gap-1`, cells with `h-16 rounded-lg border animate-pulse motion-reduce:animate-none`) when `loading` is true completely eliminates CLS while preserving layout geometry.

---

## 3. Caveats

1. **Preceding/Trailing Day Padding:** `calendarDays` currently pushes empty cells (`{ isCurrentMonth: false, dayNum: '' }`) for the offset before the 1st day. These empty cells are marked with `aria-hidden="true"` and `tabindex="-1"` so screen readers bypass non-interactive filler cells.
2. **Reduced Motion Preference:** In accordance with the system design guidelines (`SCOPE.md`), the skeleton loader classes must combine `animate-pulse motion-reduce:animate-none`.
3. **No External Store Dependency:** `EmployeeAttendanceCalendar.vue` fetches data directly via `apiClient.get('/attendance/records', ...)` rather than through a shared Pinia store. Therefore, `loading` must be a local component `ref(false)`.

---

## 4. Conclusion & Concrete Changes

The exact file modifications to apply to `resources/js/components/attendance/EmployeeAttendanceCalendar.vue` are detailed below.

### 4.1 Script Modifications (`<script setup>`)

```javascript
// 1. Add loading state ref
const loading = ref(false);

// 2. Prevent concurrent clicks and guard navigation
const prevMonth = () => {
    if (loading.value) return;
    const d = new Date(currentDate.value);
    d.setMonth(d.getMonth() - 1);
    currentDate.value = d;
    fetchMonthData();
};

const nextMonth = () => {
    if (loading.value) return;
    const d = new Date(currentDate.value);
    d.setMonth(d.getMonth() + 1);
    currentDate.value = d;
    fetchMonthData();
};

// 3. Update fetchMonthData with loading lifecycle
const fetchMonthData = async () => {
    if (!props.employee?.id) return;
    loading.value = true;
    try {
        const res = await apiClient.get('/attendance/records', {
            params: {
                employee_id: props.employee.id,
                month: currentMonth.value,
                year: currentYear.value,
            }
        });
        monthRecords.value = res.data.data || res.data || [];
    } catch (e) {
        monthRecords.value = [];
    } finally {
        loading.value = false;
    }
};

// 4. Add accessible status label helper and day cell aria-label generator
const formatStatusLabel = (status) => {
    switch (status) {
        case 'present': return 'Present';
        case 'late': return 'Late';
        case 'late_and_early_out': return 'Late and Early Out';
        case 'early_out': return 'Early Out';
        case 'half_day': return 'Half Day';
        case 'absent': return 'Absent';
        case 'on_leave': return 'On Leave';
        case 'holiday': return 'Holiday';
        default: return status ? status.replace(/_/g, ' ') : 'No record';
    }
};

const getDayAriaLabel = (day) => {
    if (!day.isCurrentMonth || !day.dayNum) {
        return 'Empty';
    }
    const statusText = formatStatusLabel(day.status);
    const clockInText = day.firstIn ? `, Clock-in at ${day.firstIn}` : '';
    return `${currentMonthName.value} ${day.dayNum}, ${currentYear.value}: ${statusText}${clockInText}`;
};
```

### 4.2 Template Modifications (`<template>`)

```html
<template>
    <!-- CAL-01: Modal wrapper converted to semantic dialog with Escape handler -->
    <div 
        v-if="isOpen" 
        role="dialog"
        aria-modal="true"
        aria-labelledby="calendar-modal-title"
        tabindex="-1"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm" 
        @click.self="close"
        @keydown.escape="close"
    >
        <div class="bg-white border border-slate-200 rounded-2xl max-w-3xl w-full p-6 shadow-2xl space-y-5 animate-in fade-in zoom-in-95 duration-150">
            <div class="flex items-center justify-between border-b border-slate-100 pb-4">
                <div>
                    <!-- CAL-01: Title with id matching aria-labelledby and hidden decorative emoji -->
                    <h3 id="calendar-modal-title" class="text-base font-bold text-slate-900 flex items-center gap-2">
                        <span aria-hidden="true">📅</span> Employee Attendance Calendar
                    </h3>
                    <p class="text-xs text-slate-500 mt-0.5">
                        {{ employee?.first_name }} {{ employee?.last_name || '' }} ({{ employee?.employee_code }}) — {{ currentMonthName }} {{ currentYear }}
                    </p>
                </div>
                <!-- CAL-01: Close button with explicit aria-label -->
                <button 
                    type="button" 
                    @click="close" 
                    aria-label="Close dialog" 
                    class="text-slate-400 hover:text-slate-700 font-bold p-1 cursor-pointer rounded focus:outline-none focus:ring-2 focus:ring-indigo-500"
                >✕</button>
            </div>

            <!-- Month Navigator & Summary Stats -->
            <div class="flex items-center justify-between bg-slate-50 border border-slate-200 p-3 rounded-xl">
                <!-- CAL-02: Descriptive aria-label="Previous month" and disabled state -->
                <button 
                    type="button" 
                    @click="prevMonth" 
                    :disabled="loading"
                    aria-label="Previous month" 
                    class="px-3 py-1 bg-white border border-slate-200 hover:bg-slate-100 text-xs font-semibold text-slate-700 rounded-lg cursor-pointer transition-colors shadow-2xs disabled:opacity-50 disabled:cursor-not-allowed focus:outline-none focus:ring-2 focus:ring-indigo-500"
                >
                    <span aria-hidden="true">←</span> Prev
                </button>
                <!-- CAL-02: Live region announcement on month transition -->
                <div class="text-sm font-bold text-slate-900" aria-live="polite" aria-atomic="true">
                    {{ currentMonthName }} {{ currentYear }}
                </div>
                <!-- CAL-02: Descriptive aria-label="Next month" and disabled state -->
                <button 
                    type="button" 
                    @click="nextMonth" 
                    :disabled="loading"
                    aria-label="Next month" 
                    class="px-3 py-1 bg-white border border-slate-200 hover:bg-slate-100 text-xs font-semibold text-slate-700 rounded-lg cursor-pointer transition-colors shadow-2xs disabled:opacity-50 disabled:cursor-not-allowed focus:outline-none focus:ring-2 focus:ring-indigo-500"
                >
                    Next <span aria-hidden="true">→</span>
                </button>
            </div>

            <div class="grid grid-cols-4 gap-3 text-center text-xs">
                <div class="bg-emerald-50 border border-emerald-200 p-2.5 rounded-xl text-emerald-800">
                    <div class="text-xl font-bold font-mono">{{ stats.present }}</div>
                    <div class="text-[11px] font-medium text-emerald-700">Present Days</div>
                </div>
                <div class="bg-rose-50 border border-rose-200 p-2.5 rounded-xl text-rose-800">
                    <div class="text-xl font-bold font-mono">{{ stats.absent }}</div>
                    <div class="text-[11px] font-medium text-rose-700">Absent Days</div>
                </div>
                <div class="bg-amber-50 border border-amber-200 p-2.5 rounded-xl text-amber-800">
                    <div class="text-xl font-bold font-mono">{{ stats.late }}</div>
                    <div class="text-[11px] font-medium text-amber-700">Late Arrivals</div>
                </div>
                <div class="bg-indigo-50 border border-indigo-200 p-2.5 rounded-xl text-indigo-800">
                    <div class="text-xl font-bold font-mono">{{ stats.leave }}</div>
                    <div class="text-[11px] font-medium text-indigo-700">Leave Days</div>
                </div>
            </div>

            <!-- Calendar Grid (CAL-03) -->
            <div class="space-y-1">
                <div class="grid grid-cols-7 gap-1 text-center text-xs font-semibold text-slate-500 pb-1 uppercase tracking-wider" aria-hidden="true">
                    <span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span>
                </div>

                <!-- CAL-03: Skeleton Loading State during Async Month Queries -->
                <div 
                    v-if="loading" 
                    class="grid grid-cols-7 gap-1" 
                    role="grid" 
                    aria-label="Loading calendar data" 
                    aria-busy="true"
                >
                    <div 
                        v-for="i in 35" 
                        :key="`skel-cal-${i}`"
                        class="h-16 p-1.5 rounded-lg border border-slate-200/80 bg-slate-50 animate-pulse motion-reduce:animate-none flex flex-col justify-between"
                        role="gridcell"
                        aria-hidden="true"
                    >
                        <div class="flex justify-between items-center">
                            <div class="h-3.5 w-5 bg-slate-200 rounded"></div>
                            <div class="h-2.5 w-10 bg-slate-200 rounded"></div>
                        </div>
                        <div class="h-2.5 w-12 bg-slate-200 rounded"></div>
                    </div>
                </div>

                <!-- CAL-03: Accessible Calendar Grid with Announcements -->
                <div 
                    v-else 
                    class="grid grid-cols-7 gap-1"
                    role="grid"
                    :aria-label="`${currentMonthName} ${currentYear} attendance calendar`"
                >
                    <div 
                        v-for="(day, idx) in calendarDays" 
                        :key="idx"
                        role="gridcell"
                        :tabindex="day.isCurrentMonth ? 0 : -1"
                        :aria-label="getDayAriaLabel(day)"
                        :aria-hidden="!day.isCurrentMonth"
                        :class="[
                            'h-16 p-1.5 rounded-lg border text-xs flex flex-col justify-between transition focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:z-10',
                            day.isCurrentMonth ? getStatusClass(day.status) : 'bg-slate-50/50 border-slate-100 text-slate-300'
                        ]"
                    >
                        <div class="flex justify-between items-center font-bold">
                            <span>{{ day.dayNum }}</span>
                            <span v-if="day.status && day.isCurrentMonth" class="text-[9px] uppercase tracking-tighter font-mono">{{ day.status }}</span>
                        </div>
                        <div v-if="day.firstIn && day.isCurrentMonth" class="text-[10px] text-slate-600 font-mono truncate font-medium">
                            In: {{ day.firstIn }}
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex justify-end pt-3 border-t border-slate-100">
                <button 
                    type="button" 
                    @click="close" 
                    class="px-5 py-2 text-xs font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 border border-slate-200 rounded-xl transition-colors cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
                >Close</button>
            </div>
        </div>
    </div>
</template>
```

---

## 5. Verification Method

To independently verify the implementation:

1. **Compilation Check:**
   Run Vite production build:
   ```bash
   npm run build
   ```
   *Expected outcome:* The build succeeds with exit code 0 and bundles `resources/js/components/attendance/EmployeeAttendanceCalendar.vue` without errors.

2. **Template Attribute Verification:**
   Inspect the modified file to confirm:
   - Root wrapper has `role="dialog"`, `aria-modal="true"`, `aria-labelledby="calendar-modal-title"`, `tabindex="-1"`, `@keydown.escape="close"`.
   - Title heading has `id="calendar-modal-title"`.
   - Close button has `aria-label="Close dialog"`.
   - Navigation buttons have `aria-label="Previous month"` and `aria-label="Next month"`.
   - Calendar grid has `role="grid"` and day cells have `role="gridcell"`, `:aria-label="getDayAriaLabel(day)"`.
   - Skeleton grid has `v-if="loading"`, 35 cells, and `animate-pulse motion-reduce:animate-none`.

3. **Invalidation Conditions:**
   - Any failure of `npm run build`.
   - Missing `id="calendar-modal-title"` on heading or missing `aria-labelledby` on dialog wrapper.
   - Missing `loading` ref or failure of navigation buttons to disable during loading.
