# WCAG 2.1 AA Accessibility & State Analysis Report: EmployeeDirectory.vue (Tasks EMP-06, EMP-07, EMP-08)

**Target Component**: `resources/js/components/employees/EmployeeDirectory.vue`  
**Related Files**:  
- `resources/js/stores/employeeStore.js`  
- `resources/js/utils/notify.js`  
- `resources/js/components/employees/EmployeeFormModal.vue`  
- `resources/js/components/employees/EmployeeProfileModal.vue`  
- `resources/js/components/attendance/DailyAttendanceRoster.vue`  
- `resources/js/components/attendance/EmployeeAttendanceCalendar.vue`  

---

## 1. Observation

### Observation 1: Deletion Confirmation (EMP-06)
- **File**: `resources/js/components/employees/EmployeeDirectory.vue`
- **Lines 605–610**:
  ```javascript
  async function confirmDelete(emp) {
    const confirmed = confirm(`Are you sure you want to delete ${emp.first_name} ${emp.last_name}? This will revoke camera biometric access.`);
    if (confirmed) {
      await store.deleteEmployee(emp.id);
    }
  }
  ```
- **Line 530–534 (Imports)**:
  ```javascript
  import { ref, reactive, onMounted } from 'vue';
  import { useEmployeeStore } from '../../stores/employeeStore';
  import EmployeeProfileModal from './EmployeeProfileModal.vue';
  import EmployeeFormModal from './EmployeeFormModal.vue';
  ```
  `notify` from `../../utils/notify` is **not imported**.
- **`resources/js/stores/employeeStore.js:196–207`**:
  ```javascript
  async deleteEmployee(id) {
      this.deleting = true;
      try {
          await apiClient.delete(`/employees/${id}`);
          notify.toast('Employee record deleted/archived.', 'info');
          await this.fetchEmployees(this.pagination.current_page);
      } catch (err) {
          notify.error('Delete Failed', err.response?.data?.message || 'Unable to delete employee.');
          throw err;
      } finally {
          this.deleting = false;
      }
  },
  ```
  `store.deleteEmployee` re-throws `err` (`throw err;`). An unhandled rejection occurs in `EmployeeDirectory.vue` if `confirmDelete` does not wrap the call in a `try...catch` block.
