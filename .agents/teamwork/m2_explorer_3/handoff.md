# Technical Implementation Blueprint: Frontend Employee & Schedule UI Suite (Milestone 2)

**Author**: `m2_explorer_3` (teamwork_preview_explorer)  
**Target Milestone**: Milestone 2 (Phase 2 Employee Management & Phase 3 Shift & Schedule Management)  
**Deliverable**: Comprehensive Technical Implementation Blueprint for Pinia Stores, Vue 3 Components, and Navigation Integration.

---

## 1. Observation

Direct observations and evidence gathered from the codebase:

1. **Vite & Tooling Environment**:
   - `package.json` specifies `"type": "module"`, `vue: "^3.5.41"`, `pinia: "^4.0.3"`, `tailwindcss: "^4.3.3"`, `@tailwindcss/vite: "^4.3.3"`, `sweetalert2: "^11.26.25"`, `lucide-vue-next: "^1.0.0"`.
   - Baseline build verification via `npm run build` completed in `603ms` with exit code `0`, outputting CSS and JS bundles into `public/build/assets/`.
2. **Existing Authentication & RBAC Architecture**:
   - `resources/js/api/client.js` configures standard `apiClient` with Bearer token injection, CSRF token handling, and interceptors for 401 (triggers `auth:unauthorized`), 403 (triggers permission warning), 422 (extracts validation error), and 500+ alerts via `notify.js`.
   - `resources/js/stores/authStore.js` exposes role-checking getters: `isAdmin`, `isHrManager`, `isManager`, `isEmployee`, and `hasAnyRole(rolesToCheck)` with automatic `super-admin` wildcard allowance.
3. **Existing Main Shell & Navigation**:
   - `resources/js/App.vue` manages top-level tabs via `baseTabs` and `visibleTabs`. Currently, tabs exist for `live`, `strangers`, `personnel`, `devices`, `logs`, `sync`, and `settings` (admin only).
   - `SettingsHub.vue` implements a segmented sub-navigation pill bar pattern (`DepartmentManager`, `SystemSettings`, `AuditLogViewer`), which serves as the established design pattern for multi-view hubs.
4. **Existing Biometric Personnel Bridge**:
   - `resources/js/views/PersonnelManager.vue` demonstrates photo handling: FileReader conversion of uploaded images to Base64 (`photo_base64`), permanent/temporary validity scheduling, and manual camera sync trigger (`/api/personnel/{id}/sync-now`).
   - `app/Models/Personnel.php` stores `customize_id`, `name`, `person_type` (0=whitelist, 1=blacklist), `photo_path`, and `photo_base64`.
5. **Organizational Hierarchy Availability**:
   - `app/Http/Controllers/OrganizationController.php` exposes:
     - `GET /api/departments` and `GET /api/departments/tree`
     - `GET /api/designations`
     - `GET /api/locations`
     - `GET /api/organizations`
6. **Milestone 2 Acceptance Tests**:
   - `tests/Feature/E2E/Tier1FeatureCoverageTest.php` (§ Section 2, lines 328–462) establishes the exact backend contracts for M2:
     - `POST /api/employees` accepts `personnel_id`, `employee_code`, `first_name`, `last_name`, `date_of_joining`, `employment_status`.
     - `GET /api/employees?status=active` supports directory filtering.
     - `DELETE /api/employees/{id}` executes soft deletes.
     - `POST /api/shifts` accepts `name`, `code`, `shift_start`, `shift_end`, `grace_period_minutes`, `early_out_threshold_minutes`, `break_duration_minutes`, `min_hours_full_day`, `half_day_threshold_hours`, `is_overnight`.
     - `POST /api/holidays` accepts `name`, `date`, `type` (`public`, `company`, `optional`), `is_recurring`.

---

## 2. Logic Chain

1. **State Isolation & Cohesion**:
   - Following Pinia modularity principles, separate employee state (`employeeStore.js`) from schedule state (`scheduleStore.js`).
   - `employeeStore.js` will encapsulate employee CRUD, filters, pagination, profile fetching, department/designation metadata, CSV import/export, and shift assignment calls.
   - `scheduleStore.js` will encapsulate shift CRUD, shift assignments, and holiday calendar state (including month/year navigation and date mapping).
