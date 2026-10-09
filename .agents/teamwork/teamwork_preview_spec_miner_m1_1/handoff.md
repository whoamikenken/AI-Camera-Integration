# Milestone 1 Specification Report: Attendance Reports Optimization (REP-04, REP-05, REP-06)

**Target Component:** `resources/js/components/reports/AttendanceReports.vue`  
**Host Context:** `resources/js/components/reports/ReportsHub.vue`  
**Data Store:** `resources/js/stores/reportStore.js`  
**Date:** 2026-10-07  

---

## Features Discovered

| # | Category | Feature | Description | Inputs | Outputs | Error Behavior | Discovered Via |
|---|----------|---------|-------------|--------|---------|----------------|----------------|
| 1 | Accessibility & Forms | REP-04: Report Period Form Association | Explicit label-control binding for Report Period filter dropdown | `<label for="report-period">`, `<select id="report-period" v-model="reportType">` with options `daily` and `monthly` | Focusing/clicking label activates select dropdown; screen reader announces "Report Period, combo box" | Fallback to unassociated input if `id` or `for` mismatched | `tasks-optimization.md:137`, `AttendanceReports.vue:6-12` |
| 2 | Accessibility & Forms | REP-04: Date Filter Form Association | Explicit label-control binding for Daily date picker | `<label for="report-date">`, `<input id="report-date" v-model="selectedDate" type="date">` | Focusing/clicking label opens date picker; accessible name announced | Inactive when `reportType !== 'daily'` (`v-if` condition) | `tasks-optimization.md:137`, `AttendanceReports.vue:14-17` |
| 3 | Accessibility & Forms | REP-04: Month Filter Form Association | Explicit label-control binding for Monthly report month selector | `<label for="report-month">`, `<select id="report-month" v-model="selectedMonth">` (1-12) | Focusing/clicking label focuses month selector; announced as "Month" | Inactive when `reportType === 'daily'` (`v-else` condition); isolated from `payroll_month` | `tasks-optimization.md:137`, `AttendanceReports.vue:20-25` |
| 4 | Accessibility & Forms | REP-04: Year Filter Form Association | Explicit label-control binding for Monthly report year selector | `<label for="report-year">`, `<select id="report-year" v-model="selectedYear">` (2025, 2026) | Focusing/clicking label focuses year selector; announced as "Year" | Inactive when `reportType === 'daily'` (`v-else` condition); isolated from `payroll_year` | `tasks-optimization.md:137`, `AttendanceReports.vue:26-32` |
| 5 | Accessibility & Forms | REP-04: Department Filter Form Association | Explicit label-control binding for Department filter dropdown | `<label for="report-department">`, `<select id="report-department" v-model="selectedDepartmentId">` | Focusing/clicking label focuses department dropdown; announced as "Department" | Empty value defaults to "All Departments" | `tasks-optimization.md:137`, `AttendanceReports.vue:35-41` |
| 6 | CLS & Loading States | REP-05: 8-Column Skeleton Table Loader | Preserves table layout and eliminates Cumulative Layout Shift during async report generation | `reportStore.loading === true`, 8-column geometry matching table headers | 5 animated pulse skeleton rows (`animate-pulse`, `aria-busy="true"`) rendered within `<tbody>` | If request errors, store resets `loading = false`, showing empty state message | `tasks-optimization.md:138`, `AttendanceReports.vue:61-97` |
| 7 | Accessibility & States | REP-05: Accessible SVG Spinner for Report Generation | Replaces spinning raw emoji `⚡` with accessible SVG spinner honoring reduced motion | `reportStore.loading === true`, click on "Generate Report" button | Circular spinning SVG with `aria-hidden="true"`, `motion-reduce:animate-none`, button `:disabled="reportStore.loading"` and `:aria-busy="reportStore.loading"` | If query fails, store captures error, clears loading flag, returns to idle state | `tasks-optimization.md:138`, `AttendanceReports.vue:45-47` |
| 8 | Interaction & Debouncing | REP-06: Export CSV Loading & Disabled States | State tracking (`isExporting`), async guard to prevent duplicate clicks, and loading feedback | Click on "Export CSV" button | Button disabled (`:disabled="isExporting"`, `:aria-busy="isExporting"`), SVG spinner displayed, text "Exporting..." | Guard blocks concurrent re-triggers; `try/finally` guarantees reset on failure | `tasks-optimization.md:139`, `AttendanceReports.vue:48-50, 136-143` |