- **`resources/js/utils/notify.js:91–105`**:
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
          confirmButtonColor: isDestructive ? '#e11d48' : '#4f46e5', // rose-600 vs indigo-600
      });

      return result.isConfirmed;
  },
  ```
  `notify.confirm()` returns a `Promise<boolean>` (`result.isConfirmed`). If canceled, it resolves to `false` without throwing an error.

---

### Observation 2: Loading State, CLS, & Motion Reduction (EMP-07)
- **File**: `resources/js/components/employees/EmployeeDirectory.vue`
- **Lines 165–170**:
  ```html
  <!-- Loading Skeleton -->
  <div v-if="store.loading" class="bg-white rounded-2xl p-12 text-center border border-slate-200 shadow-xs">
    <div class="inline-block animate-spin text-2xl text-indigo-600 mb-2">⏳</div>
    <div class="text-xs font-semibold text-slate-600">Loading workforce directory...</div>
  </div>
  ```
- **Lines 188–202 (Table Mode Container & Header)**:
  ```html
  <!-- Content: Mode 1 - High Density Table View -->
  <div v-else-if="store.viewMode === 'table'" class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
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
  ```
- **Lines 349–355 (Grid Cards Mode Container)**:
  ```html
  <!-- Content: Mode 2 - Modern Card Grid View -->
  <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
    <div
      v-for="emp in store.employees"
      :key="emp.id"
      class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs hover:shadow-md transition-all flex flex-col justify-between"
    >
  ```
- **Observation on Geometry**:
  - The loading container is a 140px-tall centered box with a spinning emoji `⏳`.
  - When loading finishes, it expands to an ~800px table with 7 columns and pagination, or a responsive 3-column card grid. This causes significant Cumulative Layout Shift (CLS).
  - The spinner animation uses `animate-spin` without `motion-reduce:animate-none`.

---

### Observation 3: Assign Shift Modal Semantics, Focus, & Labels (EMP-08)
- **File**: `resources/js/components/employees/EmployeeDirectory.vue`
- **Lines 434–490**:
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
- **Specific Deficiencies**:
  1. Outer container lacks `role="dialog"`, `aria-modal="true"`, `aria-labelledby`, and `tabindex="-1"`.
  2. Heading `<h3>` lacks an `id`.
  3. No close button in header (`aria-label="Close dialog"`).
  4. No `@keydown.escape` or global Escape listener to close the modal.
  5. Backdrop click does not close (`@click.self`).
  6. Focus is neither moved to the dialog on open nor returned to the opening button on close. Focus can escape behind the backdrop.
  7. Form fields have no label bindings:
     - `<label>Target Shift</label>` lacks `for`; `<select>` lacks `id` and `aria-required="true"`.
     - `<label>Effective From</label>` lacks `for`; `<input type="date">` lacks `id` and `aria-required="true"`.
     - `<label>Effective To (Optional)</label>` lacks `for`; `<input type="date">` lacks `id`.
  8. Lines 599–603 (`submitAssignShift`) have no loading/disabled state to prevent double submissions.

---

### Observation 4: CSV Bulk Import Modal Semantics, Focus, & Labels (EMP-08)
- **File**: `resources/js/components/employees/EmployeeDirectory.vue`
- **Lines 493–525**:
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
- **Specific Deficiencies**:
  1. Outer container lacks `role="dialog"`, `aria-modal="true"`, `aria-labelledby`, `aria-describedby`, and `tabindex="-1"`.
  2. Title `<h3>` lacks an `id`. Description `<p>` lacks an `id`.
  3. No header close button.
  4. No `@keydown.escape` or global Escape listener.
  5. Backdrop click does not close.
  6. Focus is not managed (no initial focus, no restoration on close, no focus trap).
  7. File input has **NO label** (`<label>` is completely missing), NO `id`, NO `aria-label`, and is not tied to the helper text via `aria-describedby`.
  8. Submit button lacks an asynchronous loading spinner during file upload.

---

## 2. Logic Chain

1. **Premise 1 (WCAG 2.1 AA Criteria 4.1.2 Name, Role, Value & 2.1.2 No Keyboard Trap)**:
   - Dialog elements must announce themselves as modal dialogs (`role="dialog"`, `aria-modal="true"`), provide accessible names via `aria-labelledby`, provide accessible descriptions via `aria-describedby`, trap keyboard focus while active, and dismiss cleanly via the `Escape` key (`@keydown.escape`).
   - Observations 3 and 4 establish that neither modal satisfies these criteria: both lack dialog roles, accessible names, Escape listeners, and focus traps.

2. **Premise 2 (WCAG 1.3.1 Info and Relationships & 3.3.2 Labels or Instructions)**:
   - Every interactive form control must have an associated programmatic label (`<label for="input-id">` matching `<input id="input-id">`), and required controls must indicate their state (`aria-required="true"`).
   - Observations 3 and 4 show that all three fields in the Assign Shift modal lack `for`/`id` bindings, and the CSV file input lacks a `<label>` entirely.

3. **Premise 3 (WCAG 2.2.2 Pause, Stop, Hide & 2.3.3 Animation from Interactions & Core Web Vitals CLS)**:
   - Users with vestibular motion sensitivities must not encounter continuous pulsing without an opt-out. Tailwind's `motion-reduce:animate-none` disables pulse and spin animations when `prefers-reduced-motion: reduce` is enabled.
   - Cumulative Layout Shift occurs when an asynchronous loading placeholder differs significantly in geometry from the loaded state.
   - Observation 2 demonstrates that replacing the full 7-column table or 3-column card grid with a 140px centered box triggers massive visual jumps. Rendering mode-specific skeletons matching exact layout geometry eliminates this shift.

4. **Premise 4 (WCAG 3.3.4 Error Prevention for Destructive Actions & UX Standards)**:
   - Destructive operations (such as permanently deleting an employee and revoking facial recognition templates) must provide accessible confirmation dialogs that can be reviewed, confirmed, or dismissed without blocking the browser thread.
   - Observation 1 demonstrates that `window.confirm()` halts JavaScript execution, cannot be read properly by screen readers in headless/modal contexts, and is inconsistent with the rest of the application's SweetAlert2 implementation (`notify.confirm()`).
   - Furthermore, because `store.deleteEmployee` re-throws errors, wrapping the call in `try...catch` ensures graceful failure without uncaught promise rejections.

---

## 3. Caveats

1. **Sub-components (`EmployeeProfileModal.vue`, `EmployeeFormModal.vue`)**:
   - Both sub-modals were checked. They already implement `role="dialog"`, `aria-modal="true"`, `aria-labelledby`, and `Escape` listeners. No modifications are needed in those child components.
2. **Global Event Listeners**:
   - In Vue 3, adding a `window.addEventListener('keydown', handleGlobalKeydown)` requires symmetric cleanup in `onUnmounted` to prevent memory leaks or ghost handlers when navigating between views.
3. **Active Element Tracking**:
   - When returning focus after closing a modal, `lastFocusedElement` should be checked to ensure it is still connected to the DOM before invoking `.focus()`.

---

## 4. Conclusion & Proposed Implementation Blueprint

To achieve full WCAG 2.1 AA compliance and eliminate CLS, `resources/js/components/employees/EmployeeDirectory.vue` requires three targeted refactors:

### Refactor 1: Asynchronous `notify.confirm()` for Employee Deletion (EMP-06)

1. Import `notify`:
   ```javascript
   import notify from '../../utils/notify';
   ```
2. Replace `confirmDelete`:
   ```javascript
   async function confirmDelete(emp) {
     try {
       const fullName = `${emp.first_name} ${emp.last_name || ''}`.trim();
       const confirmed = await notify.confirm(
         'Delete Employee Record',
         `Are you sure you want to delete ${fullName}? This will revoke camera biometric access.`,
         'Yes, Delete',
         'Cancel',
         true
       );
       if (confirmed) {
         await store.deleteEmployee(emp.id);
       }
     } catch (err) {
       console.error('Employee deletion error:', err);
     }
   }
   ```
3. Update delete button in template (line 309) to disable when `store.deleting`:
   ```html
   <button
     @click="confirmDelete(emp)"
     :disabled="store.deleting"
     :aria-label="`Delete record for ${emp.first_name} ${emp.last_name || ''}`"
     class="p-1.5 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition-colors cursor-pointer focus:outline-none focus:ring-2 focus:ring-rose-500 disabled:opacity-40 disabled:cursor-not-allowed"
     title="Archive / Soft Delete"
   >
     <span aria-hidden="true">🗑️</span>
   </button>
   ```

---

### Refactor 2: Mode-Specific Skeleton Loaders with `motion-reduce:animate-none` (EMP-07)

Replace lines 165–170 with:
```html
    <!-- Loading Skeletons (EMP-07: Table vs Grid geometry matching to eliminate CLS) -->
    <div v-if="store.loading" role="status" aria-label="Loading workforce directory">
      <span class="sr-only">Loading workforce directory...</span>

      <!-- Mode 1 Skeleton: 7-Column Table Geometry -->
      <div v-if="store.viewMode === 'table'" class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-left text-xs" aria-hidden="true">
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
              <tr v-for="i in 5" :key="`skel-table-${i}`" class="animate-pulse motion-reduce:animate-none">
                <td class="px-5 py-3.5">
                  <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-xl bg-slate-200 shrink-0"></div>
                    <div class="space-y-1.5">
                      <div class="h-3.5 w-28 bg-slate-200 rounded"></div>
                      <div class="h-2.5 w-20 bg-slate-100 rounded"></div>
                    </div>
                  </div>
                </td>
                <td class="px-4 py-3.5">
                  <div class="h-3.5 w-16 bg-slate-200 rounded font-mono"></div>
                </td>
                <td class="px-4 py-3.5 space-y-1.5">
                  <div class="h-3.5 w-24 bg-slate-200 rounded"></div>
                  <div class="h-2.5 w-16 bg-slate-100 rounded"></div>
                </td>
                <td class="px-4 py-3.5">
                  <div class="h-5 w-24 bg-slate-200 rounded-lg"></div>
                </td>
                <td class="px-4 py-3.5">
                  <div class="h-4.5 w-14 bg-slate-200 rounded-full"></div>
                </td>
                <td class="px-4 py-3.5">
                  <div class="h-4.5 w-24 bg-slate-200 rounded-full"></div>
                </td>
                <td class="px-5 py-3.5 text-right">
                  <div class="inline-flex items-center gap-1.5 justify-end">
                    <div class="w-6 h-6 rounded-lg bg-slate-200"></div>
                    <div class="w-6 h-6 rounded-lg bg-slate-200"></div>
                    <div class="w-6 h-6 rounded-lg bg-slate-200"></div>
                    <div class="w-6 h-6 rounded-lg bg-slate-200"></div>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="px-5 py-3 border-t border-slate-100 flex items-center justify-between text-xs text-slate-500 animate-pulse motion-reduce:animate-none" aria-hidden="true">
          <div class="h-3 w-44 bg-slate-200 rounded"></div>
          <div class="flex items-center gap-1.5">
            <div class="h-6 w-16 bg-slate-200 rounded-lg"></div>
            <div class="h-6 w-16 bg-slate-200 rounded-lg"></div>
          </div>
        </div>
      </div>

      <!-- Mode 2 Skeleton: 3-Column Card Grid Geometry -->
      <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4" aria-hidden="true">
        <div
          v-for="i in 6"
          :key="`skel-grid-${i}`"
          class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs flex flex-col justify-between animate-pulse motion-reduce:animate-none"
        >
          <div class="flex items-start gap-4">
            <div class="w-14 h-14 rounded-2xl bg-slate-200 shrink-0"></div>
            <div class="flex-1 min-w-0 space-y-2">
              <div class="flex items-center justify-between gap-2">
                <div class="h-4 w-28 bg-slate-200 rounded"></div>
                <div class="h-3.5 w-12 bg-slate-200 rounded-full"></div>
              </div>
              <div class="h-3 w-16 bg-slate-100 rounded"></div>
              <div class="h-3 w-36 bg-slate-100 rounded"></div>
            </div>
          </div>
          <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between">
            <div class="h-4 w-24 bg-slate-200 rounded-lg"></div>
            <div class="flex items-center gap-1">
              <div class="h-6 w-14 bg-slate-200 rounded-lg"></div>
              <div class="h-6 w-12 bg-slate-200 rounded-lg"></div>
            </div>
          </div>
        </div>
      </div>
    </div>
