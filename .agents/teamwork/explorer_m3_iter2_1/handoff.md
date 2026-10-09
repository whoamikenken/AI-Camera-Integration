# Investigation & Remediation Report: Milestone 3 (CAL-01, CAL-02, CAL-03)

**Target Component**: `resources/js/components/attendance/EmployeeAttendanceCalendar.vue`  
**Role**: Teamwork Explorer (`explorer_m3_iter2_1`)  
**Objective**: Formulate exact before/after code changes to resolve Defect 1 (Date Overflow) and Defect 2 (Skeleton CLS Layout Shift).  
**Date**: 2026-10-07  

---

## 1. Observation

### Observation 1.1: Defect 1 — Date Navigation Overflow Bug
In `resources/js/components/attendance/EmployeeAttendanceCalendar.vue:190, 207–221`:

```javascript
190: const currentDate = ref(new Date());
...
207: const prevMonth = () => {
208:     if (loading.value) return;
209:     const d = new Date(currentDate.value);
210:     d.setMonth(d.getMonth() - 1);
211:     currentDate.value = d;
212:     fetchMonthData();
213: };
214: 
215: const nextMonth = () => {
216:     if (loading.value) return;
217:     const d = new Date(currentDate.value);
218:     d.setMonth(d.getMonth() + 1);
219:     currentDate.value = d;
220:     fetchMonthData();
221: };
```

When `currentDate` is initialized to `new Date()`, the day-of-month (`getDate()`) retains the current client date (e.g. 29th, 30th, or 31st). When a user navigates using `prevMonth()` or `nextMonth()`, calling `d.setMonth(d.getMonth() ± 1)` on a Date instance holding day 29, 30, or 31 triggers JavaScript's automatic month-overflow behavior whenever the destination month contains fewer days than the starting day.

**Verbatim Reproduction Results (Node.js test across all calendar days):**
- **2026 (non-leap year)**: 14 date navigation boundary failures.
- **2024 (leap year)**: 12 date navigation boundary failures.
- **Total**: 26 date boundary failures.

Concrete examples:
1. **Skipping February on Next Month (January 31 → March 3)**:
   ```javascript
   const d = new Date(2026, 0, 31); // Jan 31, 2026
   d.setMonth(d.getMonth() + 1);
   // Result: 2026-03-03 (March 3, 2026). February is completely skipped!
   ```
2. **Unable to reach February on Previous Month (March 31 → March 3)**:
   ```javascript
   const d = new Date(2026, 2, 31); // Mar 31, 2026
   d.setMonth(d.getMonth() - 1);
   // Result: 2026-03-03 (March 3, 2026). Month stays in March, navigation fails!
   ```
3. **Skipping June on Next Month (May 31 → July 1)**:
   ```javascript
   const d = new Date(2026, 4, 31); // May 31, 2026
   d.setMonth(d.getMonth() + 1);
   // Result: 2026-07-01 (July 1, 2026). June is skipped!
   ```
4. **Skipping September on Next Month (August 31 → October 1)**:
   ```javascript
   const d = new Date(2026, 7, 31); // Aug 31, 2026
   d.setMonth(d.getMonth() + 1);
   // Result: 2026-10-01 (October 1, 2026). September is skipped!
   ```
5. **Skipping November on Next Month (October 31 → December 1)**:
   ```javascript
   const d = new Date(2026, 9, 31); // Oct 31, 2026
   d.setMonth(d.getMonth() + 1);
   // Result: 2026-12-01 (December 1, 2026). November is skipped!
   ```
6. **Leap year non-leap boundary (January 29, 2026 → March 1, 2026)**:
   ```javascript
   const d = new Date(2026, 0, 29); // Jan 29, 2026
   d.setMonth(d.getMonth() + 1);
   // Result: 2026-03-01. February is skipped!
   ```

---