2. **Component Modularity & User Workflows**:
   - In `resources/js/components/employees/`:
     - `EmployeeDirectory.vue` acts as the primary workforce index. It provides instant toggling between a high-density Table view and a modern Card Grid view, live filter bars, and opens modals.
     - `EmployeeProfileModal.vue` is a comprehensive detail viewer structured into 4 distinct tabs (Personal Info, Employment Details, Shift Schedule, Camera Face Biometrics) so HR and managers can inspect employee telemetry and biometric sync status in one place.
     - `EmployeeFormModal.vue` unifies employee creation and editing. To maintain parity with the biometric bridge, it provides dual-mode face capture: traditional file upload with preview and live webcam snapshot via WebRTC `navigator.mediaDevices.getUserMedia`.
   - In `resources/js/components/schedules/`:
     - `ShiftManager.vue` provides visual cards with color badges and a modal for configuring shift parameters (times, grace period, breaks, overnight toggle).
     - `ShiftAssignment.vue` supports both individual and bulk department shift scheduling with day-of-week checkboxes (Mon–Sun).
     - `HolidayCalendar.vue` displays a 7-column month grid marking public/company/optional holidays with color-coded chips, plus a modal for CRUD operations.
     - `ScheduleHub.vue` wraps `ShiftManager`, `ShiftAssignment`, and `HolidayCalendar` under a segmented pill bar, mirroring `SettingsHub.vue`.
3. **Access Control & App Navigation Integration**:
   - Both `👤 Employees` and `🕐 Schedules` tabs must be accessible to `admin`, `hr-manager`, and `manager` roles.
   - In `resources/js/App.vue`, `baseTabs` is updated with `roles: ['admin', 'hr-manager', 'manager']`. The `visibleTabs` computed property checks `authStore.hasAnyRole(tab.roles)`.
   - Async components (`defineAsyncComponent`) are used to ensure fast initial page load and clean chunk division.
4. **Vite Build Resilience**:
   - Components leverage standard Vue 3 Composition API (`<script setup>`), Pinia stores, existing `apiClient`, `notify.js` (SweetAlert2), and Tailwind CSS v4 utility classes.
   - No external uninstalled calendar libraries (like FullCalendar) are required; the calendar grid is built natively with zero external dependencies to prevent packaging overhead and bundle bloat.

---

## 3. Caveats

- **Backend Sync Timing**: Milestone 2 backend endpoints (`EmployeeController`, `ShiftController`, `HolidayController`) are being finalized by parallel agents (`m2_explorer_1`, `m2_explorer_2`). The stores and UI components are designed with fallback error handling and defensive response mapping (supporting both `{ data: [...] }` and direct array/paginator responses).
- **Webcam Security Constraints**: The live webcam capture feature requires `https://` or `localhost`. If accessed over insecure HTTP in non-localhost LAN environments, the browser disables `getUserMedia`; the form gracefully hides the webcam button and falls back to standard file upload.
- **CSV Import Schema**: CSV import expects headers matching standard fields (`employee_code`, `first_name`, `last_name`, `email`, `phone`, `department_code`, `designation_code`, `employment_type`, `employment_status`, `date_of_joining`). An inline downloadable template helper is provided.

---

## 4. Conclusion & Complete Technical Blueprint

### A. Pinia Store 1: `resources/js/stores/employeeStore.js`