```

---

### Refactor 3: Dialog Semantics, Focus Management & Label Associations for Modals (EMP-08)

#### Assign Shift Modal
```html
    <!-- 3. Assign Shift Modal (EMP-08) -->
    <div
      v-if="showShiftModal && selectedEmployee"
      ref="shiftModalRef"
      role="dialog"
      aria-modal="true"
      aria-labelledby="assign-shift-modal-title"
      tabindex="-1"
      class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4"
      @click.self="closeShiftModal"
      @keydown.escape="closeShiftModal"
      @keydown="handleShiftModalKeydown"
    >
      <div class="bg-white rounded-2xl shadow-xl border border-slate-200 max-w-md w-full p-6 space-y-4 animate-in fade-in zoom-in-95 duration-150">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
          <h3 id="assign-shift-modal-title" class="text-sm font-bold text-slate-900">
            Assign Shift Schedule: {{ selectedEmployee.first_name }} {{ selectedEmployee.last_name }}
          </h3>
          <button
            type="button"
            @click="closeShiftModal"
            aria-label="Close assign shift dialog"
            class="text-slate-400 hover:text-slate-600 p-1.5 rounded-lg hover:bg-slate-100 transition-colors cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
          >
            ✕
          </button>
        </div>

        <div class="space-y-3 text-xs">
          <div>
            <label for="assign_shift_id" class="block font-semibold text-slate-700 mb-1">
              Target Shift <span class="text-rose-500" aria-hidden="true">*</span>
            </label>
            <select
              id="assign_shift_id"
              ref="shiftSelectRef"
              v-model="shiftForm.shift_id"
              aria-required="true"
              class="w-full px-3 py-2 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 bg-white"
            >
              <option v-for="s in store.shifts" :key="s.id" :value="s.id">
                {{ s.name }} ({{ s.shift_start }} - {{ s.shift_end }})
              </option>
            </select>
          </div>

          <div>
            <label for="assign_effective_from" class="block font-semibold text-slate-700 mb-1">
              Effective From <span class="text-rose-500" aria-hidden="true">*</span>
            </label>
            <input
              id="assign_effective_from"
              v-model="shiftForm.effective_from"
              type="date"
              aria-required="true"
              class="w-full px-3 py-2 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
            />
          </div>

          <div>
            <label for="assign_effective_to" class="block font-semibold text-slate-700 mb-1">
              Effective To (Optional)
            </label>
            <input
              id="assign_effective_to"
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
            @click="closeShiftModal"
            class="px-3 py-1.5 text-xs text-slate-600 hover:bg-slate-100 rounded-lg cursor-pointer"
          >
            Cancel
          </button>
          <button
            type="button"
            :disabled="isSubmittingShift || !shiftForm.shift_id || !shiftForm.effective_from"
            @click="submitAssignShift"
            class="px-4 py-1.5 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-40 rounded-lg cursor-pointer shadow-xs inline-flex items-center gap-1.5"
          >
            <svg
              v-if="isSubmittingShift"
              class="animate-spin h-3 w-3 text-white motion-reduce:animate-none"
              fill="none"
              viewBox="0 0 24 24"
              aria-hidden="true"
            >
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <span>{{ isSubmittingShift ? 'Applying...' : 'Apply Shift' }}</span>
          </button>
        </div>
      </div>
    </div>
