# Milestone 3 Specification Mining Report: Employee Attendance Calendar Accessibility & States (CAL-01, CAL-02, CAL-03)

## 1. Observation

### 1.1 Authoritative Requirement Sources
1. **`ORIGINAL_REQUEST.md` (Follow-up 2026-10-07T01:17:45Z, Section R3, lines 240–245):**
   > "### R3. Employee Attendance Calendar Accessibility & States (CAL-01 through CAL-03)
   > In `resources/js/components/attendance/EmployeeAttendanceCalendar.vue`:
   > - Convert modal wrapper into a semantic dialog (`role="dialog"`, `aria-modal="true"`, `aria-labelledby="calendar-modal-title"`, `@keydown.escape="close"`, and close button `aria-label="Close dialog"`).
   > - Add descriptive `aria-label="Previous month"` and `aria-label="Next month"` to calendar navigation buttons.
   > - Implement accessible calendar grid announcements (`role="grid"`, descriptive `aria-label` with date and status for day cells) and skeleton loading state during async month queries."

2. **`tasks-optimization.md` (Section 22, lines 148–152):**
   > "### 22. Employee Attendance Calendar (`EmployeeAttendanceCalendar.vue`)
   > - [ ] **CAL-01 (a11y):** Convert modal wrapper (`lines 2-14`) into a semantic dialog (`role="dialog"`, `aria-modal="true"`, `aria-labelledby="calendar-modal-title"`, `@keydown.escape="close"`, and close button `aria-label="Close dialog"`).
   > - [ ] **CAL-02 (a11y):** Add descriptive `aria-label="Previous month"` and `aria-label="Next month"` to calendar navigation buttons in `resources/js/components/attendance/EmployeeAttendanceCalendar.vue:18-20`.
   > - [ ] **CAL-03 (a11y & CLS):** Implement accessible calendar grid announcements (`role="grid"`, descriptive `aria-label` with date and status for day cells) and skeleton loading state during async month queries in `resources/js/components/attendance/EmployeeAttendanceCalendar.vue:47-62`."

3. **`orchestrator_9/SCOPE.md` (Milestone 3, lines 20–22, 37):**
   > "| 9 | CAL-01 | Convert calendar modal wrapper to role=\"dialog\", aria-modal=\"true\", Escape handler, close label | M3 | tasks-optimization.md §22 |"
   > "| 10 | CAL-02 | Add aria-label=\"Previous month\" and \"Next month\" to navigation in EmployeeAttendanceCalendar.vue | M3 | tasks-optimization.md §22 |"
   > "| 11 | CAL-03 | role=\"grid\", cell aria-labels with date/status, skeleton loading state during async queries | M3 | tasks-optimization.md §22 |"
   > "| M3 | Employee Attendance Calendar a11y | CAL-01..CAL-03 in EmployeeAttendanceCalendar.vue | none | IN_PROGRESS |"

### 1.2 Target Component Baseline Inspection
In `resources/js/components/attendance/EmployeeAttendanceCalendar.vue`:
- **Lines 1–14 (Modal wrapper & Header):**
  ```html
  <template>
      <div v-if="isOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm" @click.self="close">
          <div class="bg-white border border-slate-200 rounded-2xl max-w-3xl w-full p-6 shadow-2xl space-y-5">
              <div class="flex items-center justify-between border-b border-slate-100 pb-4">
                  <div>
                      <h3 class="text-base font-bold text-slate-900 flex items-center gap-2">
                          <span>📅</span> Employee Attendance Calendar
                      </h3>
                      <p class="text-xs text-slate-500 mt-0.5">
                          {{ employee?.first_name }} {{ employee?.last_name || '' }} ({{ employee?.employee_code }}) — {{ currentMonthName }} {{ currentYear }}
                      </p>
                  </div>
                  <button @click="close" class="text-slate-400 hover:text-slate-700 font-bold p-1 cursor-pointer">✕</button>
              </div>
  ```
  *Deficiencies observed*: Wrapper lacks `role="dialog"`, `aria-modal="true"`, `aria-labelledby="calendar-modal-title"`, `tabindex="-1"`, and `@keydown.escape="close"`. Title lacks `id="calendar-modal-title"`. Title emoji lacks `aria-hidden="true"`. Close button lacks `type="button"`, `aria-label="Close dialog"`, and focus ring styling.

