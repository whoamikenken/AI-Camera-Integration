# Investigation & Handoff Report: Milestone 4 (EMP-06, EMP-07, EMP-08)

**Target Component:** `resources/js/components/employees/EmployeeDirectory.vue`  
**Investigator:** `explorer_m4_1`  
**Date:** 2026-10-08  
**Scope:** Milestone 4 (Workforce Directory & Modals: EMP-06, EMP-07, EMP-08)  
**Status:** Investigation Complete — Ready for Implementation

---

## 1. Observation

Direct inspection of `resources/js/components/employees/EmployeeDirectory.vue` (639 lines total) and related project modules (`resources/js/utils/notify.js`, `DailyAttendanceRoster.vue`, `AttendanceDashboard.vue`, `PersonnelManager.vue`, `EmployeeFormModal.vue`) revealed the following exact observations:

### 1.1 EMP-06: Employee Deletion Confirmation
In `resources/js/components/employees/EmployeeDirectory.vue`:
- **Current Lines 529–535** (Script imports):
  ```javascript
  <script setup>
  import { ref, reactive, onMounted } from 'vue';
  import { useEmployeeStore } from '../../stores/employeeStore';
  import EmployeeProfileModal from './EmployeeProfileModal.vue';
  import EmployeeFormModal from './EmployeeFormModal.vue';
  ```
  `notify` utility is **not imported**.
- **Current Lines 605–610** (`confirmDelete` function):
  ```javascript
  async function confirmDelete(emp) {
    const confirmed = confirm(`Are you sure you want to delete ${emp.first_name} ${emp.last_name}? This will revoke camera biometric access.`);
    if (confirmed) {
      await store.deleteEmployee(emp.id);
    }
  }
  ```
- **Reference Implementation (`resources/js/utils/notify.js:91–105`):**
  ```javascript
  async confirm(title, text = '', confirmButtonText = 'Yes, Proceed', cancelButtonText = 'Cancel', isDestructive = false) {
      const result = await customSwal.fire({
          title,
          text,
          icon: isDestructive ? 'warning' : 'question',
          iconColor: isDestructive ? '#f43f5e' : '#6366f1',
          showCancelButton: true,
          confirmButtonText,
          cancelButtonText,
          reverseButtons: true,
          confirmButtonColor: isDestructive ? '#e11d48' : '#4f46e5',
      });
      return result.isConfirmed;
  }
  ```
  In `DailyAttendanceRoster.vue:260, 303–307` and `PersonnelManager.vue:458–466`, `notify.confirm()` is called with `isDestructive = true` for irreversible deletions and returns a boolean Promise.
- **Deficiency:**
  - `confirm(...)` invokes the synchronous, blocking browser-native dialog (`window.confirm`), which violates WCAG 2.1 AA dialog accessibility standards, cannot be styled or keyboard-trapped consistently, freezes browser tabs, and disrupts assistive technologies.

---

### 1.2 EMP-07: Loading State & Cumulative Layout Shift (CLS)
In `resources/js/components/employees/EmployeeDirectory.vue`:
- **Current Lines 165–170**:
  ```html
  <!-- Loading Skeleton -->
  <div v-if="store.loading" class="bg-white rounded-2xl p-12 text-center border border-slate-200 shadow-xs">
    <div class="inline-block animate-spin text-2xl text-indigo-600 mb-2">⏳</div>
    <div class="text-xs font-semibold text-slate-600">Loading workforce directory...</div>
  </div>
  ```
- **Geometry of Table View (`store.viewMode === 'table'`, lines 189–203):**
  The table has 7 columns:
  1. `Employee` (`px-5 py-3.5`): avatar + name + contact
  2. `Code` (`px-4 py-3.5`): employee code
  3. `Department & Role` (`px-4 py-3.5`): department + designation
  4. `Shift Schedule` (`px-4 py-3.5`): shift badge
  5. `Status` (`px-4 py-3.5`): status pill
  6. `Camera Face Biometrics` (`px-4 py-3.5`): biometric link badge
  7. `Actions` (`px-5 py-3.5 text-right`): 4 action buttons
- **Geometry of Card Grid View (`store.viewMode === 'grid'`, lines 350–394):**
  A 3-column responsive grid (`grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4`) containing employee cards with a 14x14 avatar, employee info, and bottom actions bar.