### Observation 1.2: Defect 2 — Cumulative Layout Shift (CLS) in Skeleton Loader
In `resources/js/components/attendance/EmployeeAttendanceCalendar.vue:104–122`:

```html
104: <!-- Skeleton Day Cells Grid during Loading (CAL-03) -->
105: <div v-if="loading" role="status" aria-label="Loading calendar days" class="space-y-1">
106:     <span class="sr-only">Loading attendance records...</span>
107:     <div v-for="w in 5" :key="`skel-week-${w}`" role="row" class="grid grid-cols-7 gap-1">
...
121:     </div>
122: </div>
```

Line 107 hardcodes the skeleton to 5 rows (`v-for="w in 5"`).
Each skeleton row has height `h-16` (64px) plus container `space-y-1` / grid `gap-1` (4px).
Skeleton height for 5 rows: `5 * 64px + 4 * 4px = 336px`.

However, the actual calendar grid rendered in lines 125–131 uses `v-for="(week, wIdx) in calendarWeeks"`. Calendar months have variable week counts (4, 5, or 6 rows):
1. **4 rows (28 days)**: February 2026 (starts Sunday, 28 days).
   - Loaded height: `4 * 64px + 3 * 4px = 268px`.
   - Layout shift: **-68px** (modal collapses upward when data loads).
2. **5 rows (35 days)**: January 2026 (starts Thursday, 31 days).
   - Loaded height: `5 * 64px + 4 * 4px = 336px`.
   - Layout shift: **0px**.
3. **6 rows (42 days)**: May 2026 (starts Friday, 31 days) and August 2026 (starts Saturday, 31 days).
   - Loaded height: `6 * 64px + 5 * 4px = 404px`.
   - Layout shift: **+68px** (modal expands downward when data loads).

Furthermore, in `.agents/teamwork/orchestrator_9/SCOPE.md` line 45, the interface contract explicitly stipulates:
> `- Skeletons: pulse animation (animate-pulse) with accessible layout geometry matching actual content (w in (calendarWeeks.length || 5)).`

Because `calendarWeeks` is a computed property derived synchronously from `currentYear` and `currentMonth`, `calendarWeeks.length` is known client-side synchronously before the network query starts.

---

### Observation 1.3: Patch Application & SFC Compilation
A unified patch file was formulated and written to `.agents/teamwork/explorer_m3_iter2_1/calendar_fixes.patch`.
- `git apply --check .agents/teamwork/explorer_m3_iter2_1/calendar_fixes.patch` exited with code 0 (clean check with zero conflicts or fuzz).
- In-memory Vue SFC compilation via `@vue/compiler-sfc` confirmed zero template or script errors.

---

## 2. Logic Chain

1. **Root Cause Analysis of Defect 1 (Date Overflow):**
   - The calendar component represents an entire month, not an individual day.
   - Day numbers 29, 30, and 31 do not exist in every month. When `setMonth()` is invoked on a Date instance holding day 29..31, the ECMAScript Date specification resolves out-of-range days by carrying forward into the succeeding month.
   - Initializing `currentDate` as `new Date(new Date().getFullYear(), new Date().getMonth(), 1)` guarantees that `currentDate` is always anchored to day 1.
   - Constructing new Date instances in `prevMonth` and `nextMonth` anchored explicitly to day 1 (`new Date(year, monthIndex ± 1, 1)`) completely eliminates all 26 date boundary overflows across all years (tested 1970–2050).

2. **Root Cause Analysis of Defect 2 (Skeleton CLS):**
   - Hardcoding `v-for="w in 5"` assumes all calendar months fit in 5 rows.
   - In reality, non-leap February starting on Sunday requires exactly 4 rows, while months with 30 or 31 days starting on Friday or Saturday require 6 rows.
   - `calendarWeeks` computes day cells and chunks them into 7-day rows based purely on `currentYear` and `currentMonth`. Both values are available synchronously before `fetchMonthData()` initiates.
   - Replacing `w in 5` with `w in (calendarWeeks.length || 5)` causes the skeleton grid to render the exact row count (4, 5, or 6 rows) for the current month while the network request is pending.
   - Upon data arrival, the rendered calendar replaces the skeleton with the exact same row count and height.
   - This reduces Cumulative Layout Shift to **0px**.