```javascript
import { defineStore } from 'pinia';
import apiClient from '../api/client';
import notify from '../utils/notify';

export const useEmployeeStore = defineStore('employee', {
    state: () => ({
        employees: [],
        pagination: {
            current_page: 1,
            last_page: 1,
            per_page: 15,
            total: 0,
            from: 0,
            to: 0,
        },
        filters: {
            search: '',
            department_id: '',
            designation_id: '',
            location_id: '',
            employment_status: '',
            employment_type: '',
        },
        currentEmployee: null,
        loading: false,
        saving: false,
        deleting: false,
        error: null,
        departments: [],
        designations: [],
        locations: [],
        managers: [],
        shifts: [],
        viewMode: localStorage.getItem('employee_view_mode') || 'table', // 'table' | 'grid'
    }),

    getters: {
        hasActiveFilters: (state) => {
            return !!(
                state.filters.search ||
                state.filters.department_id ||
                state.filters.designation_id ||
                state.filters.location_id ||
                state.filters.employment_status ||
                state.filters.employment_type
            );
        },
        totalCount: (state) => state.pagination.total || state.employees.length,
        activeCount: (state) => state.employees.filter(e => e.employment_status === 'active').length,
    },

    actions: {
        setViewMode(mode) {
            this.viewMode = mode;
            localStorage.setItem('employee_view_mode', mode);
        },

        setFilter(key, value) {
            this.filters[key] = value;
            this.fetchEmployees(1);
        },

        resetFilters() {
            this.filters = {
                search: '',
                department_id: '',
                designation_id: '',
                location_id: '',
                employment_status: '',
                employment_type: '',
            };
            this.fetchEmployees(1);
        },

        async fetchMetadata() {
            try {
                const [deptRes, desigRes, locRes, shiftRes] = await Promise.allSettled([
                    apiClient.get('/departments'),
                    apiClient.get('/designations'),
                    apiClient.get('/locations'),
                    apiClient.get('/shifts'),
                ]);

                if (deptRes.status === 'fulfilled') {
                    this.departments = deptRes.value.data.data || deptRes.value.data || [];
                }
                if (desigRes.status === 'fulfilled') {
                    this.designations = desigRes.value.data.data || desigRes.value.data || [];
                }
                if (locRes.status === 'fulfilled') {
                    this.locations = locRes.value.data.data || locRes.value.data || [];
                }
                if (shiftRes.status === 'fulfilled') {
                    this.shifts = shiftRes.value.data.data || shiftRes.value.data || [];
                }
            } catch (err) {
                console.warn('Failed to load employee metadata:', err);
            }
        },

        async fetchEmployees(page = 1) {
            this.loading = true;
            this.error = null;

            const params = {
                page,
                per_page: this.pagination.per_page,
            };

            if (this.filters.search) params.search = this.filters.search;
            if (this.filters.department_id) params.department_id = this.filters.department_id;
            if (this.filters.designation_id) params.designation_id = this.filters.designation_id;
            if (this.filters.location_id) params.location_id = this.filters.location_id;
            if (this.filters.employment_status) params.employment_status = this.filters.employment_status;
            if (this.filters.employment_type) params.employment_type = this.filters.employment_type;

            try {
                const res = await apiClient.get('/employees', { params });
                const data = res.data;

                if (Array.isArray(data)) {
                    this.employees = data;
                    this.pagination.total = data.length;
                    this.pagination.current_page = 1;
                    this.pagination.last_page = 1;
                } else if (data.data && Array.isArray(data.data)) {
                    this.employees = data.data;
                    this.pagination.current_page = data.current_page || 1;
                    this.pagination.last_page = data.last_page || 1;
                    this.pagination.per_page = data.per_page || 15;
                    this.pagination.total = data.total || data.data.length;
                    this.pagination.from = data.from || 1;
                    this.pagination.to = data.to || data.data.length;
                } else {
                    this.employees = [];
                }
            } catch (err) {
                this.error = err.response?.data?.message || 'Failed to fetch employees';
                notify.error('Employee Fetch Failed', this.error);
            } finally {
                this.loading = false;
            }
        },

        async fetchEmployee(id) {
            this.loading = true;
            try {
                const res = await apiClient.get(`/employees/${id}`);
                this.currentEmployee = res.data.data || res.data;
                return this.currentEmployee;
            } catch (err) {
                notify.error('Failed to load employee profile');
                throw err;
            } finally {
                this.loading = false;
            }
        },

        async createEmployee(payload) {
            this.saving = true;
            try {
                const res = await apiClient.post('/employees', payload);
                const created = res.data.data || res.data;
                notify.success('Employee Created', `${created.first_name} ${created.last_name} enrolled successfully.`);
                await this.fetchEmployees(1);
                return created;
            } catch (err) {
                const msg = err.response?.data?.message || 'Failed to create employee.';
                notify.error('Creation Error', msg);
                throw err;
            } finally {
                this.saving = false;
            }
        },

        async updateEmployee(id, payload) {
            this.saving = true;
            try {
                const res = await apiClient.put(`/employees/${id}`, payload);
                const updated = res.data.data || res.data;
                notify.success('Employee Updated', 'Employee details saved successfully.');
                await this.fetchEmployees(this.pagination.current_page);
                if (this.currentEmployee?.id === id) {
                    this.currentEmployee = updated;
                }
                return updated;
            } catch (err) {
                const msg = err.response?.data?.message || 'Failed to update employee.';
                notify.error('Update Error', msg);
                throw err;
            } finally {
                this.saving = false;
            }
        },

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

        async assignShift(employeeId, shiftData) {
            try {
                await apiClient.post(`/employees/${employeeId}/assign-shift`, shiftData);
                notify.success('Shift Assigned', 'Employee shift schedule updated.');
                await this.fetchEmployees(this.pagination.current_page);
            } catch (err) {
                notify.error('Shift Assignment Error', err.response?.data?.message || 'Unable to assign shift.');
                throw err;
            }
        },

        async importCsv(file) {
            const formData = new FormData();
            formData.append('file', file);

            try {
                const res = await apiClient.post('/employees/import', formData, {
                    headers: { 'Content-Type': 'multipart/form-data' },
                });
                notify.success('Import Successful', `${res.data.imported || 'Employees'} records successfully imported.`);
                await this.fetchEmployees(1);
                return res.data;
            } catch (err) {
                notify.error('Import Failed', err.response?.data?.message || 'Failed to parse CSV file.');
                throw err;
            }
        },

        async exportCsv() {
            try {
                const res = await apiClient.get('/employees/export', {
                    responseType: 'blob',
                    params: this.filters,
                });

                const blob = new Blob([res.data], { type: 'text/csv;charset=utf-8;' });
                const url = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = url;
                link.setAttribute('download', `employees_export_${new Date().toISOString().slice(0, 10)}.csv`);
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                URL.revokeObjectURL(url);
                notify.toast('Employee export downloaded.', 'success');
            } catch (err) {
                notify.error('Export Failed', 'Failed to generate employee CSV export.');
            }
        },
    },
});
```