- **Deficiency:**
  - The current loader renders a single centered `⏳` spinner with a fixed height (~150px) regardless of whether table or grid mode is active. When employees load, the layout expands drastically (800+ px), causing severe Cumulative Layout Shift (CLS).
  - Lacks `animate-pulse` and `motion-reduce:animate-none` for users who have requested reduced motion preferences.

---

### 1.3 EMP-08: Assign Shift Modal & CSV Bulk Import Modal
In `resources/js/components/employees/EmployeeDirectory.vue`:
- **Current Lines 434–490 (Assign Shift Modal):**
  ```html
  <!-- 3. Assign Shift Modal -->
  <div v-if="showShiftModal && selectedEmployee" class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-xl border border-slate-200 max-w-md w-full p-6 space-y-4">
      <h3 class="text-sm font-bold text-slate-900">
        Assign Shift Schedule: {{ selectedEmployee.first_name }} {{ selectedEmployee.last_name }}
      </h3>

      <div class="space-y-3 text-xs">
        <div>
          <label class="block font-semibold text-slate-700 mb-1">Target Shift</label>
          <select
            v-model="shiftForm.shift_id"
            class="w-full px-3 py-2 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 bg-white"
          >
            <option v-for="s in store.shifts" :key="s.id" :value="s.id">
              {{ s.name }} ({{ s.shift_start }} - {{ s.shift_end }})
            </option>
          </select>
        </div>

        <div>
          <label class="block font-semibold text-slate-700 mb-1">Effective From</label>
          <input
            v-model="shiftForm.effective_from"
            type="date"
            class="w-full px-3 py-2 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
          />
        </div>

        <div>
          <label class="block font-semibold text-slate-700 mb-1">Effective To (Optional)</label>
          <input
            v-model="shiftForm.effective_to"
            type="date"
            placeholder="Indefinite"
            class="w-full px-3 py-2 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
          />
        </div>
      </div>

      <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
        <button
          type="button"
          @click="showShiftModal = false"
          class="px-3 py-1.5 text-xs text-slate-600 hover:bg-slate-100 rounded-lg cursor-pointer"
        >
          Cancel
        </button>
        <button
          type="button"
          @click="submitAssignShift"
          class="px-4 py-1.5 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg cursor-pointer shadow-xs"
        >
          Apply Shift
        </button>
      </div>
    </div>
  </div>
  ```
- **Current Lines 493–525 (CSV Bulk Import Modal):**
  ```html
  <!-- 4. CSV Import Modal -->
  <div v-if="showImportModal" class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-xl border border-slate-200 max-w-md w-full p-6 space-y-4">
      <h3 class="text-sm font-bold text-slate-900">Bulk Import Employees from CSV</h3>
      <p class="text-xs text-slate-500">
        Upload a CSV file containing workforce headers: <code>employee_code</code>, <code>first_name</code>, <code>last_name</code>, <code>work_email</code>.
      </p>

      <input
        type="file"
        accept=".csv,.txt"
        @change="importFile = $event.target.files?.[0]"
        class="block w-full text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 cursor-pointer"
      />

      <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
        <button
          type="button"
          @click="showImportModal = false"
          class="px-3 py-1.5 text-xs text-slate-600 hover:bg-slate-100 rounded-lg cursor-pointer"
        >
          Cancel
        </button>
        <button
          type="button"
          :disabled="!importFile"
          @click="submitImport"
          class="px-4 py-1.5 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-40 rounded-lg cursor-pointer shadow-xs"
        >
          Upload &amp; Import
        </button>
      </div>
    </div>
  </div>
  ```
- **Deficiencies:**
  1. Neither modal has `role="dialog"`, `aria-modal="true"`, `tabindex="-1"`, or `aria-labelledby`.
  2. Neither modal supports dismissal via `@keydown.escape`.
  3. Neither modal has a top header close button with `type="button"` and `aria-label="Close dialog"`.
  4. In Assign Shift Modal: `<label>` tags are plain text containers without `for="..."` attributes, and `<select>` and `<input>` tags lack `id="..."`.
  5. In CSV Import Modal: The file `<input>` has **no `<label>` tag at all** and lacks an `id="..."`.

---

## 2. Logic Chain

1. **Premise 1 (EMP-06: Elimination of native dialogs):**
   - Observations in Section 1.1 show `EmployeeDirectory.vue:606` calls native `confirm()`.
   - The user specification and `ORIGINAL_REQUEST.md:263` mandate: *"Zero native dialogs check: Grep to verify no `window.confirm(` remains in the modified components."*
   - `resources/js/utils/notify.js` provides `notify.confirm(title, text, confirmButtonText, cancelButtonText, isDestructive)`.
   - Therefore, importing `notify` from `../../utils/notify` and replacing `confirm(...)` with `await notify.confirm('Delete Employee', ..., 'Yes, Delete Employee', 'Cancel', true)` delivers accessible confirmation that conforms to the design system.

