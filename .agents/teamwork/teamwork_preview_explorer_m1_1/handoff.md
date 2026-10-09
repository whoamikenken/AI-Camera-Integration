# Milestone 1 Handoff Report: Attendance Reports Optimization (REP-04, REP-05, REP-06)

## 1. Observation

### Target Files and Code Context
- **Target Component**: `resources/js/components/reports/AttendanceReports.vue` (145 lines)
- **Supporting Store**: `resources/js/stores/reportStore.js` (108 lines)
- **Parent Hub**: `resources/js/components/reports/ReportsHub.vue` (29 lines)

### Direct Code Observations in `AttendanceReports.vue`

#### Observation 1 (REP-04: Filter Labels and Form Controls Association)
In lines 6-41 of `resources/js/components/reports/AttendanceReports.vue`:
```html
6:                 <div>
7:                     <label class="block text-[10px] uppercase tracking-wider text-slate-500 font-bold mb-1">Report Period</label>
8:                     <select v-model="reportType" class="bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 cursor-pointer shadow-xs">
...
14:                 <div v-if="reportType === 'daily'">
15:                     <label class="block text-[10px] uppercase tracking-wider text-slate-500 font-bold mb-1">Date</label>
16:                     <input v-model="selectedDate" type="date" class="bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 cursor-pointer shadow-xs" />
...
20:                     <div>
21:                         <label class="block text-[10px] uppercase tracking-wider text-slate-500 font-bold mb-1">Month</label>
22:                         <select v-model="selectedMonth" class="bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 cursor-pointer shadow-xs">
...
26:                     <div>
27:                         <label class="block text-[10px] uppercase tracking-wider text-slate-500 font-bold mb-1">Year</label>
28:                         <select v-model="selectedYear" class="bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 cursor-pointer shadow-xs">
...
35:                 <div>
36:                     <label class="block text-[10px] uppercase tracking-wider text-slate-500 font-bold mb-1">Department</label>
37:                     <select v-model="selectedDepartmentId" class="bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 cursor-pointer shadow-xs">
```
- Line 7 (`Report Period` label): has no `for` attribute. Line 8 (`select`): has no `id` attribute.
- Line 15 (`Date` label): has no `for` attribute. Line 16 (`input type="date"`): has no `id` attribute.
- Line 21 (`Month` label): has no `for` attribute. Line 22 (`select`): has no `id` attribute.
- Line 27 (`Year` label): has no `for` attribute. Line 28 (`select`): has no `id` attribute.
- Line 36 (`Department` label): has no `for` attribute. Line 37 (`select`): has no `id` attribute.
- Result: Screen readers cannot programmatically connect filter labels with their input controls; clicking the label does not focus the input. Fails WCAG 2.1 Criteria 1.3.1 (Info and Relationships) and 4.1.2 (Name, Role, Value).