---

## Edge Cases

| # | Feature | Input | Observed Behavior |
|---|---------|-------|-------------------|
| 1 | REP-04 | Switching `reportType` between `'daily'` and `'monthly'` | When `'daily'`, `report-date` is present in DOM while `report-month` and `report-year` are unmounted via `v-else`. When `'monthly'`, `report-month` and `report-year` are mounted and `report-date` is unmounted. Both states maintain valid label associations without orphan or dangling references. |
| 2 | REP-04 | Sibling modal component IDs in `ReportsHub.vue` | `ReportsHub.vue` mounts both `AttendanceReports.vue` and `PayrollExportModal.vue`. `PayrollExportModal.vue` contains `id="payroll_month"` and `id="payroll_year"`. Assigning `id="report-month"` and `id="report-year"` (or `report_month` / `report_year`) guarantees zero DOM ID collisions. |
| 3 | REP-05 | Zero data returned after fetch completes | `reportStore.loading` transitions to `false`. When `reportData` has length 0, the skeleton rows are replaced with a single accessible table row `<td colspan="8" class="py-12 text-center text-slate-500 text-sm">` inside `<tbody>`, preserving table geometry. |
| 4 | REP-05 | System accessibility preference `prefers-reduced-motion: reduce` | SVG spinner and skeleton pulses include Tailwind `motion-reduce:animate-none` to prevent motion sickness for sensitive users. |
| 5 | REP-06 | Rapid double-click or multi-click on "Export CSV" | First click sets `isExporting.value = true`. The synchronous guard `if (isExporting.value) return;` immediately drops subsequent clicks, preventing multiple network requests and duplicate blob download triggers. |
| 6 | REP-06 | Network or server error during CSV export | In `exportReport`, wrapping `await reportStore.exportReport(...)` in a `try ... finally` block ensures `isExporting.value = false` executes on error, preventing the button from becoming permanently disabled. |
| 7 | REP-06 | Attempting export while report data is still being generated | Export button can be triggered independently or disabled while generating; setting `:disabled="isExporting || reportStore.loading"` prevents conflicting async operations. |

---

## 1. Observation

Direct code observations from inspection of `resources/js/components/reports/AttendanceReports.vue` (145 lines total):

1. **Unassociated Form Labels (`AttendanceReports.vue:7-41`)**:
   - Line 7: `<label class="block text-[10px] uppercase tracking-wider text-slate-500 font-bold mb-1">Report Period</label>` lacks `for` attribute.
   - Line 8: `<select v-model="reportType" ...>` lacks `id` attribute.
   - Line 15: `<label class="block text-[10px] uppercase tracking-wider text-slate-500 font-bold mb-1">Date</label>` lacks `for` attribute.
   - Line 16: `<input v-model="selectedDate" type="date" ...>` lacks `id` attribute.
   - Line 21: `<label class="block text-[10px] uppercase tracking-wider text-slate-500 font-bold mb-1">Month</label>` lacks `for` attribute.
   - Line 22: `<select v-model="selectedMonth" ...>` lacks `id` attribute.
   - Line 27: `<label class="block text-[10px] uppercase tracking-wider text-slate-500 font-bold mb-1">Year</label>` lacks `for` attribute.
   - Line 28: `<select v-model="selectedYear" ...>` lacks `id` attribute.
   - Line 36: `<label class="block text-[10px] uppercase tracking-wider text-slate-500 font-bold mb-1">Department</label>` lacks `for` attribute.
   - Line 37: `<select v-model="selectedDepartmentId" ...>` lacks `id` attribute.