2. **Premise 2 (EMP-07: Skeletons & CLS elimination):**
   - Observations in Section 1.2 demonstrate that `EmployeeDirectory.vue:166–169` replaces the entire directory with a single 150px spinner card.
   - The application supports two distinct viewing modes: `store.viewMode === 'table'` and `store.viewMode === 'grid'`.
   - When in table mode, replacing the single loader with 5 animated table rows matching the 7 table columns preserves table structure and column widths.
   - When in grid mode, rendering 8 animated card skeletons matching the 3-column card geometry preserves grid dimensions.
   - Adding `animate-pulse motion-reduce:animate-none` satisfies WCAG 2.3.3 by disabling animation for users with `prefers-reduced-motion`.

3. **Premise 3 (EMP-08: WCAG 2.1 AA dialog compliance & form labeling):**
   - Observations in Section 1.3 show both modals lack standard dialog ARIA semantics and label associations.
   - Adding `role="dialog"`, `aria-modal="true"`, `tabindex="-1"`, `aria-labelledby="<id>"`, and `@keydown.escape="close"` ensures assistive technologies announce the modal context and keyboard users can exit with the Escape key.
   - Adding a top header close button with `type="button"` and `aria-label="Close dialog"` satisfies WCAG 2.1 Criteria 4.1.2 (Name, Role, Value).
   - Adding explicit `id` attributes to all form controls (`assign_shift_id`, `assign_shift_effective_from`, `assign_shift_effective_to`, `csv_file_import`) and matching `<label for="...">` satisfies WCAG 2.1 Criteria 1.3.1 (Info and Relationships) and 3.3.2 (Labels or Instructions).

---

## 3. Caveats

1. **Sub-components untouched:** `EmployeeProfileModal.vue` and `EmployeeFormModal.vue` are separate components that already implement `role="dialog"`, `aria-modal="true"`, and Escape handling. No changes are required in those files.
2. **Backdrop light-dismiss:** In addition to `@keydown.escape`, adding `@click.self="showShiftModal = false"` and `@click.self="showImportModal = false"` enables clicking outside the modal to dismiss without accidentally closing when clicking inside the modal content box.
3. **No breaking changes to stores or APIs:** The changes are purely presentation and accessibility refactors; all Pinia store actions (`store.deleteEmployee`, `store.assignShift`, `store.importCsv`) and parameters remain identical.

---

## 4. Conclusion & Proposed Code Changes

The investigation is complete. Below are the exact before and after code changes for `resources/js/components/employees/EmployeeDirectory.vue`.

### 4.1 EMP-06: Replace `window.confirm()` with `notify.confirm()`

#### Script Imports (Line 529):
**Before:**
```javascript
<script setup>
import { ref, reactive, onMounted } from 'vue';
import { useEmployeeStore } from '../../stores/employeeStore';
import EmployeeProfileModal from './EmployeeProfileModal.vue';
import EmployeeFormModal from './EmployeeFormModal.vue';
```

**After:**
```javascript
<script setup>
import { ref, reactive, onMounted } from 'vue';
import { useEmployeeStore } from '../../stores/employeeStore';
import EmployeeProfileModal from './EmployeeProfileModal.vue';
import EmployeeFormModal from './EmployeeFormModal.vue';
import notify from '../../utils/notify';
```

#### `confirmDelete` Function (Lines 605–610):
**Before:**
```javascript
async function confirmDelete(emp) {
  const confirmed = confirm(`Are you sure you want to delete ${emp.first_name} ${emp.last_name}? This will revoke camera biometric access.`);
  if (confirmed) {
    await store.deleteEmployee(emp.id);
  }
}
```

**After:**
```javascript
async function confirmDelete(emp) {
  const name = `${emp.first_name} ${emp.last_name || ''}`.trim();
  const confirmed = await notify.confirm(
    `Delete ${name}?`,
    `Are you sure you want to delete ${name}? This will revoke camera biometric access.`,
    'Yes, Delete Employee',
    'Cancel',
    true
  );
  if (confirmed) {
    await store.deleteEmployee(emp.id);
  }
}
```

---

### 4.2 EMP-07: Mode-Specific Skeleton Loaders