---

### B. Pinia Store 2: `resources/js/stores/scheduleStore.js`

```javascript
import { defineStore } from 'pinia';
import apiClient from '../api/client';
import notify from '../utils/notify';

export const useScheduleStore = defineStore('schedule', {
    state: () => ({
        shifts: [],
        holidays: [],
        shiftAssignments: [],
        loading: false,
        saving: false,
        calendarMonth: new Date().getMonth(), // 0-11
        calendarYear: new Date().getFullYear(),
        calendarViewMode: 'month', // 'month' | 'list'
    }),

    getters: {
        activeShifts: (state) => state.shifts.filter(s => s.is_active !== false),
        shiftsMap: (state) => {
            const map = {};
            state.shifts.forEach(s => { map[s.id] = s; });
            return map;
        },
        holidaysMap: (state) => {
            const map = {};
            state.holidays.forEach(h => {
                if (!map[h.date]) map[h.date] = [];
                map[h.date].push(h);
            });
            return map;
        },
        monthHolidays: (state) => {
            const prefix = `${state.calendarYear}-${String(state.calendarMonth + 1).padStart(2, '0')}`;
            return state.holidays.filter(h => h.date && h.date.startsWith(prefix));
        },
    },

    actions: {
        setCalendarView(mode) {
            this.calendarViewMode = mode;
        },

        navigateMonth(delta) {
            let m = this.calendarMonth + delta;
            let y = this.calendarYear;
            if (m < 0) {
                m = 11;
                y -= 1;
            } else if (m > 11) {
                m = 0;
                y += 1;
            }
            this.calendarMonth = m;
            this.calendarYear = y;
            this.fetchHolidays();
        },

        setToday() {
            const now = new Date();
            this.calendarMonth = now.getMonth();
            this.calendarYear = now.getFullYear();
            this.fetchHolidays();
        },

        // --- Shifts ---
        async fetchShifts() {
            this.loading = true;
            try {
                const res = await apiClient.get('/shifts');
                this.shifts = res.data.data || res.data || [];
            } catch (err) {
                console.warn('Failed to load shifts:', err);
            } finally {
                this.loading = false;
            }
        },

        async createShift(payload) {
            this.saving = true;
            try {
                const res = await apiClient.post('/shifts', payload);
                const shift = res.data.data || res.data;
                notify.success('Shift Created', `Shift "${shift.name}" created successfully.`);
                await this.fetchShifts();
                return shift;
            } catch (err) {
                notify.error('Creation Failed', err.response?.data?.message || 'Could not create shift.');
                throw err;
            } finally {
                this.saving = false;
            }
        },

        async updateShift(id, payload) {
            this.saving = true;
            try {
                const res = await apiClient.put(`/shifts/${id}`, payload);
                const shift = res.data.data || res.data;
                notify.success('Shift Updated', `Shift "${shift.name}" updated successfully.`);
                await this.fetchShifts();
                return shift;
            } catch (err) {
                notify.error('Update Failed', err.response?.data?.message || 'Could not update shift.');
                throw err;
            } finally {
                this.saving = false;
            }
        },

        async deleteShift(id) {
            try {
                await apiClient.delete(`/shifts/${id}`);
                notify.toast('Shift deleted successfully.', 'info');
                await this.fetchShifts();
            } catch (err) {
                notify.error('Delete Failed', err.response?.data?.message || 'Could not delete shift.');
                throw err;
            }
        },

        async bulkAssignShift(payload) {
            this.saving = true;
            try {
                const res = await apiClient.post('/shifts/bulk-assign', payload);
                notify.success('Schedule Assigned', 'Shift assignment applied successfully.');
                return res.data;
            } catch (err) {
                notify.error('Assignment Error', err.response?.data?.message || 'Failed to assign shifts.');
                throw err;
            } finally {
                this.saving = false;
            }
        },

        // --- Holidays ---
        async fetchHolidays() {
            try {
                const res = await apiClient.get('/holidays', {
                    params: { year: this.calendarYear }
                });
                this.holidays = res.data.data || res.data || [];
            } catch (err) {
                console.warn('Failed to load holidays:', err);
            }
        },

        async createHoliday(payload) {
            this.saving = true;
            try {
                const res = await apiClient.post('/holidays', payload);
                const holiday = res.data.data || res.data;
                notify.success('Holiday Added', `Holiday "${holiday.name}" saved.`);
                await this.fetchHolidays();
                return holiday;
            } catch (err) {
                notify.error('Holiday Error', err.response?.data?.message || 'Failed to add holiday.');
                throw err;
            } finally {
                this.saving = false;
            }
        },

        async updateHoliday(id, payload) {
            this.saving = true;
            try {
                const res = await apiClient.put(`/holidays/${id}`, payload);
                const holiday = res.data.data || res.data;
                notify.success('Holiday Updated', `Holiday "${holiday.name}" updated.`);
                await this.fetchHolidays();
                return holiday;
            } catch (err) {
                notify.error('Update Error', err.response?.data?.message || 'Failed to update holiday.');
                throw err;
            } finally {
                this.saving = false;
            }
        },

        async deleteHoliday(id) {
            try {
                await apiClient.delete(`/holidays/${id}`);
                notify.toast('Holiday removed.', 'info');
                await this.fetchHolidays();
            } catch (err) {
                notify.error('Delete Error', err.response?.data?.message || 'Could not delete holiday.');
                throw err;
            }
        },
    },
});
```