2. **Plain Text Loading Placeholder & CLS (`AttendanceReports.vue:61-66`)**:
   ```vue
   61:             <div v-if="reportStore.loading" class="py-12 text-center text-slate-500 text-sm">
   62:                 Generating comprehensive report calculations...
   63:             </div>
   64:             <div v-else-if="!reportData || reportData.length === 0" class="py-12 text-center text-slate-500 text-sm">
   65:                 Click "Generate Report" to view analytics.
   66:             </div>
   67:             <div v-else class="overflow-x-auto">
   68:                 <table class="w-full text-left text-sm text-slate-700">
   ```
   During `reportStore.loading`, the entire table container is completely unmounted. When loading completes, `<table ...>` is suddenly rendered, causing massive Cumulative Layout Shift (CLS).

3. **Raw Emoji Spinner (`AttendanceReports.vue:45-47`)**:
   ```vue
   45:                 <button @click="generateReport" :disabled="reportStore.loading" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-xs font-semibold flex items-center gap-1.5 cursor-pointer shadow-xs transition-colors disabled:opacity-50">
   46:                     <span :class="{'animate-spin': reportStore.loading}">⚡</span> Generate Report
   47:                 </button>
   ```
   The emoji character `⚡` (lightning bolt) is rotated with `.animate-spin`. It lacks `aria-hidden="true"`, does not respect `prefers-reduced-motion`, and fails standard a11y expectations.

4. **Table Headers Lack Scope (`AttendanceReports.vue:69-80`)**:
   ```vue
   69:                     <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500 font-semibold border-b border-slate-200">
   70:                         <tr>
   71:                             <th class="px-4 py-3">Employee</th>
   72:                             <th class="px-4 py-3">Department</th>
   73:                             <th class="px-4 py-3">Present Days</th>
   74:                             <th class="px-4 py-3">Late Days</th>
   75:                             <th class="px-4 py-3">Absent Days</th>
   76:                             <th class="px-4 py-3">Leave Days</th>
   77:                             <th class="px-4 py-3">Total Hours</th>
   78:                             <th class="px-4 py-3">Overtime</th>
   79:                         </tr>
   80:                     </thead>
   ```
   The 8 table header `<th>` cells do not contain `scope="col"`.

5. **Unguarded and Stateless "Export CSV" Button (`AttendanceReports.vue:48-50, 136-143`)**:
   ```vue
   48:                 <button @click="exportReport('csv')" class="px-3 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 rounded-lg text-xs font-semibold flex items-center gap-1.5 cursor-pointer transition-colors">
   49:                     <span>📥</span> Export CSV
   50:                 </button>
   ```
   ```javascript
   136: const exportReport = (format) => {
   137:     reportStore.exportReport(reportType.value, format, {
   138:         date: selectedDate.value,
   139:         month: selectedMonth.value,
   140:         year: selectedYear.value,
   141:         department_id: selectedDepartmentId.value,
   142:     });
   143: };
   ```
   The button lacks `:disabled`, loading state feedback, and debounce/guarding. Double-clicking or clicking while a download is already in progress sends duplicate requests.
   In `resources/js/stores/reportStore.js:60`, `exportReport` is an `async` method returning a Promise that can be awaited.

---

## 2. Logic Chain

1. **REP-04 Label-ID Pairings**:
   - WCAG 2.1 Success Criterion 1.3.1 (Info and Relationships) and 4.1.2 (Name, Role, Value) mandate that form controls have accessible names linked via `<label for="id">` and `<input/select id="id">`.
   - In `AttendanceReports.vue`, there are exactly 5 form controls:
     1. Report Period: `for="report-period"` -> `id="report-period"`
     2. Date (when daily): `for="report-date"` -> `id="report-date"`
     3. Month (when monthly): `for="report-month"` -> `id="report-month"`
     4. Year (when monthly): `for="report-year"` -> `id="report-year"`
     5. Department: `for="report-department"` -> `id="report-department"`
   - Grep search confirmed zero occurrences of `id="report-*"` across the application. `PayrollExportModal.vue` uses `id="payroll_month"` and `id="payroll_year"`. The `report-*` prefix guarantees strict DOM ID uniqueness.