#### Lines 165–170:
**Before:**
```html
    <!-- Loading Skeleton -->
    <div v-if="store.loading" class="bg-white rounded-2xl p-12 text-center border border-slate-200 shadow-xs">
      <div class="inline-block animate-spin text-2xl text-indigo-600 mb-2">⏳</div>
      <div class="text-xs font-semibold text-slate-600">Loading workforce directory...</div>
    </div>
```

**After:**
```html
    <!-- Loading Skeletons (EMP-07) -->
    <div v-if="store.loading">
      <!-- Mode 1: Table Skeleton (5 animated rows matching column geometry) -->
      <div v-if="store.viewMode === 'table'" class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-left text-xs">
            <thead class="bg-slate-50/80 border-b border-slate-200/80 text-[11px] font-semibold text-slate-500 uppercase tracking-wider">
              <tr>
                <th scope="col" class="px-5 py-3.5">Employee</th>
                <th scope="col" class="px-4 py-3.5">Code</th>
                <th scope="col" class="px-4 py-3.5">Department &amp; Role</th>
                <th scope="col" class="px-4 py-3.5">Shift Schedule</th>
                <th scope="col" class="px-4 py-3.5">Status</th>
                <th scope="col" class="px-4 py-3.5">Camera Face Biometrics</th>
                <th scope="col" class="px-5 py-3.5 text-right">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="i in 5" :key="`skel-emp-${i}`" class="animate-pulse motion-reduce:animate-none">
                <td class="px-5 py-3.5">
                  <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-xl bg-slate-200 shrink-0"></div>
                    <div class="space-y-1.5">
                      <div class="h-3.5 bg-slate-200 rounded w-28"></div>
                      <div class="h-2.5 bg-slate-100 rounded w-20"></div>
                    </div>
                  </div>
                </td>
                <td class="px-4 py-3.5">
                  <div class="h-3.5 bg-slate-200 rounded w-16"></div>
                </td>
                <td class="px-4 py-3.5">
                  <div class="space-y-1.5">
                    <div class="h-3.5 bg-slate-200 rounded w-24"></div>
                    <div class="h-2.5 bg-slate-100 rounded w-16"></div>
                  </div>
                </td>
                <td class="px-4 py-3.5">
                  <div class="h-5 bg-slate-200 rounded-lg w-24"></div>
                </td>
                <td class="px-4 py-3.5">
                  <div class="h-5 bg-slate-200 rounded-full w-16"></div>
                </td>
                <td class="px-4 py-3.5">
                  <div class="h-5 bg-slate-200 rounded-full w-24"></div>
                </td>
                <td class="px-5 py-3.5 text-right">
                  <div class="inline-flex items-center gap-1 justify-end">
                    <div class="w-7 h-7 bg-slate-200 rounded-lg"></div>
                    <div class="w-7 h-7 bg-slate-200 rounded-lg"></div>
                    <div class="w-7 h-7 bg-slate-200 rounded-lg"></div>
                    <div class="w-7 h-7 bg-slate-200 rounded-lg"></div>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Mode 2: Grid Skeleton (8 animated cards matching card geometry) -->
      <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <div
          v-for="i in 8"
          :key="`skel-card-${i}`"
          class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs flex flex-col justify-between animate-pulse motion-reduce:animate-none"
        >
          <div class="flex items-start gap-4">
            <div class="w-14 h-14 rounded-2xl bg-slate-200 shrink-0"></div>
            <div class="flex-1 min-w-0 space-y-2">
              <div class="flex items-center justify-between gap-2">
                <div class="h-4 bg-slate-200 rounded w-28"></div>
                <div class="h-4 bg-slate-200 rounded-full w-14 shrink-0"></div>
              </div>
              <div class="h-3 bg-slate-200 rounded w-16"></div>
              <div class="h-3 bg-slate-100 rounded w-32"></div>
            </div>
          </div>
          <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between">
            <div class="h-3 bg-slate-200 rounded w-20"></div>
            <div class="flex items-center gap-1">
              <div class="h-6 w-12 bg-slate-200 rounded-lg"></div>
              <div class="h-6 w-12 bg-slate-200 rounded-lg"></div>
            </div>
          </div>
        </div>
      </div>
    </div>
```

---

### 4.3 EMP-08: Assign Shift Modal & CSV Bulk Import Modal