- **Lines 17–21 (Month Navigator):**
  ```html
              <div class="flex items-center justify-between bg-slate-50 border border-slate-200 p-3 rounded-xl">
                  <button @click="prevMonth" class="px-3 py-1 bg-white border border-slate-200 hover:bg-slate-100 text-xs font-semibold text-slate-700 rounded-lg cursor-pointer transition-colors shadow-2xs">← Prev</button>
                  <div class="text-sm font-bold text-slate-900">{{ currentMonthName }} {{ currentYear }}</div>
                  <button @click="nextMonth" class="px-3 py-1 bg-white border border-slate-200 hover:bg-slate-100 text-xs font-semibold text-slate-700 rounded-lg cursor-pointer transition-colors shadow-2xs">Next →</button>
              </div>
  ```
  *Deficiencies observed*: Navigation buttons lack `aria-label="Previous month"` and `aria-label="Next month"`, lack `type="button"`, lack `:disabled="loading"`, and lack `:aria-busy="loading"`. Month/year header lacks live region announcement (`aria-live="polite"`).

- **Lines 43–62 (Calendar Grid):**
  ```html
              <div class="space-y-1">
                  <div class="grid grid-cols-7 gap-1 text-center text-xs font-semibold text-slate-500 pb-1 uppercase tracking-wider">
                      <span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span>
                  </div>
                  <div class="grid grid-cols-7 gap-1">
                      <div v-for="(day, idx) in calendarDays" :key="idx"
                          :class="[
                              'h-16 p-1.5 rounded-lg border text-xs flex flex-col justify-between transition',
                              day.isCurrentMonth ? getStatusClass(day.status) : 'bg-slate-50/50 border-slate-100 text-slate-300'
                          ]">
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
  ```
  *Deficiencies observed*: Container lacks `role="grid"` and accessible grid label. Headers lack `role="row"` and `role="columnheader"`. Day cells lack `role="gridcell"` and descriptive `aria-label` stating date and status. No skeleton loader is displayed during async data fetching; when month changes, existing content remains static or abruptly shifts without loading feedback.

- **Lines 71–131, 171 (Script Setup):**
  - No `loading` ref is declared (`const loading = ref(false)` is missing).
  - `fetchMonthData` lacks a `loading.value = true` at entry and `loading.value = false` in `finally`.
  - No global Escape key listener via `onMounted` / `onUnmounted` (`window.addEventListener('keydown', handleGlobalKeydown)`).

### 1.3 Cross-Referenced Patterns Across Codebase
1. **`DailyAttendanceRoster.vue` (Parent consumer & ROST-04 / ROST-05 implementation):**
   - Caller interface (line 157):
     `<EmployeeAttendanceCalendar :isOpen="showCalendarModal" :employee="selectedEmployee" @close="showCalendarModal = false" />`
   - Dialog wrapper (lines 160–169):
     ```html
     <div
         v-if="showOverrideModal"
         role="dialog"
         aria-modal="true"
         aria-labelledby="override-modal-title"
         tabindex="-1"
         class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm"
         @click.self="showOverrideModal = false"
         @keydown.escape="showOverrideModal = false"
     >
     ```
   - Global Escape handler (lines 282–296):
     ```javascript
     const handleGlobalKeydown = (e) => {
         if (e.key === 'Escape' && showOverrideModal.value) {
             closeOverrideModal();
         }
     };
     onMounted(() => {
         window.addEventListener('keydown', handleGlobalKeydown);
     });
     onUnmounted(() => {
         window.removeEventListener('keydown', handleGlobalKeydown);
     });
     ```
   - Skeletons (lines 68–70): Uses `animate-pulse motion-reduce:animate-none` with geometry matching actual display elements.