---

### C. Employee UI Suite Components

#### 1. `resources/js/components/employees/EmployeeDirectory.vue`
- **Key Features**:
  - Filter bar with debounced Search, Department, Designation, Status, and Type filters.
  - Table vs Card Grid view toggle with localStorage persistence.
  - Status badges with customized color palettes:
    - Active: `bg-emerald-50 text-emerald-700 border-emerald-200`
    - On Leave: `bg-amber-50 text-amber-700 border-amber-200`
    - Suspended: `bg-rose-50 text-rose-700 border-rose-200`
    - Terminated / Resigned: `bg-slate-100 text-slate-600 border-slate-200`
  - Biometric face badge indicating whether edge camera face enrollment is active:
    - Enrolled: `📷 Linked (#CustomizeID)`
    - Missing: `⚠️ No Face Enrolled`
  - Direct actions: View Profile modal, Edit modal, Assign Shift modal, Soft Delete with `notify.confirm`.
  - CSV Bulk Import modal with dropzone & CSV Export action.

#### 2. `resources/js/components/employees/EmployeeProfileModal.vue`
- **Key Features**:
  - Profile header with large avatar, employee code, name, designation, department, and active status pill.
  - 4 tabs:
    1. **Personal Info**: Work & personal emails, phone, gender, birthday, emergency contacts.
    2. **Employment Details**: Department, Designation (Level), Location/Site, Reporting Manager, Joining Date, Tenure calculation.
    3. **Shift Schedule**: Assigned Shift name, Work hours (`09:00 - 18:00`), Grace period, Break duration, Overnight indicator, and assigned days of the week badges (`Mon` `Tue` `Wed` `Thu` `Fri`).
    4. **Camera Face Biometrics**: High-res preview of stored face, linked `personnel_id`, camera `customize_id`, permanent/temporary validity, and instant "Re-sync to Cameras" action.