#### Assign Shift Modal (Lines 434–490):
**Before:**
```html
    <!-- 3. Assign Shift Modal -->
    <div v-if="showShiftModal && selectedEmployee" class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
      <div class="bg-white rounded-2xl shadow-xl border border-slate-200 max-w-md w-full p-6 space-y-4">
        <h3 class="text-sm font-bold text-slate-900">
          Assign Shift Schedule: {{ selectedEmployee.first_name }} {{ selectedEmployee.last_name }}
        </h3>

        <div class="space-y-3 text-xs">
          <div>
            <label class="block font-semibold text-slate-700 mb-1">Target Shift</label>
            <select
              v-model="shiftForm.shift_id"
              class="w-full px-3 py-2 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 bg-white"
            >
              <option v-for="s in store.shifts" :key="s.id" :value="s.id">
                {{ s.name }} ({{ s.shift_start }} - {{ s.shift_end }})
              </option>
            </select>
          </div>

          <div>
            <label class="block font-semibold text-slate-700 mb-1">Effective From</label>
            <input
              v-model="shiftForm.effective_from"
              type="date"
              class="w-full px-3 py-2 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
            />
          </div>

          <div>
            <label class="block font-semibold text-slate-700 mb-1">Effective To (Optional)</label>
            <input
              v-model="shiftForm.effective_to"
              type="date"
              placeholder="Indefinite"
              class="w-full px-3 py-2 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
            />
          </div>
        </div>

        <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
          <button
            type="button"
            @click="showShiftModal = false"
            class="px-3 py-1.5 text-xs text-slate-600 hover:bg-slate-100 rounded-lg cursor-pointer"
          >
            Cancel
          </button>
          <button
            type="button"
            @click="submitAssignShift"
            class="px-4 py-1.5 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg cursor-pointer shadow-xs"
          >
            Apply Shift
          </button>
        </div>
      </div>
    </div>
```

**After:**
```html
    <!-- 3. Assign Shift Modal (EMP-08) -->
    <div
      v-if="showShiftModal && selectedEmployee"
      role="dialog"
      aria-modal="true"
      tabindex="-1"
      aria-labelledby="assign-shift-modal-title"
      @click.self="showShiftModal = false"
      @keydown.escape="showShiftModal = false"
      class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4"
    >
      <div class="bg-white rounded-2xl shadow-xl border border-slate-200 max-w-md w-full p-6 space-y-4">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
          <h3 id="assign-shift-modal-title" class="text-sm font-bold text-slate-900">
            Assign Shift Schedule: {{ selectedEmployee.first_name }} {{ selectedEmployee.last_name }}
          </h3>
          <button
            type="button"
            @click="showShiftModal = false"
            aria-label="Close dialog"
            class="text-slate-400 hover:text-slate-600 p-1 rounded-lg hover:bg-slate-100 transition-colors cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
          >
            ✕
          </button>
        </div>

        <div class="space-y-3 text-xs">
          <div>
            <label for="assign_shift_id" class="block font-semibold text-slate-700 mb-1">Target Shift</label>
            <select
              id="assign_shift_id"
              v-model="shiftForm.shift_id"
              class="w-full px-3 py-2 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 bg-white"
            >
              <option v-for="s in store.shifts" :key="s.id" :value="s.id">
                {{ s.name }} ({{ s.shift_start }} - {{ s.shift_end }})
              </option>
            </select>
          </div>

          <div>
            <label for="assign_shift_effective_from" class="block font-semibold text-slate-700 mb-1">Effective From</label>
            <input
              id="assign_shift_effective_from"
              v-model="shiftForm.effective_from"
              type="date"
              class="w-full px-3 py-2 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
            />
          </div>

          <div>
            <label for="assign_shift_effective_to" class="block font-semibold text-slate-700 mb-1">Effective To (Optional)</label>
            <input
              id="assign_shift_effective_to"
              v-model="shiftForm.effective_to"
              type="date"
              placeholder="Indefinite"
              class="w-full px-3 py-2 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
            />
          </div>
        </div>

        <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
          <button
            type="button"
            @click="showShiftModal = false"
            aria-label="Close dialog"
            class="px-3 py-1.5 text-xs text-slate-600 hover:bg-slate-100 rounded-lg cursor-pointer"
          >
            Cancel
          </button>
          <button
            type="button"
            @click="submitAssignShift"
            class="px-4 py-1.5 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg cursor-pointer shadow-xs"
          >
            Apply Shift
          </button>
        </div>
      </div>
    </div>
```