#### Observation 2 (REP-05: Loading Placeholder and Emoji Spinner)
In lines 45-47 and lines 61-80 of `resources/js/components/reports/AttendanceReports.vue`:
```html
45:                 <button @click="generateReport" :disabled="reportStore.loading" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-xs font-semibold flex items-center gap-1.5 cursor-pointer shadow-xs transition-colors disabled:opacity-50">
46:                     <span :class="{'animate-spin': reportStore.loading}">⚡</span> Generate Report
47:                 </button>
...
61:             <div v-if="reportStore.loading" class="py-12 text-center text-slate-500 text-sm">
62:                 Generating comprehensive report calculations...
63:             </div>
64:             <div v-else-if="!reportData || reportData.length === 0" class="py-12 text-center text-slate-500 text-sm">
65:                 Click "Generate Report" to view analytics.
66:             </div>
67:             <div v-else class="overflow-x-auto">
68:                 <table class="w-full text-left text-sm text-slate-700">
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
- Line 46 uses a raw emoji `⚡` with CSS class `animate-spin` (`{'animate-spin': reportStore.loading}`). This is non-semantic, does not communicate loading state to assistive technologies, and text reads as "High Voltage".
- Line 61 uses a simple text `<div>` that completely unmounts the `<table>` element (`v-if="reportStore.loading"` ... `v-else`). When report data loads, the entire table structure abruptly appears, causing significant Cumulative Layout Shift (CLS).
- The table contains 8 distinct columns (`Employee`, `Department`, `Present Days`, `Late Days`, `Absent Days`, `Leave Days`, `Total Hours`, `Overtime`), none of which have `scope="col"` on the `<th>` tags.

#### Observation 3 (REP-06: Export CSV Button Disabled & Loading Feedback)
In lines 48-50 and lines 136-143 of `resources/js/components/reports/AttendanceReports.vue`:
```html
48:                 <button @click="exportReport('csv')" class="px-3 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 rounded-lg text-xs font-semibold flex items-center gap-1.5 cursor-pointer transition-colors">
49:                     <span>📥</span> Export CSV
50:                 </button>
...
136: const exportReport = (format) => {
137:     reportStore.exportReport(reportType.value, format, {
138:         date: selectedDate.value,
139:         month: selectedMonth.value,
140:         year: selectedYear.value,
141:         department_id: selectedDepartmentId.value,
142:     });
143: };
```
- The "Export CSV" button has no `:disabled` attribute.
- The `exportReport` function in the script is not asynchronous and does not await `reportStore.exportReport(...)`.
- There is no reactive loading/exporting flag. If a user double-clicks or repeatedly clicks the button, multiple concurrent network requests (`/reports/attendance/export`) are triggered, producing duplicate file downloads and unnecessary server load.
- No visual feedback (spinner or text alteration) is shown to inform the user that file generation is underway.

---

## 2. Logic Chain

1. **REP-04 (Filter Labels & Inputs Association)**:
   - *Premise*: Under WCAG 2.1 Criteria 1.3.1 and 4.1.2, every visual form label must be programmatically paired with its corresponding control using either matching `for="..."` and `id="..."` attributes or wrapping.
   - *Observation Reference*: In lines 7-37 of `AttendanceReports.vue`, 5 separate controls (`Report Period`, `Date`, `Month`, `Year`, `Department`) lack `for` and `id`.
   - *Deduction*: Adding explicit, unique IDs (`report-period-select`, `report-date-input`, `report-month-select`, `report-year-select`, `report-department-select`) to the controls and matching `for="..."` attributes on the `<label>` elements establishes programmatic association and restores click-to-focus functionality.

2. **REP-05 (CLS Elimination via 8-Column Skeleton Table & Accessible SVG Spinner)**:
   - *Premise*: To eliminate Cumulative Layout Shift (CLS), the table layout and headers must persist during loading states, and table cells must be filled with skeleton placeholders matching the dimensions and count of actual data columns (8 columns).
   - *Premise 2*: Rotating raw emojis via CSS causes visual glitches across different platforms and fails WCAG accessibility. An SVG spinner with `aria-hidden="true"`, `motion-reduce:animate-none`, and `:aria-busy` provides standards-compliant feedback.
   - *Observation Reference*: Line 46 uses `<span :class="{'animate-spin': reportStore.loading}">⚡</span>`, and line 61 renders `<div v-if="reportStore.loading">` outside the table.
   - *Deduction*:
     - Replace the `v-if` on the table wrapper with a persistent table where `<div class="overflow-x-auto">` always wraps `<table>`.
     - In `<tbody>`, introduce `<template v-if="reportStore.loading">` rendering 5 skeleton rows (`<tr v-for="i in 5" :key="\`skel-report-\${i}\`" class="animate-pulse">`).
     - Structure the 8 skeleton `<td>` cells to mirror actual content: Col 1 has a dual-bar placeholder (name + code); Col 2 has department width (~w-20); Cols 3-6 have numeric badge widths (~w-10); Cols 7-8 have hours widths (~w-12).
     - Add `scope="col"` to all 8 `<th>` elements.
     - Replace `⚡` during loading with an inline SVG spinner (`class="animate-spin h-3.5 w-3.5 text-white motion-reduce:animate-none"`), and update button text dynamically (`{{ reportStore.loading ? 'Generating...' : 'Generate Report' }}`).

3. **REP-06 (Export CSV Disabled & Loading Feedback)**:
   - *Premise*: To prevent duplicate form submissions and provide clear feedback, async actions must be guarded by a reactive boolean flag (`isExporting = ref(false)`), the button must bind `:disabled="isExporting || reportStore.loading"`, and show a spinner with text like "Exporting...".
   - *Observation Reference*: In `reportStore.js`, `exportReport` is an `async` function returning a Promise. In `AttendanceReports.vue`, `exportReport` does not await or track state, and line 48 has no `:disabled`.
   - *Deduction*:
     - Declare `const isExporting = ref(false)`.
     - Refactor `exportReport` to `async (format) => { if (isExporting.value) return; isExporting.value = true; try { await reportStore.exportReport(...); } finally { isExporting.value = false; } }`.
     - Bind `:disabled="isExporting || reportStore.loading"` on the button with `disabled:opacity-50 disabled:cursor-not-allowed`.
     - Display an animated SVG spinner (`animate-spin h-3.5 w-3.5 text-slate-600 motion-reduce:animate-none`) when `isExporting` is true, and switch text to `Exporting...`.

---

## 3. Caveats

1. **Backend Export Response Type**:
   - `reportStore.exportReport` handles errors internally by catching and displaying a toast notification (`notify.error`). The proposed `try ... finally` block in `AttendanceReports.vue` guarantees that `isExporting.value` is reset even if the network fails or an error is thrown.
2. **Reduced Motion**:
   - For users with vestibular sensitivity (`prefers-reduced-motion: reduce`), Tailwind's `motion-reduce:animate-none` class is applied to both SVG spinners to align with global accessibility guidelines (DASH-02 / APP-05).
3. **No Breaking Store Changes**:
   - All proposed modifications are strictly contained within `resources/js/components/reports/AttendanceReports.vue`. No API endpoints, store schemas, or route definitions require modification.

---

## 4. Conclusion & Technical Proposal

The investigation confirms that tasks REP-04, REP-05, and REP-06 can be cleanly and comprehensively resolved in `resources/js/components/reports/AttendanceReports.vue`.

### Proposed Code Changes

#### A. Unified Diff Patch (`AttendanceReports.vue`)

```diff
--- a/resources/js/components/reports/AttendanceReports.vue
+++ b/resources/js/components/reports/AttendanceReports.vue
@@ -4,48 +4,82 @@
         <div class="flex flex-wrap items-center justify-between gap-3 bg-white border border-slate-200/80 p-5 rounded-2xl shadow-xs">
             <div class="flex flex-wrap items-center gap-3">
                 <div>