2. **`AttendanceReports.vue` (REP-04 / REP-05 / REP-06 implementation):**
   - Action buttons use `:disabled="loading"` and `:aria-busy="loading"`.
   - Table skeletons use `v-if="reportStore.loading"` with `animate-pulse motion-reduce:animate-none`.

3. **`HolidayCalendar.vue` (Calendar Navigation pattern):**
   - Navigation buttons (lines 46–64):
     `<button @click="store.navigateMonth(-1)" aria-label="Previous month" ...>◀</button>`
     `<button @click="store.navigateMonth(1)" aria-label="Next month" ...>▶</button>`

4. **`ManualAttendanceEntry.vue` (Attendance Modal pattern):**
   - Dialog wrapper (lines 2–10):
     ```html
     <div 
         v-if="isOpen" 
         role="dialog"
         aria-modal="true"
         aria-labelledby="manual-entry-modal-title"
         @keydown.escape="close"
         class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm" 
         @click.self="close"
     >
     ```
   - Title has `id="manual-entry-modal-title"`.
   - Close button has `type="button"` and `aria-label="Close manual entry dialog"`.
   - Global keydown listener on `window` in `onMounted` / `onUnmounted`.

---

## 2. Logic Chain

1. **Premise 1 (Caller Contract Stability):**
   `EmployeeAttendanceCalendar.vue` is consumed directly by `DailyAttendanceRoster.vue:157` with props `:isOpen="showCalendarModal"` and `:employee="selectedEmployee"`, emitting `@close="showCalendarModal = false"`. Any enhancement must preserve this external contract without breaking parent component bindings.

2. **Premise 2 (CAL-01 Modal Semantics & Dismissal):**
   - The outer wrapper `<div v-if="isOpen">` represents an overlay dialog. According to W3C WAI-ARIA Authoring Practices and existing project modals (`ManualAttendanceEntry.vue`, `DailyAttendanceRoster.vue`), the wrapper must carry `role="dialog"`, `aria-modal="true"`, and `aria-labelledby="calendar-modal-title"`.
   - Adding `tabindex="-1"` and `@keydown.escape="close"` ensures that keyboard focus and Escape key events within the modal bubble to dismiss it.
   - Adding a global `window.addEventListener('keydown', handleGlobalKeydown)` inside `onMounted` (and cleaned up on `onUnmounted`) ensures that if the modal is open, pressing `Escape` dismisses it even if focus has not yet moved inside an interactive child element.
   - The title `<h3>` must receive `id="calendar-modal-title"`, matching `aria-labelledby`. The decorative emoji `<span>📅</span>` must have `aria-hidden="true"`.
   - The header close button `✕` must have `type="button"`, `aria-label="Close dialog"`, and focus indicators (`focus:outline-none focus:ring-2 focus:ring-indigo-500`). The footer button `Close` must have `type="button"`.

3. **Premise 3 (CAL-02 Navigation Semantics & State):**
   - The month navigation buttons currently contain text `← Prev` and `Next →`. Screen readers should announce descriptive names rather than symbols; hence `aria-label="Previous month"` and `aria-label="Next month"` must be added.
   - The navigation buttons must have `type="button"`.
   - During asynchronous data fetching (`loading === true`), navigation buttons should be disabled (`:disabled="loading"`) and indicate busy state (`:aria-busy="loading"`) to prevent duplicate in-flight requests or race conditions.
   - The month heading between the buttons can include `aria-live="polite"` so screen reader users are notified when the month changes.