```

#### CSV Bulk Import Modal
```html
    <!-- 4. CSV Import Modal (EMP-08) -->
    <div
      v-if="showImportModal"
      ref="importModalRef"
      role="dialog"
      aria-modal="true"
      aria-labelledby="csv-import-modal-title"
      aria-describedby="csv-import-modal-desc"
      tabindex="-1"
      class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4"
      @click.self="closeImportModal"
      @keydown.escape="closeImportModal"
      @keydown="handleImportModalKeydown"
    >
      <div class="bg-white rounded-2xl shadow-xl border border-slate-200 max-w-md w-full p-6 space-y-4 animate-in fade-in zoom-in-95 duration-150">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
          <h3 id="csv-import-modal-title" class="text-sm font-bold text-slate-900">
            Bulk Import Employees from CSV
          </h3>
          <button
            type="button"
            @click="closeImportModal"
            aria-label="Close bulk import dialog"
            class="text-slate-400 hover:text-slate-600 p-1.5 rounded-lg hover:bg-slate-100 transition-colors cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
          >
            ✕
          </button>
        </div>

        <p id="csv-import-modal-desc" class="text-xs text-slate-500">
          Upload a CSV file containing workforce headers: <code>employee_code</code>, <code>first_name</code>, <code>last_name</code>, <code>work_email</code>.
        </p>

        <div>
          <label for="csv_import_file" class="block text-xs font-semibold text-slate-700 mb-1.5">
            Select CSV File <span class="text-rose-500" aria-hidden="true">*</span>
          </label>
          <input
            id="csv_import_file"
            ref="fileInputRef"
            type="file"
            accept=".csv,.txt"
            aria-describedby="csv-import-modal-desc"
            aria-required="true"
            @change="importFile = $event.target.files?.[0]"
            class="block w-full text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 cursor-pointer"
          />
        </div>

        <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
          <button
            type="button"
            @click="closeImportModal"
            class="px-3 py-1.5 text-xs text-slate-600 hover:bg-slate-100 rounded-lg cursor-pointer"
          >
            Cancel
          </button>
          <button
            type="button"
            :disabled="!importFile || isImporting"
            @click="submitImport"
            class="px-4 py-1.5 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-40 rounded-lg cursor-pointer shadow-xs inline-flex items-center gap-1.5"
          >
            <svg
              v-if="isImporting"
              class="animate-spin h-3 w-3 text-white motion-reduce:animate-none"
              fill="none"
              viewBox="0 0 24 24"
              aria-hidden="true"
            >
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <span>{{ isImporting ? 'Importing...' : 'Upload & Import' }}</span>
          </button>
        </div>
      </div>
    </div>