-                    <label class="block text-[10px] uppercase tracking-wider text-slate-500 font-bold mb-1">Report Period</label>
-                    <select v-model="reportType" class="bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 cursor-pointer shadow-xs">
+                    <label for="report-period-select" class="block text-[10px] uppercase tracking-wider text-slate-500 font-bold mb-1">Report Period</label>
+                    <select id="report-period-select" v-model="reportType" class="bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 cursor-pointer shadow-xs">
                         <option value="daily">Daily Summary</option>
                         <option value="monthly">Monthly Aggregate</option>
                     </select>
                 </div>
 
                 <div v-if="reportType === 'daily'">
-                    <label class="block text-[10px] uppercase tracking-wider text-slate-500 font-bold mb-1">Date</label>
-                    <input v-model="selectedDate" type="date" class="bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 cursor-pointer shadow-xs" />
+                    <label for="report-date-input" class="block text-[10px] uppercase tracking-wider text-slate-500 font-bold mb-1">Date</label>
+                    <input id="report-date-input" v-model="selectedDate" type="date" class="bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 cursor-pointer shadow-xs" />
                 </div>
 
                 <div v-else class="flex gap-2">
                     <div>
-                        <label class="block text-[10px] uppercase tracking-wider text-slate-500 font-bold mb-1">Month</label>
-                        <select v-model="selectedMonth" class="bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 cursor-pointer shadow-xs">
+                        <label for="report-month-select" class="block text-[10px] uppercase tracking-wider text-slate-500 font-bold mb-1">Month</label>
+                        <select id="report-month-select" v-model="selectedMonth" class="bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 cursor-pointer shadow-xs">
                             <option v-for="m in 12" :key="m" :value="m">{{ new Date(2026, m - 1).toLocaleString('default', { month: 'short' }) }}</option>
                         </select>
                     </div>
                     <div>
-                        <label class="block text-[10px] uppercase tracking-wider text-slate-500 font-bold mb-1">Year</label>
-                        <select v-model="selectedYear" class="bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 cursor-pointer shadow-xs">
+                        <label for="report-year-select" class="block text-[10px] uppercase tracking-wider text-slate-500 font-bold mb-1">Year</label>
+                        <select id="report-year-select" v-model="selectedYear" class="bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 cursor-pointer shadow-xs">
                             <option :value="2026">2026</option>
                             <option :value="2025">2025</option>
                         </select>
                     </div>
                 </div>
 
                 <div>