2. **REP-05 Layout Geometry & Skeleton Table**:
   - Replacing the text block with an 8-column skeleton table inside the permanent table structure keeps the DOM dimensions stable while fetching data, eliminating Cumulative Layout Shift (CLS).
   - Table headers define 8 columns:
     `Employee`, `Department`, `Present Days`, `Late Days`, `Absent Days`, `Leave Days`, `Total Hours`, `Overtime`.
   - The skeleton row must contain 8 corresponding `<td>` elements with pulse elements (`animate-pulse`) mirroring the content widths:
     - Employee: 2 lines (name `w-28 h-4`, code `w-16 h-3`)
     - Department: `w-20 h-4`
     - Present/Late/Absent/Leave Days: `w-8 h-4`
     - Total Hours / Overtime: `w-12 h-4`
   - Adding `scope="col"` to all 8 header `<th>` cells ensures screen readers properly associate data cells with headers.
   - For the "Generate Report" button:
     - Replace `<span :class="{'animate-spin': reportStore.loading}">⚡</span>` with an inline SVG circular spinner when `reportStore.loading === true`, with `aria-hidden="true"` and `motion-reduce:animate-none`.
     - When idle, display `<span aria-hidden="true">⚡</span>`.

3. **REP-06 Export Button States & Debouncing**:
   - The export process is asynchronous (`reportStore.exportReport` performs an API fetch and blob download).
   - Define a local reactive boolean `const isExporting = ref(false);`.
   - Inside `exportReport`:
     ```javascript
     const exportReport = async (format = 'csv') => {
         if (isExporting.value) return; // Guard
         isExporting.value = true;
         try {
             await reportStore.exportReport(reportType.value, format, {
                 date: selectedDate.value,
                 month: selectedMonth.value,
                 year: selectedYear.value,
                 department_id: selectedDepartmentId.value,
             });
         } finally {
             isExporting.value = false;
         }
     };
     ```
   - In template:
     - Bind `:disabled="isExporting || reportStore.loading"`
     - Bind `:aria-busy="isExporting"`
     - Add CSS classes `disabled:opacity-50 disabled:cursor-not-allowed`
     - Render spinning SVG when `isExporting` is true with text `Exporting...`
     - Render `📥` and `Export CSV` when idle.

---

## 3. Caveats

1. **Backend Route Path**: In `resources/js/stores/reportStore.js:62`, `exportReport` calls `apiClient.get('/reports/attendance/export', ...)`. In `routes/api.php:277`, the route is defined as `Route::get('reports/export', [ReportController::class, 'export'])`. Changing the backend API route or store is outside Milestone 1 scope (which is restricted to `AttendanceReports.vue`). However, the frontend implementation must gracefully handle promise resolution or rejection without hanging in `isExporting = true`.
2. **Reduced Motion**: All animations (`animate-pulse` and `animate-spin`) must include `motion-reduce:animate-none` to comply with WCAG 2.1 AA (Guideline 2.3.3 Animation from Interactions).
3. **No Implementation in this Turn**: As a Specification Miner, no files in `resources/js/` have been modified during this step.

---

## 4. Conclusion & Precise Specification

The exact requirements to implement in `resources/js/components/reports/AttendanceReports.vue` are:

### A. REP-04 Form Control Specifications
1. `<label for="report-period">` associated with `<select id="report-period" v-model="reportType">`.
2. `<label for="report-date">` associated with `<input id="report-date" v-model="selectedDate" type="date">`.
3. `<label for="report-month">` associated with `<select id="report-month" v-model="selectedMonth">`.
4. `<label for="report-year">` associated with `<select id="report-year" v-model="selectedYear">`.
5. `<label for="report-department">` associated with `<select id="report-department" v-model="selectedDepartmentId">`.

### B. REP-05 Table Geometry & Spinner Specifications
1. **Headers**: Add `scope="col"` to all 8 `<th>` elements:
   - `Employee`, `Department`, `Present Days`, `Late Days`, `Absent Days`, `Leave Days`, `Total Hours`, `Overtime`.