#### CSV Bulk Import Modal (Lines 493–525):
**Before:**
```html
    <!-- 4. CSV Import Modal -->
    <div v-if="showImportModal" class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
      <div class="bg-white rounded-2xl shadow-xl border border-slate-200 max-w-md w-full p-6 space-y-4">
        <h3 class="text-sm font-bold text-slate-900">Bulk Import Employees from CSV</h3>
        <p class="text-xs text-slate-500">
          Upload a CSV file containing workforce headers: <code>employee_code</code>, <code>first_name</code>, <code>last_name</code>, <code>work_email</code>.
        </p>

        <input
          type="file"
          accept=".csv,.txt"
          @change="importFile = $event.target.files?.[0]"
          class="block w-full text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 cursor-pointer"
        />

        <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
          <button
            type="button"
            @click="showImportModal = false"
            class="px-3 py-1.5 text-xs text-slate-600 hover:bg-slate-100 rounded-lg cursor-pointer"
          >
            Cancel
          </button>
          <button
            type="button"
            :disabled="!importFile"
            @click="submitImport"
            class="px-4 py-1.5 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-40 rounded-lg cursor-pointer shadow-xs"
          >
            Upload &amp; Import
          </button>
        </div>
      </div>
    </div>
```

**After:**
```html
    <!-- 4. CSV Import Modal (EMP-08) -->
    <div
      v-if="showImportModal"
      role="dialog"
      aria-modal="true"
      tabindex="-1"
      aria-labelledby="csv-import-modal-title"
      @click.self="showImportModal = false"
      @keydown.escape="showImportModal = false"
      class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4"
    >
      <div class="bg-white rounded-2xl shadow-xl border border-slate-200 max-w-md w-full p-6 space-y-4">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
          <h3 id="csv-import-modal-title" class="text-sm font-bold text-slate-900">
            Bulk Import Employees from CSV
          </h3>
          <button
            type="button"
            @click="showImportModal = false"
            aria-label="Close dialog"
            class="text-slate-400 hover:text-slate-600 p-1 rounded-lg hover:bg-slate-100 transition-colors cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
          >
            ✕
          </button>
        </div>
        <p class="text-xs text-slate-500">
          Upload a CSV file containing workforce headers: <code>employee_code</code>, <code>first_name</code>, <code>last_name</code>, <code>work_email</code>.
        </p>

        <div>
          <label for="csv_file_import" class="block font-semibold text-slate-700 mb-1 text-xs">
            CSV File
          </label>
          <input
            id="csv_file_import"
            type="file"
            accept=".csv,.txt"
            @change="importFile = $event.target.files?.[0]"
            class="block w-full text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 cursor-pointer"
          />
        </div>

        <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
          <button
            type="button"
            @click="showImportModal = false"
            aria-label="Close dialog"
            class="px-3 py-1.5 text-xs text-slate-600 hover:bg-slate-100 rounded-lg cursor-pointer"
          >
            Cancel
          </button>
          <button
            type="button"
            :disabled="!importFile"
            @click="submitImport"
            class="px-4 py-1.5 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-40 rounded-lg cursor-pointer shadow-xs"
          >
            Upload &amp; Import
          </button>
        </div>
      </div>
    </div>
```

---

## 5. Verification Method

Once the changes are applied by the worker/implementer, verify independently using the following commands and checks:

1. **Frontend Compilation Check:**
   ```bash
   npm run build
   ```
   *Expected output:* Vite builds client assets successfully with zero syntax, template, or bundling errors (`exited with code 0`).

2. **Zero Native Dialogs Check:**
   ```bash
   git grep "window.confirm" resources/js/components/employees/EmployeeDirectory.vue
   git grep "confirm(" resources/js/components/employees/EmployeeDirectory.vue
   ```
   *Expected output:* Only `notify.confirm` remains; zero calls to native `confirm(` or `window.confirm(`.

3. **Dialog & ARIA Semantics Check:**
   ```bash
   grep -E 'role="dialog"|aria-modal="true"|@keydown\.escape|aria-labelledby|assign_shift_id|csv_file_import' resources/js/components/employees/EmployeeDirectory.vue
   ```
   *Expected output:* Matches on both Assign Shift Modal and CSV Import Modal, along with corresponding labels.

4. **Skeleton & Motion Preference Check:**
   ```bash
   grep -E 'motion-reduce:animate-none' resources/js/components/employees/EmployeeDirectory.vue
   ```
   *Expected output:* Matches on both table row skeletons (`skel-emp-`) and grid card skeletons (`skel-card-`).