-                    <label class="block text-[10px] uppercase tracking-wider text-slate-500 font-bold mb-1">Department</label>
-                    <select v-model="selectedDepartmentId" class="bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 cursor-pointer shadow-xs">
+                    <label for="report-department-select" class="block text-[10px] uppercase tracking-wider text-slate-500 font-bold mb-1">Department</label>
+                    <select id="report-department-select" v-model="selectedDepartmentId" class="bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 cursor-pointer shadow-xs">
                         <option value="">All Departments</option>
                         <option v-for="dept in employeeStore.departments" :key="dept.id" :value="dept.id">{{ dept.name }}</option>
                     </select>
                 </div>
             </div>
 
             <div class="flex items-center gap-2 pt-3 sm:pt-0">
-                <button @click="generateReport" :disabled="reportStore.loading" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-xs font-semibold flex items-center gap-1.5 cursor-pointer shadow-xs transition-colors disabled:opacity-50">
-                    <span :class="{'animate-spin': reportStore.loading}">⚡</span> Generate Report
+                <button
+                    @click="generateReport"
+                    :disabled="reportStore.loading"
+                    class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed text-white rounded-lg text-xs font-semibold flex items-center gap-1.5 cursor-pointer shadow-xs transition-colors"
+                    :aria-busy="reportStore.loading"
+                >
+                    <svg
+                        v-if="reportStore.loading"
+                        class="animate-spin h-3.5 w-3.5 text-white motion-reduce:animate-none"
+                        fill="none"
+                        viewBox="0 0 24 24"
+                        aria-hidden="true"
+                    >
+                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
+                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
+                    </svg>
+                    <span v-else aria-hidden="true">⚡</span>
+                    <span>{{ reportStore.loading ? 'Generating...' : 'Generate Report' }}</span>
                 </button>
-                <button @click="exportReport('csv')" class="px-3 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 rounded-lg text-xs font-semibold flex items-center gap-1.5 cursor-pointer transition-colors">
-                    <span>📥</span> Export CSV
+                <button
+                    @click="exportReport('csv')"
+                    :disabled="isExporting || reportStore.loading"
+                    class="px-3 py-2 bg-slate-100 hover:bg-slate-200 disabled:opacity-50 disabled:cursor-not-allowed text-slate-700 border border-slate-200 rounded-lg text-xs font-semibold flex items-center gap-1.5 cursor-pointer transition-colors"
+                    :aria-busy="isExporting"
+                    aria-label="Export report to CSV spreadsheet"
+                >
+                    <svg
+                        v-if="isExporting"
+                        class="animate-spin h-3.5 w-3.5 text-slate-600 motion-reduce:animate-none"
+                        fill="none"
+                        viewBox="0 0 24 24"
+                        aria-hidden="true"
+                    >
+                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
+                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
+                    </svg>
+                    <span v-else aria-hidden="true">📥</span>
+                    <span>{{ isExporting ? 'Exporting...' : 'Export CSV' }}</span>
                 </button>
             </div>
         </div>
@@ -58,25 +92,49 @@
                 <span class="text-xs text-slate-500">Live Analytics Data</span>
             </div>
 
-            <div v-if="reportStore.loading" class="py-12 text-center text-slate-500 text-sm">
-                Generating comprehensive report calculations...
-            </div>
-            <div v-else-if="!reportData || reportData.length === 0" class="py-12 text-center text-slate-500 text-sm">
-                Click "Generate Report" to view analytics.
-            </div>
-            <div v-else class="overflow-x-auto">
+            <div class="overflow-x-auto">
                 <table class="w-full text-left text-sm text-slate-700">
                     <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500 font-semibold border-b border-slate-200">
                         <tr>
-                            <th class="px-4 py-3">Employee</th>
-                            <th class="px-4 py-3">Department</th>
-                            <th class="px-4 py-3">Present Days</th>
-                            <th class="px-4 py-3">Late Days</th>
-                            <th class="px-4 py-3">Absent Days</th>
-                            <th class="px-4 py-3">Leave Days</th>
-                            <th class="px-4 py-3">Total Hours</th>
-                            <th class="px-4 py-3">Overtime</th>
+                            <th scope="col" class="px-4 py-3">Employee</th>
+                            <th scope="col" class="px-4 py-3">Department</th>
+                            <th scope="col" class="px-4 py-3">Present Days</th>
+                            <th scope="col" class="px-4 py-3">Late Days</th>
+                            <th scope="col" class="px-4 py-3">Absent Days</th>
+                            <th scope="col" class="px-4 py-3">Leave Days</th>
+                            <th scope="col" class="px-4 py-3">Total Hours</th>
+                            <th scope="col" class="px-4 py-3">Overtime</th>
                         </tr>
                     </thead>
                     <tbody class="divide-y divide-slate-100">