```

#### Script State & Helper Setup for Modals & Focus Trap
```javascript
import { ref, reactive, onMounted, onUnmounted, nextTick } from 'vue';
import notify from '../../utils/notify';

// Focus management references
const shiftModalRef = ref(null);
const shiftSelectRef = ref(null);
const importModalRef = ref(null);
const fileInputRef = ref(null);
let lastFocusedElement = null;

const isSubmittingShift = ref(false);
const isImporting = ref(false);

function trapModalFocus(e, modalElement) {
  if (e.key !== 'Tab' || !modalElement) return;
  const focusables = modalElement.querySelectorAll(
    'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
  );
  if (!focusables.length) return;
  const first = focusables[0];
  const last = focusables[focusables.length - 1];

  if (e.shiftKey && document.activeElement === first) {
    e.preventDefault();
    last.focus();
  } else if (!e.shiftKey && document.activeElement === last) {
    e.preventDefault();
    first.focus();
  }
}

function handleShiftModalKeydown(e) {
  trapModalFocus(e, shiftModalRef.value);
}

function handleImportModalKeydown(e) {
  trapModalFocus(e, importModalRef.value);
}

function openAssignShiftModal(emp) {
  lastFocusedElement = document.activeElement;
  selectedEmployee.value = emp;
  shiftForm.shift_id = emp.shift_id || (store.shifts[0]?.id ?? '');
  shiftForm.effective_from = new Date().toISOString().slice(0, 10);
  shiftForm.effective_to = '';
  showShiftModal.value = true;
  nextTick(() => {
    shiftSelectRef.value?.focus();
  });
}