#### 3. `resources/js/components/employees/EmployeeFormModal.vue`
- **Key Features**:
  - Unified Create & Edit form with pre-filled fields in edit mode.
  - Form validation on required fields (`employee_code`, `first_name`, `last_name`, `date_of_joining`).
  - Auto-generate employee code button if left blank.
  - Department, Designation, Location, Manager, Shift dropdowns populated from store metadata.
  - **Biometric Photo Ingestion**:
    - **Mode A: File Upload**: Drag-and-drop or file selector, reading via `FileReader` into Base64 for instant preview and backend sync.
    - **Mode B: Live Webcam Snapshot**: Accesses `navigator.mediaDevices.getUserMedia({ video: { width: 640, height: 480 } })`, renders live video stream with an oval face framing overlay, and grabs canvas frame as Base64 on click. Cleanly shuts down video tracks on unmount.
    - Checkbox: "Auto-sync to Edge Camera Fleet (`personnel` library)".

---

### D. Shift & Schedule UI Suite Components

#### 1. `resources/js/components/schedules/ShiftManager.vue`
- **Key Features**:
  - Visual Shift cards styled with colored left-accent border matching shift hex color (`color` field).
  - Metrics: Start to End times, calculated total work duration, grace period, early-out threshold, break duration, and overnight tag (`🌙 Overnight`).
  - Shift Form Modal:
    - `<input type="time">` pickers for start and end times.
    - Color picker with 10 vibrant/accessible presets + hex code input.
    - Grace period (minutes), Early out (minutes), Half-day threshold (hours), Minimum full day (hours), Break duration (minutes).
    - Overnight shift checkbox toggle.
    - Flexible hours checkbox toggle.
    - Active toggle switch.

#### 2. `resources/js/components/schedules/ShiftAssignment.vue`
- **Key Features**:
  - Supports single employee assignment or entire department batch assignment.
  - Shift selector with color dot and timing info.
  - Effective date range (Effective From to Effective To / Indefinite).
  - Assigned Days of Week selector: Interactive checkboxes for `Mon`, `Tue`, `Wed`, `Thu`, `Fri`, `Sat`, `Sun` with quick preset buttons ("Mon-Fri", "Mon-Sat", "All 7 Days").
  - Table of active shift assignments with actions to modify or remove.

#### 3. `resources/js/components/schedules/HolidayCalendar.vue`
- **Key Features**:
  - Full native 7-column Month Calendar (Sunday through Saturday).
  - Previous / Next month navigation with "Today" button and current month/year display.
  - Calendar cells display:
    - Day number.
    - Current day highlighting.
    - Weekend subtle gray shading.
    - Holiday chips categorized by type:
      - Public Holiday: Crimson (`bg-rose-100 text-rose-800 border-rose-200`)
      - Company Holiday: Indigo (`bg-indigo-100 text-indigo-800 border-indigo-200`)
      - Optional / Floater: Amber (`bg-amber-100 text-amber-800 border-amber-200`)
  - Alternative Holiday List view table for quick tabular review of all holidays across the year.
  - Holiday Modal: Name, Date, Type select, Annual Recurrence toggle, Department scope.

#### 4. `resources/js/components/schedules/ScheduleHub.vue`
- **Key Features**:
  - High-level container mimicking `SettingsHub.vue`.
  - Header with title: "Work Schedules & Rosters".
  - Pill navigation bar:
    - `⏱️ Shift Definitions` (`ShiftManager.vue`)
    - `📋 Shift Assignments & Rosters` (`ShiftAssignment.vue`)
    - `🗓️ Holiday Calendar` (`HolidayCalendar.vue`)