+                        <template v-if="reportStore.loading">
+                            <tr v-for="i in 5" :key="`skel-report-${i}`" class="animate-pulse">
+                                <td class="px-4 py-3">
+                                    <div class="h-4 bg-slate-200 rounded w-28 mb-1.5"></div>
+                                    <div class="h-3 bg-slate-100 rounded w-16"></div>
+                                </td>
+                                <td class="px-4 py-3">
+                                    <div class="h-4 bg-slate-200 rounded w-20"></div>
+                                </td>
+                                <td class="px-4 py-3">
+                                    <div class="h-4 bg-slate-200 rounded w-10"></div>
+                                </td>
+                                <td class="px-4 py-3">
+                                    <div class="h-4 bg-slate-200 rounded w-10"></div>
+                                </td>
+                                <td class="px-4 py-3">
+                                    <div class="h-4 bg-slate-200 rounded w-10"></div>
+                                </td>
+                                <td class="px-4 py-3">
+                                    <div class="h-4 bg-slate-200 rounded w-10"></div>
+                                </td>
+                                <td class="px-4 py-3">
+                                    <div class="h-4 bg-slate-200 rounded w-12"></div>
+                                </td>
+                                <td class="px-4 py-3">
+                                    <div class="h-4 bg-slate-200 rounded w-12"></div>
+                                </td>
+                            </tr>
+                        </template>
+                        <tr v-else-if="!reportData || reportData.length === 0">
+                            <td colspan="8" class="py-12 text-center text-slate-500 text-sm">
+                                Click "Generate Report" to view analytics.
+                            </td>
+                        </tr>
                         <tr v-else v-for="(row, idx) in reportData" :key="idx" class="hover:bg-slate-50/80 transition-colors">
@@ -113,6 +171,7 @@
 const selectedMonth = ref(new Date().getMonth() + 1);
 const selectedYear = ref(2026);
 const selectedDepartmentId = ref('');
+const isExporting = ref(false);
 
 onMounted(() => {
     employeeStore.fetchMetadata();
@@ -134,8 +193,14 @@
     return reportStore.monthlyReportData?.data || reportStore.monthlyReportData || [];
 });
 