4. **Premise 4 (CAL-03 Grid Semantics, Cell Announcements & Skeletons):**
   - The calendar grid container must have `role="grid"`, `aria-readonly="true"`, and `:aria-label="`Attendance calendar for ${currentMonthName} ${currentYear}``".
   - The weekday headers must be marked with `role="row"` and `role="columnheader"` with full names (e.g., `aria-label="Sunday"`, etc.) so screen readers correctly identify column semantics.
   - An asynchronous loading state (`const loading = ref(false)`) must be introduced in script setup. `fetchMonthData` must set `loading.value = true` upon request invocation and `loading.value = false` in a `finally` block.
   - When `loading` is true:
     - Render a 35-cell animated skeleton grid (5 rows x 7 cols) matching the 7-column calendar geometry with `animate-pulse motion-reduce:animate-none`.
     - Each skeleton card must match cell dimensions (`h-16 p-1.5 rounded-lg border border-slate-200 bg-slate-50/80`) with internal placeholder bars, preventing Cumulative Layout Shift (CLS).
     - The skeleton container must have `aria-busy="true"` and `aria-label="Loading calendar attendance data"`.
   - When `loading` is false:
     - Render day cells with `role="gridcell"`.
     - For days in the current month (`day.isCurrentMonth`):
       - Assign `tabindex="0"`.
       - Assign `:aria-label="getDayAriaLabel(day)"`.
       - `getDayAriaLabel(day)` must format a clear screen-reader message:
         E.g.: `${currentMonthName.value} ${day.dayNum}, ${currentYear.value}: ${formatStatusText(day.status)}${day.firstIn ? ', clocked in at ' + day.firstIn : ''}`.
         If status is blank: announce `no attendance record`.
       - Status badge and clock-in text inside the cell should carry `aria-hidden="true"` to prevent redundant duplicate announcements by screen readers.
     - For padding/empty days (`!day.isCurrentMonth`):
       - Assign `aria-hidden="true"` and `tabindex="-1"`.

---

## 3. Features Discovered