---

### E. App Navigation Integration (`resources/js/App.vue`)

Update `resources/js/App.vue` as follows:

1. **Imports & Async Loading**:
   ```javascript
   const EmployeeDirectory = defineAsyncComponent(() => import('./components/employees/EmployeeDirectory.vue'));
   const ScheduleHub = defineAsyncComponent(() => import('./components/schedules/ScheduleHub.vue'));
   ```

2. **Tab Definitions**:
   ```javascript
   const baseTabs = [
     { id: 'live', label: 'Live Telemetry', icon: '📹' },
     { id: 'strangers', label: 'Stranger Snaps', icon: '🎭' },
     { id: 'employees', label: 'Employees', icon: '👤', roles: ['admin', 'hr-manager', 'manager'] },
     { id: 'schedules', label: 'Schedules', icon: '🕐', roles: ['admin', 'hr-manager', 'manager'] },
     { id: 'personnel', label: 'Personnel & Face Library', icon: '👥' },
     { id: 'devices', label: 'Camera Devices', icon: '📡' },
     { id: 'logs', label: 'Access Audit Logs', icon: '📋' },
     { id: 'sync', label: 'Sync Outbox Queue', icon: '⚡' },
     { id: 'settings', label: 'Settings & Org', icon: '⚙️', requiresAdmin: true },
   ];

   const visibleTabs = computed(() => {
     return baseTabs.filter(tab => {
       if (tab.requiresAdmin && !authStore.isAdmin) return false;
       if (tab.roles && !authStore.hasAnyRole(tab.roles)) return false;
       return true;
     });
   });
   ```

3. **Template Main View Slot**:
   ```html
   <main class="max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6 flex-1 flex flex-col">
     <!-- Tabs -->
     <nav class="flex items-center gap-2 border-b border-slate-200 mb-6 overflow-x-auto pb-1">
       ...
     </nav>

     <!-- Active Tab View -->
     <div class="flex-1">
       <LiveTelemetry v-if="currentTab === 'live'" />
       <StrangerSnapsMonitor v-else-if="currentTab === 'strangers'" />
       <EmployeeDirectory v-else-if="currentTab === 'employees'" />
       <ScheduleHub v-else-if="currentTab === 'schedules'" />
       <PersonnelManager v-else-if="currentTab === 'personnel'" />
       <DeviceManager v-else-if="currentTab === 'devices'" />
       <AccessLogsHistory v-else-if="currentTab === 'logs'" />
       <SyncTasksMonitor v-else-if="currentTab === 'sync'" />
       <SettingsHub v-else-if="currentTab === 'settings'" />
     </div>
   </main>
   ```

---

## 5. Verification Method

To verify the implementation independently:

1. **Vite Clean Build Check**:
   Run:
   ```bash
   npm run build
   ```
   **Expected Result**: Vite compiles all modules with exit code 0, generating chunks for `EmployeeDirectory`, `ScheduleHub`, and Pinia stores without syntax errors, missing imports, or CSS post-processing warnings.

2. **File Structure Inspection**:
   Confirm the presence of all 9 files:
   - `resources/js/stores/employeeStore.js`
   - `resources/js/stores/scheduleStore.js`
   - `resources/js/components/employees/EmployeeDirectory.vue`
   - `resources/js/components/employees/EmployeeProfileModal.vue`
   - `resources/js/components/employees/EmployeeFormModal.vue`
   - `resources/js/components/schedules/ShiftManager.vue`
   - `resources/js/components/schedules/ShiftAssignment.vue`
   - `resources/js/components/schedules/HolidayCalendar.vue`
   - `resources/js/components/schedules/ScheduleHub.vue`

3. **RBAC Guard Invalidation Condition**:
   - Log in as user with role `security` or `receptionist`: Neither the `👤 Employees` nor `🕐 Schedules` tabs should appear in the navigation bar.
   - Log in as user with role `manager`, `hr-manager`, or `admin`: Both `👤 Employees` and `🕐 Schedules` tabs must be rendered in the navigation bar and functional.