-const exportReport = (format) => {
-    reportStore.exportReport(reportType.value, format, {
-        date: selectedDate.value,
-        month: selectedMonth.value,
-        year: selectedYear.value,
-        department_id: selectedDepartmentId.value,
-    });
+const exportReport = async (format) => {
+    if (isExporting.value) return;
+    isExporting.value = true;
+    try {
+        await reportStore.exportReport(reportType.value, format, {
+            date: selectedDate.value,
+            month: selectedMonth.value,
+            year: selectedYear.value,
+            department_id: selectedDepartmentId.value,
+        });
+    } finally {
+        isExporting.value = false;
+    }
 };
 </script>
```

#### B. Full Replacement Content (`proposed_AttendanceReports.vue`)

```vue
<template>
    <div class="space-y-6">
        <!-- Date Range & Filters -->
        <div class="flex flex-wrap items-center justify-between gap-3 bg-white border border-slate-200/80 p-5 rounded-2xl shadow-xs">
            <div class="flex flex-wrap items-center gap-3">
                <div>
                    <label for="report-period-select" class="block text-[10px] uppercase tracking-wider text-slate-500 font-bold mb-1">Report Period</label>
                    <select id="report-period-select" v-model="reportType" class="bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 cursor-pointer shadow-xs">
                        <option value="daily">Daily Summary</option>
                        <option value="monthly">Monthly Aggregate</option>
                    </select>
                </div>

                <div v-if="reportType === 'daily'">
                    <label for="report-date-input" class="block text-[10px] uppercase tracking-wider text-slate-500 font-bold mb-1">Date</label>
                    <input id="report-date-input" v-model="selectedDate" type="date" class="bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 cursor-pointer shadow-xs" />
                </div>

                <div v-else class="flex gap-2">
                    <div>
                        <label for="report-month-select" class="block text-[10px] uppercase tracking-wider text-slate-500 font-bold mb-1">Month</label>
                        <select id="report-month-select" v-model="selectedMonth" class="bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 cursor-pointer shadow-xs">
                            <option v-for="m in 12" :key="m" :value="m">{{ new Date(2026, m - 1).toLocaleString('default', { month: 'short' }) }}</option>
                        </select>
                    </div>
                    <div>
                        <label for="report-year-select" class="block text-[10px] uppercase tracking-wider text-slate-500 font-bold mb-1">Year</label>
                        <select id="report-year-select" v-model="selectedYear" class="bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 cursor-pointer shadow-xs">
                            <option :value="2026">2026</option>
                            <option :value="2025">2025</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label for="report-department-select" class="block text-[10px] uppercase tracking-wider text-slate-500 font-bold mb-1">Department</label>
                    <select id="report-department-select" v-model="selectedDepartmentId" class="bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 cursor-pointer shadow-xs">
                        <option value="">All Departments</option>
                        <option v-for="dept in employeeStore.departments" :key="dept.id" :value="dept.id">{{ dept.name }}</option>
                    </select>
                </div>
            </div>

            <div class="flex items-center gap-2 pt-3 sm:pt-0">
                <button
                    @click="generateReport"
                    :disabled="reportStore.loading"
                    class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed text-white rounded-lg text-xs font-semibold flex items-center gap-1.5 cursor-pointer shadow-xs transition-colors"
                    :aria-busy="reportStore.loading"
                >
                    <svg
                        v-if="reportStore.loading"
                        class="animate-spin h-3.5 w-3.5 text-white motion-reduce:animate-none"
                        fill="none"
                        viewBox="0 0 24 24"
                        aria-hidden="true"
                    >
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span v-else aria-hidden="true">⚡</span>
                    <span>{{ reportStore.loading ? 'Generating...' : 'Generate Report' }}</span>
                </button>
                <button
                    @click="exportReport('csv')"
                    :disabled="isExporting || reportStore.loading"
                    class="px-3 py-2 bg-slate-100 hover:bg-slate-200 disabled:opacity-50 disabled:cursor-not-allowed text-slate-700 border border-slate-200 rounded-lg text-xs font-semibold flex items-center gap-1.5 cursor-pointer transition-colors"
                    :aria-busy="isExporting"
                    aria-label="Export report to CSV spreadsheet"
                >
                    <svg
                        v-if="isExporting"
                        class="animate-spin h-3.5 w-3.5 text-slate-600 motion-reduce:animate-none"
                        fill="none"
                        viewBox="0 0 24 24"
                        aria-hidden="true"
                    >
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span v-else aria-hidden="true">📥</span>
                    <span>{{ isExporting ? 'Exporting...' : 'Export CSV' }}</span>
                </button>
            </div>
        </div>

        <!-- Report Results -->
        <div class="bg-white border border-slate-200/80 rounded-2xl p-5 space-y-4 shadow-xs">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                <h3 class="font-bold text-slate-900 text-base">Generated Attendance Report</h3>
                <span class="text-xs text-slate-500">Live Analytics Data</span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-slate-700">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500 font-semibold border-b border-slate-200">
                        <tr>
                            <th scope="col" class="px-4 py-3">Employee</th>
                            <th scope="col" class="px-4 py-3">Department</th>
                            <th scope="col" class="px-4 py-3">Present Days</th>
                            <th scope="col" class="px-4 py-3">Late Days</th>
                            <th scope="col" class="px-4 py-3">Absent Days</th>
                            <th scope="col" class="px-4 py-3">Leave Days</th>
                            <th scope="col" class="px-4 py-3">Total Hours</th>
                            <th scope="col" class="px-4 py-3">Overtime</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <template v-if="reportStore.loading">
                            <tr v-for="i in 5" :key="`skel-report-${i}`" class="animate-pulse">
                                <td class="px-4 py-3">
                                    <div class="h-4 bg-slate-200 rounded w-28 mb-1.5"></div>
                                    <div class="h-3 bg-slate-100 rounded w-16"></div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="h-4 bg-slate-200 rounded w-20"></div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="h-4 bg-slate-200 rounded w-10"></div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="h-4 bg-slate-200 rounded w-10"></div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="h-4 bg-slate-200 rounded w-10"></div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="h-4 bg-slate-200 rounded w-10"></div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="h-4 bg-slate-200 rounded w-12"></div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="h-4 bg-slate-200 rounded w-12"></div>
                                </td>
                            </tr>
                        </template>
                        <tr v-else-if="!reportData || reportData.length === 0">
                            <td colspan="8" class="py-12 text-center text-slate-500 text-sm">
                                Click "Generate Report" to view analytics.
                            </td>
                        </tr>
                        <tr v-else v-for="(row, idx) in reportData" :key="idx" class="hover:bg-slate-50/80 transition-colors">
                            <td class="px-4 py-3 font-semibold text-slate-900">
                                {{ row.employee_name || row.name }}
                                <div class="text-xs text-slate-400 font-mono font-normal">{{ row.employee_code }}</div>
                            </td>
                            <td class="px-4 py-3 text-slate-600 text-xs">{{ row.department || row.department_name || 'General' }}</td>
                            <td class="px-4 py-3 font-bold text-emerald-600">{{ row.present_days ?? row.present_count ?? 0 }}</td>
                            <td class="px-4 py-3 font-bold text-amber-600">{{ row.late_days ?? row.late_count ?? 0 }}</td>
                            <td class="px-4 py-3 font-bold text-rose-600">{{ row.absent_days ?? row.absent_count ?? 0 }}</td>
                            <td class="px-4 py-3 font-bold text-indigo-600">{{ row.leave_days ?? row.leave_count ?? 0 }}</td>
                            <td class="px-4 py-3 font-mono text-xs font-semibold text-slate-800">{{ Number(row.total_hours || 0).toFixed(1) }}h</td>
                            <td class="px-4 py-3 font-mono text-xs font-bold text-amber-600">{{ Number(row.overtime_hours || 0).toFixed(1) }}h</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue';
import { useReportStore } from '../../stores/reportStore';
import { useEmployeeStore } from '../../stores/employeeStore';

const reportStore = useReportStore();
const employeeStore = useEmployeeStore();

const reportType = ref('monthly');
const selectedDate = ref(new Date().toISOString().slice(0, 10));
const selectedMonth = ref(new Date().getMonth() + 1);
const selectedYear = ref(2026);
const selectedDepartmentId = ref('');
const isExporting = ref(false);

onMounted(() => {
    employeeStore.fetchMetadata();
    generateReport();
});

const generateReport = async () => {
    if (reportType.value === 'daily') {
        await reportStore.fetchDailyReport(selectedDate.value, selectedDepartmentId.value);
    } else {
        await reportStore.fetchMonthlyReport(selectedMonth.value, selectedYear.value, selectedDepartmentId.value);
    }
};

const reportData = computed(() => {
    if (reportType.value === 'daily') {
        return reportStore.dailyReportData?.data || reportStore.dailyReportData || [];
    }
    return reportStore.monthlyReportData?.data || reportStore.monthlyReportData || [];
});

const exportReport = async (format) => {
    if (isExporting.value) return;
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
</script>
```

---

## 5. Verification Method

To verify the implementation once applied:

1. **Frontend Compilation Check**:
   Run:
   ```bash
   npm run build
   ```
   *Expected outcome*: Vite compiles cleanly with exit code 0, emitting updated chunks with zero syntax errors or template compilation warnings.

2. **Template Attribute Verification**:
   Inspect `resources/js/components/reports/AttendanceReports.vue`:
   - Verify `for="report-period-select"` matches `id="report-period-select"`.
   - Verify `for="report-date-input"` matches `id="report-date-input"`.
   - Verify `for="report-month-select"` matches `id="report-month-select"`.
   - Verify `for="report-year-select"` matches `id="report-year-select"`.
   - Verify `for="report-department-select"` matches `id="report-department-select"`.
   - Verify all 8 `<th>` cells contain `scope="col"`.
   - Verify `template v-if="reportStore.loading"` contains exactly 8 `<td>` elements in each skeleton row.
   - Verify no emoji `⚡` has class `animate-spin`.
   - Verify "Export CSV" button has `:disabled="isExporting || reportStore.loading"` and displays SVG spinner during export.

3. **Backend Test Suite Integrity**:
   Run:
   ```bash
   php artisan test
   ```
   *Expected outcome*: Complete test suite passes with zero regressions.