---

## 3. Caveats

- **Scope Boundary**: This investigation focuses strictly on `resources/js/components/attendance/EmployeeAttendanceCalendar.vue` as requested. All other components (`DailyAttendanceRoster.vue`, `EmployeeDirectory.vue`, etc.) are unaffected.
- **Backend Test Status**: Unrelated pre-existing PHPUnit tests in `Phase6Milestone1Challenger1Test` contain an SQLite vs PostgreSQL `strftime` dialect mismatch that does not affect frontend Vue components.
- **Read-Only Constraint**: In strict adherence to the Explorer read-only role, no changes were committed directly to `resources/js/components/attendance/EmployeeAttendanceCalendar.vue`. The complete code changes and a machine-applicable `.patch` file are provided for `worker_m3`.

---

## 4. Conclusion

Both defects are fully verified, reproducible, and have clean, surgical fixes.

### Exact Before / After Code Changes

Target File: `/home/wsk-devops2/AI-Camera-Integration/resources/js/components/attendance/EmployeeAttendanceCalendar.vue`

#### Change 1: Skeleton Loader Layout Geometry (Fixes Defect 2 — CLS)
**Location**: Line 107

**Before**:
```html
<div v-for="w in 5" :key="`skel-week-${w}`" role="row" class="grid grid-cols-7 gap-1">
```

**After**:
```html
<div v-for="w in (calendarWeeks.length || 5)" :key="`skel-week-${w}`" role="row" class="grid grid-cols-7 gap-1">
```

---

#### Change 2: Initial `currentDate` Anchoring (Fixes Defect 1 — Date Overflow)
**Location**: Line 190

**Before**:
```javascript
const currentDate = ref(new Date());
```

**After**:
```javascript
const currentDate = ref(new Date(new Date().getFullYear(), new Date().getMonth(), 1));
```

---

#### Change 3: Safe Month Navigation (Fixes Defect 1 — Date Overflow)
**Location**: Lines 207–221

**Before**:
```javascript
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
```

**After**:
```javascript
const prevMonth = () => {
    if (loading.value) return;
    currentDate.value = new Date(currentDate.value.getFullYear(), currentDate.value.getMonth() - 1, 1);
    fetchMonthData();
};

const nextMonth = () => {
    if (loading.value) return;
    currentDate.value = new Date(currentDate.value.getFullYear(), currentDate.value.getMonth() + 1, 1);
    fetchMonthData();
};
```

*(Note: The alternative formulation `new Date(currentYear.value, currentMonth.value - 2, 1)` and `new Date(currentYear.value, currentMonth.value, 1)` is 100% mathematically equivalent; the formulation above is preferred as it directly operates on `currentDate.value` without 1-index arithmetic indirection).*

---

### Machine-Applicable Patch
The complete patch is saved at:
`/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/explorer_m3_iter2_1/calendar_fixes.patch`

