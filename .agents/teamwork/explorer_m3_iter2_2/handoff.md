# Handoff Report: Date Arithmetic, Reactivity & CLS Analysis for EmployeeAttendanceCalendar.vue

**Agent**: `explorer_m3_iter2_2`  
**Target Component**: `resources/js/components/attendance/EmployeeAttendanceCalendar.vue`  
**Milestone**: Milestone 3, Iteration 2 (CAL-01, CAL-02, CAL-03)  
**Date**: 2026-10-07  

---

## 1. Observation

### Observation 1.1: Current Implementation State in EmployeeAttendanceCalendar.vue
Direct inspection of `resources/js/components/attendance/EmployeeAttendanceCalendar.vue`:
- **Current date ref initialization (line 190):**
  ```javascript
  190: const currentDate = ref(new Date());
  ```
- **Current year & month computeds (lines 194–196):**
  ```javascript
  194: const currentYear = computed(() => currentDate.value.getFullYear());
  195: const currentMonth = computed(() => currentDate.value.getMonth() + 1);
  196: const currentMonthName = computed(() => currentDate.value.toLocaleString('default', { month: 'long' }));
  ```
  Note: `currentMonth.value` is **1-indexed** (1 for January, 12 for December).
- **Current month navigation logic (lines 207–221):**
  ```javascript
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
- **Current skeleton grid row count (lines 105–107):**
  ```html
  105:                 <div v-if="loading" role="status" aria-label="Loading calendar days" class="space-y-1">
  106:                     <span class="sr-only">Loading attendance records...</span>
  107:                     <div v-for="w in 5" :key="`skel-week-${w}`" role="row" class="grid grid-cols-7 gap-1">
  ```
- **Calendar days and weeks computation (lines 292–347):**
  ```javascript
  292: const calendarDays = computed(() => {
  293:     const year = currentYear.value;
  294:     const month = currentMonth.value - 1;
  295:     const firstDayIndex = new Date(year, month, 1).getDay();
  296:     const daysInMonth = new Date(year, month + 1, 0).getDate();
  297:     const days = [];
  298: 
  299:     // Preceding empty/prev-month days
  300:     for (let i = 0; i < firstDayIndex; i++) {
  301:         days.push({ isCurrentMonth: false, dayNum: '' });
  302:     }
  ...
  329:     // Trailing empty days to complete the final 7-day row
  330:     const remainder = days.length % 7;
  331:     if (remainder > 0) {
  332:         for (let i = 0; i < 7 - remainder; i++) {
  333:             days.push({ isCurrentMonth: false, dayNum: '' });
  334:         }
  335:     }
  336:     return days;
  337: });
  340: const calendarWeeks = computed(() => {
  341:     const days = calendarDays.value;
  342:     const weeks = [];
  343:     for (let i = 0; i < days.length; i += 7) {
  344:         weeks.push(days.slice(i, i + 7));
  345:     }
  346:     return weeks;
  347: });
  ```

---

### Observation 1.2: Empirical Testing of Date Navigation Overflow in Current Code
Running empirical test of `d.setMonth(d.getMonth() ± 1)` without day normalization across all days of the year:
- Non-leap year 2026: **14 date navigation failures**.
- Leap year 2024: **12 date navigation failures**.
- Total: **26 failures**.
Concrete examples:
1. `new Date(2026, 0, 31)` (Jan 31): `nextMonth()` sets month to 1. `new Date(2026, 1, 31)` overflows to **March 3, 2026** (February is completely skipped).
2. `new Date(2026, 2, 31)` (Mar 31): `prevMonth()` sets month to 1. `new Date(2026, 1, 31)` overflows to **March 3, 2026** (fails to reach February, stays in March).
3. `new Date(2026, 4, 31)` (May 31): `nextMonth()` overflows to **July 1, 2026** (June is skipped).

---

### Observation 1.3: Empirical Verification of Proposed Date Normalization
Testing the proposed formula:
- `prevMonth`: `currentDate.value = new Date(currentYear.value, currentMonth.value - 2, 1)`
- `nextMonth`: `currentDate.value = new Date(currentYear.value, currentMonth.value, 1)`
Across 101 years (2000 through 2100), testing all 12 month transitions for both next and prev (2,424 transitions in total):
```text
Tested 2424 transitions from 2000 to 2100.
Total failures: 0
```
Sequential navigation test of 120 steps back (10 years) and 120 steps forward:
```text
120 prev steps SUCCESS (reached Jan 1, 2016)
120 next steps SUCCESS (returned to Jan 1, 2026)
```

---

### Observation 1.4: Empirical Testing of `calendarWeeks.length` Reactivity During Async Query
Testing Vue 3 reactive getter execution with `@vue/server-renderer` and reactive event loop simulation:
```text
Jan 2026 weeks (while loading): 5
Feb 2026 weeks (while loading): 4
Mar 2026 weeks (while loading): 5
Apr 2026 weeks (while loading): 5
May 2026 weeks (while loading): 6
Jun 2026 weeks (while loading): 5
Jul 2026 weeks (while loading): 5
Aug 2026 weeks (while loading): 6
Sep 2026 weeks (while loading): 5
Oct 2026 weeks (while loading): 5
Nov 2026 weeks (while loading): 5
Dec 2026 weeks (while loading): 5
```
During dynamic transition in same synchronous tick:
```text
Jan 2026: { state: 'content', count: 5 }
Jan -> Feb 2026: [ { state: 'skeleton', count: 4 }, { state: 'content', count: 4 } ]
Feb -> Mar 2026: [ { state: 'skeleton', count: 5 }, { state: 'content', count: 5 } ]
Apr -> May 2026: [ { state: 'skeleton', count: 6 }, { state: 'content', count: 6 } ]
```

---

### Observation 1.5: Grid Geometry and Layout Shift (CLS)
- Row height: `h-16` (64px, 4rem).
- Grid row gap: `gap-1` (4px, 0.25rem).
- Total height for $N$ rows: $N \times 64 + (N - 1) \times 4 = 68N - 4\text{px}$.
  - 4 rows: $4 \times 64 + 3 \times 4 = 268\text{px}$.
  - 5 rows: $5 \times 64 + 4 \times 4 = 336\text{px}$.
  - 6 rows: $6 \times 64 + 5 \times 4 = 404\text{px}$.
- With hardcoded `v-for="w in 5"` (336px):
  - Feb 2026 (4 rows): Skeleton is 336px, loaded is 268px $\rightarrow$ **-68px shift** upon data load.
  - May 2026 (6 rows): Skeleton is 336px, loaded is 404px $\rightarrow$ **+68px shift** upon data load.
- With `v-for="w in (calendarWeeks.length || 5)"`:
  - Feb 2026 (4 rows): Skeleton is 268px, loaded is 268px $\rightarrow$ **0px shift**.
  - May 2026 (6 rows): Skeleton is 404px, loaded is 404px $\rightarrow$ **0px shift**.
  - Jan 2026 (5 rows): Skeleton is 336px, loaded is 336px $\rightarrow$ **0px shift**.

---

## 2. Logic Chain

### 2.1 Mathematical & ECMAScript Proof of Date Arithmetic
1. **Index Conversion Proof**:
   - In `EmployeeAttendanceCalendar.vue`, `currentMonth` is defined as `currentDate.value.getMonth() + 1`, representing 1-indexed months $M \in [1, 12]$.
   - The constructor `new Date(year, monthIndex, day)` expects a 0-indexed month parameter $m \in [0, 11]$.
   - The current month's 0-indexed equivalent is $m = M - 1$.
   - **For Previous Month**:
     - Desired 0-indexed month index is $m - 1 = (M - 1) - 1 = M - 2$.
     - Hence: `new Date(currentYear.value, currentMonth.value - 2, 1)`.
   - **For Next Month**:
     - Desired 0-indexed month index is $m + 1 = (M - 1) + 1 = M$.
     - Hence: `new Date(currentYear.value, currentMonth.value, 1)`.

2. **Year Boundary Analysis (Dec -> Jan, Jan -> Dec)**:
   - **Jan -> Dec (Year Boundary Backwards)**:
     - When $M = 1$ (January): $M - 2 = 1 - 2 = -1$.
     - According to ECMA-262 § 21.4.3.1 (`MakeDay` & `MakeMonth`), month index `-1` resolves to month `11` (December) of `year - 1`.
     - `new Date(2026, -1, 1)` yields `2025-12-01` (December 1, 2025).
     - Day is fixed at `1`, which exists in every month.
   - **Dec -> Jan (Year Boundary Forwards)**:
     - When $M = 12$ (December): $M = 12$.
     - According to ECMA-262 § 21.4.3.1, month index `12` resolves to month `0` (January) of `year + 1`.
     - `new Date(2025, 12, 1)` yields `2026-01-01` (January 1, 2026).
     - Day is fixed at `1`.

3. **Leap Year & Month Length Invariance**:
   - Because `day` is explicitly passed as `1`, the day of the month never exceeds 1.
   - Month lengths vary between 28, 29, 30, and 31 days. Day 1 is universally valid for all Gregorian calendar months in every year (leap or non-leap).
   - In 2,424 sequential and random transitions across 101 years (2000–2100), 0 overflows or skipped months occurred (Observation 1.3).
   - In contrast, the current code's `d.setMonth(d.getMonth() ± 1)` preserves `d.getDate()`. When the active day is 29, 30, or 31, setting the month to February, April, June, September, or November overflows into the following month, causing 26 failure states (Observation 1.2).
   - Fixing `currentDate` initialization to `ref(new Date(new Date().getFullYear(), new Date().getMonth(), 1))` guarantees the day is 1 from initial mount.

---

### 2.2 Synchronous Reactivity of `calendarWeeks.length` During Async Queries
1. **Reactivity Dependency Graph**:
   ```text
   currentDate.value (ref)
       ├── currentYear (computed)
       └── currentMonth (computed)
               └── calendarDays (computed)
                       └── calendarWeeks (computed)
   ```
2. **Execution Timing during Navigation**:
   - When `nextMonth()` or `prevMonth()` is triggered:
     ```javascript
     currentDate.value = new Date(currentYear.value, currentMonth.value, 1);
     fetchMonthData();
     ```
   - In `fetchMonthData()`:
     ```javascript
     loading.value = true;
     const res = await apiClient.get(...); // Asynchronous suspension begins here
     ```
   - Both `currentDate.value` and `loading.value = true` are mutated in the **same synchronous call frame** before the microtask event loop yields to `await apiClient.get(...)`.
3. **Template Evaluation While Loading**:
   - Vue schedules a component re-render for the next DOM update tick.
   - At this tick, `loading.value === true`.
   - The template enters `<div v-if="loading">` and evaluates:
     `v-for="w in (calendarWeeks.length || 5)"`.
   - Evaluating `calendarWeeks.length` invokes the computed getter for `calendarWeeks`.
   - Because `currentDate.value` changed, `calendarWeeks` is dirty and re-evaluates `calendarDays`.
4. **Independence from `monthRecords.value`**:
   - In `calendarDays`:
     - `firstDayIndex = new Date(year, month, 1).getDay()` (weekday of 1st of month).
     - `daysInMonth = new Date(year, month + 1, 0).getDate()` (days in month).
     - Total cells in grid = `Math.ceil((firstDayIndex + daysInMonth) / 7) * 7`.
     - Total weeks in `calendarWeeks` = `Math.ceil((firstDayIndex + daysInMonth) / 7)`.
   - Notice: `firstDayIndex` and `daysInMonth` depend **solely** on `currentYear.value` and `currentMonth.value`.
   - `monthRecords.value` is only used inside the day loop to attach attendance status (`status`, `firstIn`, `lastOut`). It has **zero impact** on the length of `calendarDays` or `calendarWeeks`.
   - Therefore, `calendarWeeks.length` evaluates immediately, synchronously, and with 100% mathematical accuracy for the target month before the server response is received (Observation 1.4).

---

### 2.3 Layout Shift (CLS) Elimination & Timing Analysis
1. **W3C Cumulative Layout Shift (CLS) Specification**:
   - Cumulative Layout Shift measures unexpected layout shifts that occur *without recent user input*.
   - A layout shift that occurs within 500ms of user input (e.g. clicking "Next Month") has `hadRecentInput = true` and is **excluded** from CLS scoring.
   - A layout shift that occurs when an asynchronous network query completes (e.g. 200ms–1500ms later) has `hadRecentInput = false` and directly incurs a CLS penalty.
2. **Elimination of Asynchronous Shift**:
   - In the hardcoded 5-row implementation (`v-for="w in 5"`), for 4-week months (Feb 2026) or 6-week months (May 2026), the skeleton was rendered at 336px. When the async API returned, the loaded grid snapped to 268px (-68px) or 404px (+68px). This shift occurred upon network arrival, producing an unprompted, visible jank and CLS penalty.
   - With `v-for="w in (calendarWeeks.length || 5)"`:
     - The skeleton immediately renders at the exact height of the target month:
       - 4 rows = 268px.
       - 5 rows = 336px.
       - 6 rows = 404px.
     - When the network query resolves and `loading.value = false`, the active grid replaces the skeleton.
     - The active grid has the exact same row count and identical height ($68N - 4\text{px}$).
     - Height difference: $\Delta\text{Height} = 0\text{px}$.
     - Layout shift upon network resolution is **identically 0**.
3. **Re-Entrancy & Concurrency Safety**:
   - The navigation buttons include `:disabled="loading"` in the template (lines 39, 55).
   - Both `prevMonth()` and `nextMonth()` include early returns: `if (loading.value) return;` (lines 208, 216).
   - This prevents rapid multi-clicks while an async request is in flight, ensuring `currentDate` cannot become desynchronized from the in-flight request.
4. **Defensive Fallback Safety**:
   - Across 300 Gregorian calendar years (1900–2200), all months strictly produce 4, 5, or 6 weeks.
   - `calendarWeeks.length` is always a positive integer $\in \{4, 5, 6\}$.
   - It is never 0, `null`, `undefined`, or `NaN`.
   - The `|| 5` expression is a safe fallback guard with zero performance overhead.

---

## 3. Caveats

1. **Read-Only Scope**:
   In strict adherence to the Teamwork Explorer read-only protocol, no source files were modified in the repository. The proposed code replacements are documented below for `worker_m3` to implement.
2. **Timezone Considerations**:
   All date arithmetic uses local date construction `new Date(year, monthIndex, 1)`. In JavaScript, `getFullYear()` and `getMonth()` on dates constructed via `new Date(y, m, 1)` are timezone-consistent because midnight local time is used throughout. No UTC vs local drift occurs for 1st-of-month calculations.
3. **Pre-existing Unrelated Test Failure**:
   The existing backend PHPUnit test `Phase6Milestone1Challenger1Test` contains an SQLite `strftime` query syntax incompatibility unrelated to `EmployeeAttendanceCalendar.vue` or frontend components.

---

## 4. Conclusion

1. **Date Arithmetic Verification**:
   - `new Date(currentYear.value, currentMonth.value - 2, 1)` (Previous Month) and `new Date(currentYear.value, currentMonth.value, 1)` (Next Month) are **100% mathematically correct** and conformant with the ECMAScript Date specification.
   - They handle all 12 month transitions, year boundaries (Dec -> Jan, Jan -> Dec), and leap years with 0 failures across 2,424 transitions tested.
   - Anchoring `day = 1` completely resolves the 26 month-skipping overflow bugs in the current implementation.
   - `currentDate` should also be initialized to day 1: `ref(new Date(new Date().getFullYear(), new Date().getMonth(), 1))`.

2. **Reactivity Verification**:
   - `calendarWeeks` is a synchronous computed property.
   - When `currentDate.value` changes, `calendarWeeks.length` is computed immediately and synchronously within the same event loop tick.
   - `calendarWeeks.length` is strictly determined by the month's day count and start day; it does **not** depend on the asynchronous `monthRecords.value` fetch.
   - While `loading.value = true` during the async API query, `calendarWeeks.length` is already 100% accurate for the target month.

3. **CLS Elimination Verification**:
   - Replacing `v-for="w in 5"` with `v-for="w in (calendarWeeks.length || 5)"` guarantees that the skeleton grid geometry precisely matches the loaded grid geometry (4, 5, or 6 rows).
   - This eliminates the 68px layout shifts on 4-week months (Feb 2026) and 6-week months (May/Aug 2026).
   - Timing is completely safe due to synchronous computed evaluation and `:disabled="loading"` guards.

### Required Actionable Changes for `worker_m3`:

1. **Update `resources/js/components/attendance/EmployeeAttendanceCalendar.vue` line 107**:
   ```html
   <!-- Replace: -->
   <div v-for="w in 5" :key="`skel-week-${w}`" role="row" class="grid grid-cols-7 gap-1">

   <!-- With: -->
   <div v-for="w in (calendarWeeks.length || 5)" :key="`skel-week-${w}`" role="row" class="grid grid-cols-7 gap-1">
   ```

2. **Update `resources/js/components/attendance/EmployeeAttendanceCalendar.vue` line 190**:
   ```javascript
   // Replace:
   const currentDate = ref(new Date());

   // With:
   const currentDate = ref(new Date(new Date().getFullYear(), new Date().getMonth(), 1));
   ```

3. **Update `resources/js/components/attendance/EmployeeAttendanceCalendar.vue` lines 207–221**:
   ```javascript
   // Replace:
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

   // With:
   const prevMonth = () => {
       if (loading.value) return;
       currentDate.value = new Date(currentYear.value, currentMonth.value - 2, 1);
       fetchMonthData();
   };

   const nextMonth = () => {
       if (loading.value) return;
       currentDate.value = new Date(currentYear.value, currentMonth.value, 1);
       fetchMonthData();
   };
   ```

---

## 5. Verification Method

To independently verify these findings, execute the following commands in the workspace root:

1. **Verify Date Arithmetic Across 101 Years (2,424 transitions)**:
   ```bash
   node -e "
   let failures = 0;
   for (let y = 2000; y <= 2100; y++) {
       for (let m = 1; m <= 12; m++) {
           const prev = new Date(y, m - 2, 1);
           const expPrevYear = m === 1 ? y - 1 : y;
           const expPrevMonth = m === 1 ? 12 : m - 1;
           if (prev.getFullYear() !== expPrevYear || (prev.getMonth() + 1) !== expPrevMonth || prev.getDate() !== 1) failures++;

           const next = new Date(y, m, 1);
           const expNextYear = m === 12 ? y + 1 : y;
           const expNextMonth = m === 12 ? 1 : m + 1;
           if (next.getFullYear() !== expNextYear || (next.getMonth() + 1) !== expNextMonth || next.getDate() !== 1) failures++;
       }
   }
   console.log('Transitions tested: 2424, Failures:', failures);
   "
   ```
   *Expected Output: `Transitions tested: 2424, Failures: 0`*

2. **Verify Immediate `calendarWeeks.length` Reactivity During Loading State**:
   ```bash
   node -e "
   const { ref, computed } = require('vue');
   const currentDate = ref(new Date(2026, 0, 1));
   const loading = ref(false);
   const currentYear = computed(() => currentDate.value.getFullYear());
   const currentMonth = computed(() => currentDate.value.getMonth() + 1);
   const calendarDays = computed(() => {
       const y = currentYear.value;
       const m = currentMonth.value - 1;
       const firstDayIndex = new Date(y, m, 1).getDay();
       const daysInMonth = new Date(y, m + 1, 0).getDate();
       const days = [];
       for (let i = 0; i < firstDayIndex; i++) days.push({ isCurrentMonth: false });
       for (let d = 1; d <= daysInMonth; d++) days.push({ isCurrentMonth: true, d });
       const remainder = days.length % 7;
       if (remainder > 0) for (let i = 0; i < 7 - remainder; i++) days.push({ isCurrentMonth: false });
       return days;
   });
   const calendarWeeks = computed(() => {
       const days = calendarDays.value;
       const weeks = [];
       for (let i = 0; i < days.length; i += 7) weeks.push(days.slice(i, i + 7));
       return weeks;
   });
   // Test navigation to Feb 2026 (4 rows) and May 2026 (6 rows) while loading=true
   currentDate.value = new Date(currentYear.value, 1, 1);
   loading.value = true;
   console.log('Feb 2026 weeks while loading=true:', calendarWeeks.value.length); // 4
   currentDate.value = new Date(currentYear.value, 4, 1);
   console.log('May 2026 weeks while loading=true:', calendarWeeks.value.length); // 6
   "
   ```
   *Expected Output:*
   ```text
   Feb 2026 weeks while loading=true: 4
   May 2026 weeks while loading=true: 6
   ```

3. **Verify SFC Compilation with Proposed Changes**:
   ```bash
   node -e "
   const fs = require('fs');
   const { parse, compileScript, compileTemplate } = require('@vue/compiler-sfc');
   let content = fs.readFileSync('resources/js/components/attendance/EmployeeAttendanceCalendar.vue', 'utf8');
   content = content.replace('v-for=\"w in 5\"', 'v-for=\"w in (calendarWeeks.length || 5)\"');
   content = content.replace('const currentDate = ref(new Date());', 'const currentDate = ref(new Date(new Date().getFullYear(), new Date().getMonth(), 1));');
   content = content.replace('d.setMonth(d.getMonth() - 1);\\n    currentDate.value = d;', 'currentDate.value = new Date(currentYear.value, currentMonth.value - 2, 1);');
   content = content.replace('d.setMonth(d.getMonth() + 1);\\n    currentDate.value = d;', 'currentDate.value = new Date(currentYear.value, currentMonth.value, 1);');
   const { descriptor, errors } = parse(content);
   if (errors.length) { console.error(errors); process.exit(1); }
   compileScript(descriptor, { id: 'calendar-test' });
   compileTemplate({ id: 'calendar-test', filename: 'EmployeeAttendanceCalendar.vue', source: descriptor.template.content });
   console.log('Patched SFC compiles cleanly with 0 errors.');
   "
   ```
   *Expected Output: `Patched SFC compiles cleanly with 0 errors.`*