function closeShiftModal() {
  showShiftModal.value = false;
  nextTick(() => {
    if (lastFocusedElement && typeof lastFocusedElement.focus === 'function') {
      lastFocusedElement.focus();
    }
  });
}

function openImportModal() {
  lastFocusedElement = document.activeElement;
  showImportModal.value = true;
  nextTick(() => {
    fileInputRef.value?.focus();
  });
}

function closeImportModal() {
  showImportModal.value = false;
  importFile.value = null;
  nextTick(() => {
    if (lastFocusedElement && typeof lastFocusedElement.focus === 'function') {
      lastFocusedElement.focus();
    }
  });
}

function handleGlobalKeydown(e) {
  if (e.key === 'Escape') {
    if (showShiftModal.value) {
      closeShiftModal();
    } else if (showImportModal.value) {
      closeImportModal();
    }
  }
}

onMounted(() => {
  window.addEventListener('keydown', handleGlobalKeydown);
});

onUnmounted(() => {
  window.removeEventListener('keydown', handleGlobalKeydown);
});

async function submitAssignShift() {
  if (!shiftForm.shift_id || !shiftForm.effective_from || isSubmittingShift.value) return;
  isSubmittingShift.value = true;
  try {
    await store.assignShift(selectedEmployee.value.id, shiftForm);
    closeShiftModal();
  } catch (err) {
    console.error('Assign shift error:', err);
  } finally {
    isSubmittingShift.value = false;
  }
}

async function submitImport() {
  if (!importFile.value || isImporting.value) return;
  isImporting.value = true;
  try {
    await store.importCsv(importFile.value);
    closeImportModal();
  } catch (err) {
    console.error('CSV import error:', err);
  } finally {
    isImporting.value = false;
  }
}
```

---

## 5. Verification Method

To independently verify after implementation:

1. **Vite Bundle Compilation Check**:
   ```bash
   npm run build
   ```
   *Expected outcome*: Exits with code 0. Zero template compilation or syntax errors.

2. **Native Dialog Audit**:
   ```bash
   grep -n "window.confirm" resources/js/components/employees/EmployeeDirectory.vue
   grep -n "confirm(" resources/js/components/employees/EmployeeDirectory.vue
   ```
   *Expected outcome*: Only `notify.confirm` remains; zero native `confirm(` calls.

3. **Dialog Semantics Audit**:
   ```bash
   grep -n 'role="dialog"' resources/js/components/employees/EmployeeDirectory.vue
   grep -n 'aria-modal="true"' resources/js/components/employees/EmployeeDirectory.vue
   grep -n '@keydown.escape' resources/js/components/employees/EmployeeDirectory.vue
   ```
   *Expected outcome*: Both Assign Shift Modal and CSV Bulk Import Modal include these attributes.

4. **Label Association Audit**:
   ```bash
   grep -n 'for=' resources/js/components/employees/EmployeeDirectory.vue
   ```
   *Expected outcome*: Every `<label>` in both modals has a `for` attribute matching an existing element `id` (`assign_shift_id`, `assign_effective_from`, `assign_effective_to`, `csv_import_file`).

5. **Reduced Motion Audit**:
   ```bash
   grep -n 'motion-reduce:animate-none' resources/js/components/employees/EmployeeDirectory.vue
   ```
   *Expected outcome*: All skeleton rows, cards, and SVG spinners include `motion-reduce:animate-none`.

6. **Regression Test Suite**:
   ```bash
   php artisan test --filter=Employee
   ```
   *Expected outcome*: All 71 tests pass cleanly with 0 failures and 0 errors.