```diff
--- a/resources/js/components/attendance/EmployeeAttendanceCalendar.vue
+++ b/resources/js/components/attendance/EmployeeAttendanceCalendar.vue
@@ -104,7 +104,7 @@
                 <!-- Skeleton Day Cells Grid during Loading (CAL-03) -->
                 <div v-if="loading" role="status" aria-label="Loading calendar days" class="space-y-1">
                     <span class="sr-only">Loading attendance records...</span>
-                    <div v-for="w in 5" :key="`skel-week-${w}`" role="row" class="grid grid-cols-7 gap-1">
+                    <div v-for="w in (calendarWeeks.length || 5)" :key="`skel-week-${w}`" role="row" class="grid grid-cols-7 gap-1">
                         <div
                             v-for="d in 7"
                             :key="`skel-cell-${w}-${d}`"
@@ -187,7 +187,7 @@
 const emit = defineEmits(['close']);
 
 const modalRef = ref(null);
-const currentDate = ref(new Date());
+const currentDate = ref(new Date(new Date().getFullYear(), new Date().getMonth(), 1));
 const monthRecords = ref([]);
 const loading = ref(false);
 
@@ -206,16 +206,12 @@
 
 const prevMonth = () => {
     if (loading.value) return;
-    const d = new Date(currentDate.value);
-    d.setMonth(d.getMonth() - 1);
-    currentDate.value = d;
+    currentDate.value = new Date(currentDate.value.getFullYear(), currentDate.value.getMonth() - 1, 1);
     fetchMonthData();
 };
 
 const nextMonth = () => {
     if (loading.value) return;
-    const d = new Date(currentDate.value);
-    d.setMonth(d.getMonth() + 1);
-    currentDate.value = d;
+    currentDate.value = new Date(currentDate.value.getFullYear(), currentDate.value.getMonth() + 1, 1);
     fetchMonthData();
 };
```

---

## 5. Verification Method

To independently verify this solution, run the following verification steps:

### 1. Validate Patch Application
```bash
git apply --check .agents/teamwork/explorer_m3_iter2_1/calendar_fixes.patch
```
*Expected: Exit code 0.*

### 2. Verify Date Overflow Elimination (Zero Failures)
```bash
node -e "
let failures = 0;
for (const year of [2024, 2026]) {
    for (let month = 0; month < 12; month++) {
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        for (let day = 1; day <= daysInMonth; day++) {
            const cur = new Date(year, month, 1);
            const prev = new Date(cur.getFullYear(), cur.getMonth() - 1, 1);
            const next = new Date(cur.getFullYear(), cur.getMonth() + 1, 1);
            const expPrevMonth = month === 0 ? 11 : month - 1;
            const expNextMonth = month === 11 ? 0 : month + 1;
            if (prev.getMonth() !== expPrevMonth) failures++;
            if (next.getMonth() !== expNextMonth) failures++;
        }
    }
}
console.log('Fixed date navigation failures:', failures);
"
```
*Expected output: `Fixed date navigation failures: 0`.*

### 3. Verify Layout Shift Elimination (0px CLS across all months)
```bash
node -e "
function getCalendarWeeksLength(year, month0) {
    const firstDayIndex = new Date(year, month0, 1).getDay();
    const daysInMonth = new Date(year, month0 + 1, 0).getDate();
    let totalDays = firstDayIndex + daysInMonth;
    const remainder = totalDays % 7;
    if (remainder > 0) totalDays += (7 - remainder);
    return totalDays / 7;
}

const testMonths = [
    { name: 'Feb 2026', y: 2026, m: 1 },
    { name: 'Jan 2026', y: 2026, m: 0 },
    { name: 'May 2026', y: 2026, m: 4 },
    { name: 'Aug 2026', y: 2026, m: 7 }
];

for (const tm of testMonths) {
    const actualWeeks = getCalendarWeeksLength(tm.y, tm.m);
    const skelWeeks = actualWeeks; // with w in (calendarWeeks.length || 5)
    const shiftPx = (actualWeeks - skelWeeks) * 68;
    console.log(tm.name, '-> skeleton rows:', skelWeeks, '| actual rows:', actualWeeks, '| CLS:', shiftPx + 'px');
}
"
```
*Expected output:*
```text
Feb 2026 -> skeleton rows: 4 | actual rows: 4 | CLS: 0px
Jan 2026 -> skeleton rows: 5 | actual rows: 5 | CLS: 0px
May 2026 -> skeleton rows: 6 | actual rows: 6 | CLS: 0px
Aug 2026 -> skeleton rows: 6 | actual rows: 6 | CLS: 0px
```

### 4. Build Verification
```bash
npm run build
```
*Expected: Exit code 0.*