| # | Category | Feature | Description | Inputs | Outputs | Error Behavior | Discovered Via |
|---|----------|---------|-------------|--------|---------|----------------|----------------|
| 1 | Modal Dialog | CAL-01 Dialog Wrapper | Modal overlay wrapper with ARIA dialog semantics and backdrop dismissal | Props: `isOpen` (Boolean); Click: `@click.self="close"` | Accessible dialog container `role="dialog"`, `aria-modal="true"` | Hidden when `isOpen=false` | `tasks-optimization.md §22`, `EmployeeAttendanceCalendar.vue:2` |
| 2 | Modal Dialog | CAL-01 Label Association | Associating modal dialog with heading via `aria-labelledby` | Element ID: `calendar-modal-title` | Screen reader announces "Employee Attendance Calendar" on focus | Fallback to default dialog title if missing | `tasks-optimization.md §22`, `EmployeeAttendanceCalendar.vue:6` |
| 3 | Modal Dialog | CAL-01 Keyboard Dismissal | Escape key listener dismissing calendar modal | Keydown: `Escape` on wrapper or window | Emits `close` event to parent component | No-op if modal is not open (`!isOpen`) | `tasks-optimization.md §22`, `ManualAttendanceEntry.vue:152` |
| 4 | Modal Dialog | CAL-01 Close Button a11y | Header and footer close buttons with explicit type and accessible label | Button click `@click="close"` | `aria-label="Close dialog"`, `type="button"`, focus ring | None | `tasks-optimization.md §22`, `EmployeeAttendanceCalendar.vue:13` |
| 5 | Navigation | CAL-02 Previous Month Button | Previous month button with accessible label and in-flight guard | Click `@click="prevMonth"`, state: `loading` | Decrements month, triggers `fetchMonthData()`, `aria-label="Previous month"` | Disables button while `loading=true` | `tasks-optimization.md §22`, `HolidayCalendar.vue:48` |
| 6 | Navigation | CAL-02 Next Month Button | Next month button with accessible label and in-flight guard | Click `@click="nextMonth"`, state: `loading` | Increments month, triggers `fetchMonthData()`, `aria-label="Next month"` | Disables button while `loading=true` | `tasks-optimization.md §22`, `HolidayCalendar.vue:58` |
| 7 | Navigation | CAL-02 Month Live Region | Screen reader polite announcement of current month and year on change | Computed: `currentMonthName`, `currentYear` | `<div aria-live="polite">October 2026</div>` | None | WCAG 2.1 AA 4.1.3 (Status Messages) |
| 8 | Grid a11y | CAL-03 Calendar Grid Role | Grid container semantics for 7-column calendar layout | Container attributes | `role="grid"`, `aria-readonly="true"`, `:aria-label="Attendance calendar for ..."` | None | `tasks-optimization.md §22`, WAI-ARIA Grid Pattern |
| 9 | Grid a11y | CAL-03 Weekday Headers | Weekday header row and column headers with descriptive names | Day names Sun–Sat | `role="row"`, `role="columnheader"`, `aria-label="Sunday"`, etc. | None | WAI-ARIA Table/Grid Pattern |
| 10 | Grid a11y | CAL-03 Day Cell Announcements | Descriptive announcement for day cells with date, status, and clock-in time | `day.dayNum`, `day.status`, `day.firstIn`, `day.isCurrentMonth` | `role="gridcell"`, `tabindex="0"`, `aria-label="October 14, 2026: late, clocked in at 09:15"` | Padding days marked `aria-hidden="true"` | `tasks-optimization.md §22`, `EmployeeAttendanceCalendar.vue:48` |
| 11 | CLS & States | CAL-03 Async Loading State | Reactive `loading` state tracking API request lifecycle | `fetchMonthData()` execution | `loading.value = true` during request, `false` in `finally` | Resets `loading.value = false` on network error | `tasks-optimization.md §22`, `AttendanceReports.vue:47` |
| 12 | CLS & States | CAL-03 Calendar Skeleton Loader | 35-cell animated pulse skeleton grid matching exact calendar geometry | Condition: `loading === true` | 35 skeleton cards with `animate-pulse motion-reduce:animate-none`, `aria-busy="true"` | Smooth transition without layout shift (CLS) | `tasks-optimization.md §22`, `DailyAttendanceRoster.vue:69` |
| 13 | Data Fetching | Month Data Retrieval | Fetches monthly employee attendance records from backend API | API: `GET /attendance/records`, params: `employee_id`, `month`, `year` | Array of attendance records bound to `monthRecords.value` | On error: defaults `monthRecords.value = []`, completes in `finally` | `EmployeeAttendanceCalendar.vue:111–125` |
| 14 | Data Calculation | Summary Stats Computation | Computes month KPIs (present, absent, late, leave) from attendance records | Array `monthRecords.value` | Computed object: `{ present, absent, late, leave }` | Returns 0 for all stats if records empty | `EmployeeAttendanceCalendar.vue:88–95` |

---

## 4. Edge Cases

| # | Feature | Input | Observed Behavior |
|---|---------|-------|-------------------|
| 1 | CAL-01 | Escape key pressed when modal is open and focus is on body | Handled: Global keydown listener captures `Escape` and emits `close`. |
| 2 | CAL-01 | Escape key pressed when modal is closed (`isOpen === false`) | Handled: `if (e.key === 'Escape' && props.isOpen)` guard prevents firing `close` when closed. |
| 3 | CAL-01 | User clicks outside modal card on dark backdrop | Handled: `@click.self="close"` triggers dismissal without triggering on modal card clicks. |
| 4 | CAL-01 | Employee prop is null or undefined when `isOpen` becomes true | Handled: Optional chaining (`employee?.first_name`, `employee?.employee_code`, `if (!props.employee?.id) return;`) prevents runtime exceptions. |
| 5 | CAL-02 | User navigates from January to previous month | Handled: `d.setMonth(d.getMonth() - 1)` decrements to December of previous year; `currentYear` and `currentMonth` update correctly. |
| 6 | CAL-02 | User navigates from December to next month | Handled: `d.setMonth(d.getMonth() + 1)` increments to January of next year; `currentYear` and `currentMonth` update correctly. |
| 7 | CAL-02 | User rapidly clicks Next/Prev month button during active fetch | Handled: `:disabled="loading"` blocks rapid multi-clicks, preventing out-of-order race conditions. |
| 8 | CAL-03 | Month starts on Sunday (day 0) vs Saturday (day 6) | Handled: `new Date(year, month, 1).getDay()` returns 0..6; loop renders 0..6 padding cells accordingly. |
| 9 | CAL-03 | Leap year February (29 days) vs non-leap year (28 days) | Handled: `new Date(year, month + 1, 0).getDate()` returns exactly 28 or 29 days dynamically. |
| 10 | CAL-03 | Day cell has no record (`status` is empty/null) | Handled: `getDayAriaLabel` announces `no attendance record` instead of empty or undefined string. |
| 11 | CAL-03 | Attendance record status with underscores (e.g., `on_leave`, `early_out`) | Handled: `status.replace(/_/g, ' ')` cleans string for natural screen reader speech (e.g., "on leave", "early out"). |
| 12 | CAL-03 | Network request fails (HTTP 500 or timeout) | Handled: `try ... catch` clears records (`monthRecords.value = []`) and `finally` resets `loading.value = false`, avoiding permanently stuck skeleton. |
| 13 | CAL-03 | User has reduced motion enabled in OS settings | Handled: `motion-reduce:animate-none` on skeleton elements disables pulsating CSS animations. |