2. **Permanent Table Structure**: Move `<div class="overflow-x-auto"><table class="w-full text-left text-sm text-slate-700">` out of `v-else` so that it always wraps the results area.
3. **Skeleton Loading (`v-if="reportStore.loading"`)**:
   Render 5 table rows with `class="animate-pulse"` and `aria-busy="true"` containing 8 columns:
   ```vue
   <tr v-for="i in 5" :key="`skel-${i}`" class="animate-pulse" aria-busy="true">
       <td class="px-4 py-3">
           <div class="h-4 bg-slate-200 rounded w-28 mb-1"></div>
           <div class="h-3 bg-slate-200 rounded w-16"></div>
       </td>
       <td class="px-4 py-3"><div class="h-4 bg-slate-200 rounded w-20"></div></td>
       <td class="px-4 py-3"><div class="h-4 bg-slate-200 rounded w-8"></div></td>
       <td class="px-4 py-3"><div class="h-4 bg-slate-200 rounded w-8"></div></td>
       <td class="px-4 py-3"><div class="h-4 bg-slate-200 rounded w-8"></div></td>
       <td class="px-4 py-3"><div class="h-4 bg-slate-200 rounded w-8"></div></td>
       <td class="px-4 py-3"><div class="h-4 bg-slate-200 rounded w-12"></div></td>
       <td class="px-4 py-3"><div class="h-4 bg-slate-200 rounded w-12"></div></td>
   </tr>
   ```
4. **Empty State (`v-else-if="!reportData || reportData.length === 0"`)**:
   ```vue
   <tr>
       <td colspan="8" class="py-12 text-center text-slate-500 text-sm">
           Click "Generate Report" to view analytics.
       </td>
   </tr>
   ```
5. **Generate Button Accessible Spinner**:
   ```vue
   <button @click="generateReport" :disabled="reportStore.loading" :aria-busy="reportStore.loading" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-xs font-semibold flex items-center gap-1.5 cursor-pointer shadow-xs transition-colors disabled:opacity-50">
       <svg v-if="reportStore.loading" class="animate-spin h-3.5 w-3.5 text-white motion-reduce:animate-none" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
           <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
           <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
       </svg>
       <span v-else aria-hidden="true">⚡</span>
       <span>Generate Report</span>
   </button>
   ```

### C. REP-06 Export Button Specifications
1. State variable: `const isExporting = ref(false);`
2. Async guard function:
   ```javascript
   const exportReport = async (format = 'csv') => {
       if (isExporting.value) return;
       isExporting.value = true;
       try {
           await reportStore.exportReport(reportType.value, format, {
               date: selectedDate.value,
               month: selectedMonth.value,
               year: selectedYear.value,
               department_id: selectedDepartmentId.value,
           });
       } catch (err) {
           // Store notifies on error; caught here to guarantee finally execution
       } finally {
           isExporting.value = false;
       }
   };
   ```
3. Template markup:
   ```vue
   <button
       @click="exportReport('csv')"
       :disabled="isExporting"
       :aria-busy="isExporting"
       class="px-3 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 rounded-lg text-xs font-semibold flex items-center gap-1.5 cursor-pointer transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
   >
       <svg v-if="isExporting" class="animate-spin h-3.5 w-3.5 text-slate-700 motion-reduce:animate-none" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
           <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
           <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
       </svg>
       <span v-else aria-hidden="true">📥</span>
       <span>{{ isExporting ? 'Exporting...' : 'Export CSV' }}</span>
   </button>
   ```

---

## 5. Verification Method

To independently verify the implementation:

1. **Compilation Check**:
   ```bash
   npm run build
   ```
   Must exit with code 0 without template syntax or typing errors.

2. **Form Accessibility & DOM ID Verification**:
   - Verify every `<label>` has a matching `for` attribute corresponding to a control `id`.
   - Verify DOM IDs are unique across `ReportsHub.vue` and `AttendanceReports.vue`.
   - Verify clicking each label triggers focus on its corresponding form control.

3. **Cumulative Layout Shift & Skeleton Loader Verification**:
   - Inspect network throttling/slow 3G: Trigger "Generate Report".
   - The table header remains in place; 5 skeleton rows with pulse animation render in `<tbody>`.
   - No jumping or unmounting of `<table ...>`.
   - The button shows an SVG spinner; emoji `⚡` is not animated.

4. **Export CSV Button State & Debounce Verification**:
   - Trigger "Export CSV" and rapidly click multiple times.
   - Button immediately displays `:disabled`, `:aria-busy="true"`, "Exporting...", and SVG spinner.
   - Duplicate clicks are dropped by `if (isExporting.value) return`.
   - Once file download completes or fails, button unlocks back to "Export CSV".