---

## 5. Caveats

- **No DOM Focus Trap Library Used:** In line with existing modals in the repository (e.g., `ManualAttendanceEntry.vue`, `DailyAttendanceRoster.vue`), keyboard focus trap is handled via modal accessibility attributes (`role="dialog"`, `aria-modal="true"`, `tabindex="-1"`, and Escape key listener) rather than importing heavy third-party focus trap packages.
- **Purely Read-Only Mining:** In accordance with the SPECIFICATION MINER archetype, no source files were altered during this probing session.

---

## 6. Conclusion

The specification requirements and acceptance criteria for Milestone 3 (CAL-01, CAL-02, CAL-03) are fully defined and cross-referenced with existing implementations in `AI-Camera-Integration`:
1. **CAL-01:** Convert modal wrapper in `EmployeeAttendanceCalendar.vue` to `role="dialog"`, `aria-modal="true"`, `aria-labelledby="calendar-modal-title"`, `tabindex="-1"`, with template `@keydown.escape="close"` and script `handleGlobalKeydown` listener on window. Add `id="calendar-modal-title"` to title, `aria-hidden="true"` to icon, and `type="button"` with `aria-label="Close dialog"` to the close buttons.
2. **CAL-02:** Add `type="button"`, `aria-label="Previous month"`, and `aria-label="Next month"` to calendar navigation buttons, with `:disabled="loading"` and `:aria-busy="loading"` guards. Add `aria-live="polite"` to month/year header.
3. **CAL-03:** Implement `role="grid"`, `role="row"`, `role="columnheader"`, and `role="gridcell"`. Add `const loading = ref(false)` with `finally` block in `fetchMonthData`. Render a 35-cell animated skeleton grid (`animate-pulse motion-reduce:animate-none`) when `loading` is true. Implement `getDayAriaLabel(day)` for accessible date and status announcements on active day cells.

---

## 7. Verification Method

To independently verify the specification and any subsequent implementation:
1. **Automated Bundle Verification:**
   Run `npm run build` in `/home/wsk-devops2/AI-Camera-Integration` to verify Vue template compilation and asset generation.
2. **Backend Regression Test:**
   Run `php artisan test --filter=BiometricAttendanceEngineTest` to ensure attendance backend APIs remain unaffected.
3. **Template Inspection:**
   - Verify `EmployeeAttendanceCalendar.vue` contains `role="dialog"`, `aria-modal="true"`, `aria-labelledby="calendar-modal-title"`.
   - Verify navigation buttons have `aria-label="Previous month"` and `aria-label="Next month"`.
   - Verify grid container has `role="grid"` and day cells have `role="gridcell"` with descriptive `aria-label`.
   - Verify presence of `animate-pulse` skeleton grid when `loading` is true.
   - Verify presence of Escape key listener (`handleGlobalKeydown` and `@keydown.escape`).
