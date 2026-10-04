<template>
  <div class="space-y-6">
    <!-- Top Action Bar & Metrics -->
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs">
      <div>
        <div class="flex items-center gap-2">
          <h2 class="text-base font-bold text-slate-900 tracking-tight">Workforce Directory</h2>
          <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-indigo-50 text-indigo-700 border border-indigo-200">
            {{ store.totalCount }} Total Employees
          </span>
        </div>
        <p class="text-xs text-slate-500 mt-0.5">
          Manage employee records, organizational roles, assigned shifts, and camera facial recognition links.
        </p>
      </div>

      <div class="flex flex-wrap items-center gap-2.5 w-full sm:w-auto">
        <!-- View Toggle (Table vs Grid) -->
        <div class="flex items-center bg-slate-100 p-1 rounded-xl border border-slate-200" role="group" aria-label="View layout switcher">
          <button
            type="button"
            @click="store.setViewMode('table')"
            :aria-pressed="store.viewMode === 'table'"
            class="px-2.5 py-1 text-xs font-semibold rounded-lg transition-all cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
            :class="store.viewMode === 'table' ? 'bg-white text-slate-900 shadow-xs' : 'text-slate-500 hover:text-slate-800'"
            title="Table View"
          >
            📋 Table
          </button>
          <button
            type="button"
            @click="store.setViewMode('grid')"
            :aria-pressed="store.viewMode === 'grid'"
            class="px-2.5 py-1 text-xs font-semibold rounded-lg transition-all cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
            :class="store.viewMode === 'grid' ? 'bg-white text-slate-900 shadow-xs' : 'text-slate-500 hover:text-slate-800'"
            title="Grid Cards"
          >
            🔲 Grid
          </button>
        </div>

        <!-- Export CSV -->
        <button
          type="button"
          @click="store.exportCsv"
          class="px-3 py-2 text-xs font-semibold text-slate-700 bg-white border border-slate-200 hover:bg-slate-50 rounded-xl transition-colors cursor-pointer shadow-xs inline-flex items-center gap-1.5"
        >
          <span>📥</span> Export CSV
        </button>

        <!-- Import CSV -->
        <button
          type="button"
          @click="showImportModal = true"
          class="px-3 py-2 text-xs font-semibold text-slate-700 bg-white border border-slate-200 hover:bg-slate-50 rounded-xl transition-colors cursor-pointer shadow-xs inline-flex items-center gap-1.5"
        >
          <span>📤</span> Import
        </button>

        <!-- Enroll New Employee -->
        <button
          type="button"
          @click="openCreateModal"
          class="px-4 py-2 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl shadow-xs transition-colors cursor-pointer inline-flex items-center gap-1.5"
        >
          <span>➕</span> Enroll Employee
        </button>
      </div>
    </div>

    <!-- Live Filters Row -->
    <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-xs space-y-3">
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
        <!-- Search Box -->
        <div class="relative lg:col-span-1">
          <input
            v-model="searchQuery"
            type="text"
            aria-label="Search workforce by name, employee code, or email"
            placeholder="Search name, code, email..."
            class="w-full pl-8 pr-3 py-2 text-xs border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
            @input="handleSearch"
          />
          <span class="absolute left-2.5 top-2.5 text-xs text-slate-400 pointer-events-none">🔍</span>
        </div>

        <!-- Department Filter -->
        <div>
          <select
            :value="store.filters.department_id"
            @change="store.setFilter('department_id', $event.target.value)"
            aria-label="Filter by department"
            class="w-full px-3 py-2 text-xs border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 bg-white"
          >
            <option value="">All Departments</option>
            <option v-for="dept in store.departments" :key="dept.id" :value="dept.id">
              {{ dept.name }}
            </option>
          </select>
        </div>

        <!-- Designation Filter -->
        <div>
          <select
            :value="store.filters.designation_id"
            @change="store.setFilter('designation_id', $event.target.value)"
            aria-label="Filter by designation"
            class="w-full px-3 py-2 text-xs border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 bg-white"
          >
            <option value="">All Roles / Designations</option>
            <option v-for="desig in store.designations" :key="desig.id" :value="desig.id">
              {{ desig.name }}
            </option>
          </select>
        </div>

        <!-- Status Filter -->
        <div>
          <select
            :value="store.filters.employment_status"
            @change="store.setFilter('employment_status', $event.target.value)"
            aria-label="Filter by employment status"
            class="w-full px-3 py-2 text-xs border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 bg-white"
          >
            <option value="">All Employment Statuses</option>
            <option value="active">Active (Whitelisted)</option>
            <option value="probation">Probation</option>
            <option value="suspended">Suspended (Blacklisted)</option>
            <option value="terminated">Terminated</option>
            <option value="resigned">Resigned</option>
          </select>
        </div>

        <!-- Employment Type -->
        <div>
          <select
            :value="store.filters.employment_type"
            @change="store.setFilter('employment_type', $event.target.value)"
            aria-label="Filter by work type"
            class="w-full px-3 py-2 text-xs border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 bg-white"
          >
            <option value="">All Work Types</option>
            <option value="full-time">Full-Time</option>
            <option value="part-time">Part-Time</option>
            <option value="contract">Contract</option>
            <option value="intern">Intern</option>
            <option value="temporary">Temporary</option>
          </select>
        </div>
      </div>

      <!-- Reset Filter Pill Bar -->
      <div v-if="store.hasActiveFilters" class="flex items-center justify-between pt-2 border-t border-slate-100 text-xs">
        <span class="text-slate-500">Filtered view active</span>
        <button
          type="button"
          @click="resetAllFilters"
          class="text-indigo-600 font-semibold hover:text-indigo-800 cursor-pointer"
        >
          Reset All Filters ✕
        </button>
      </div>
    </div>

    <!-- Loading Skeleton -->
    <div v-if="store.loading" class="bg-white rounded-2xl p-12 text-center border border-slate-200 shadow-xs">
      <div class="inline-block animate-spin text-2xl text-indigo-600 mb-2">⏳</div>
      <div class="text-xs font-semibold text-slate-600">Loading workforce directory...</div>
    </div>

    <!-- Empty State -->
    <div v-else-if="store.employees.length === 0" class="bg-white rounded-2xl p-12 text-center border border-slate-200 shadow-xs">
      <div class="text-4xl mb-3">👥</div>
      <h3 class="text-sm font-bold text-slate-800">No Employees Found</h3>
      <p class="text-xs text-slate-500 mt-1 max-w-sm mx-auto">
        No employees match the applied filter criteria or no workforce records have been registered yet.
      </p>
      <div class="mt-4">
        <button
          @click="openCreateModal"
          class="px-4 py-2 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl shadow-xs transition-colors cursor-pointer"
        >
          Enroll First Employee
        </button>
      </div>
    </div>

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
          <tbody class="divide-y divide-slate-100">
            <tr
              v-for="emp in store.employees"
              :key="emp.id"
              class="hover:bg-slate-50/60 transition-colors"
            >
              <!-- Name & Avatar -->
              <td class="px-5 py-3.5">
                <div class="flex items-center gap-3">
                  <div class="w-9 h-9 rounded-xl bg-slate-100 border border-slate-200 overflow-hidden flex items-center justify-center shrink-0">
                    <img
                      v-if="emp.avatar || emp.personnel?.photo_base64"
                      :src="emp.avatar || emp.personnel?.photo_base64"
                      alt="Avatar"
                      class="w-full h-full object-cover"
                    />
                    <span v-else class="text-xs font-bold text-slate-500">
                      {{ emp.first_name[0] }}{{ emp.last_name ? emp.last_name[0] : '' }}
                    </span>
                  </div>
                  <div>
                    <div class="font-bold text-slate-900 leading-tight">
                      {{ emp.first_name }} {{ emp.last_name }}
                    </div>
                    <div class="text-[11px] text-slate-500 font-mono truncate max-w-[180px]">
                      {{ emp.work_email || emp.phone || 'No contact' }}
                    </div>
                  </div>
                </div>
              </td>

              <!-- Employee Code -->
              <td class="px-4 py-3.5 font-mono text-[11px] font-bold text-slate-700">
                {{ emp.employee_code }}
              </td>

              <!-- Dept & Role -->
              <td class="px-4 py-3.5">
                <div class="font-medium text-slate-900">{{ emp.department?.name || '—' }}</div>
                <div class="text-[11px] text-slate-500">{{ emp.designation?.name || 'Staff' }}</div>
              </td>

              <!-- Shift Schedule -->
              <td class="px-4 py-3.5">
                <div class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-lg text-[11px] font-medium bg-slate-100 text-slate-800">
                  <span class="w-2 h-2 rounded-full" :style="{ backgroundColor: emp.shift?.color || '#3B82F6' }"></span>
                  <span>{{ emp.shift?.name || 'Standard Day' }}</span>
                </div>
              </td>

              <!-- Status -->
              <td class="px-4 py-3.5">
                <span
                  class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider border"
                  :class="statusPillClass(emp.employment_status)"
                >
                  {{ emp.employment_status || 'active' }}
                </span>
              </td>

              <!-- Face Biometric Link -->
              <td class="px-4 py-3.5">
                <span
                  v-if="emp.personnel_id"
                  class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200"
                  title="Biometric Face Enrolled & Synced"
                >
                  <span>📷 Linked (#{{ emp.personnel?.customize_id }})</span>
                </span>
                <span
                  v-else
                  class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-50 text-amber-700 border border-amber-200"
                  title="No Face Photo Enrolled"
                >
                  <span>⚠️ No Face</span>
                </span>
              </td>

              <!-- Row Actions -->
              <td class="px-5 py-3.5 text-right">
                <div class="inline-flex items-center gap-1">
                  <button
                    @click="viewProfile(emp)"
                    :aria-label="`View full profile of ${emp.first_name} ${emp.last_name || ''}`"
                    class="p-1.5 text-slate-500 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    title="View Full Profile"
                  >
                    <span aria-hidden="true">👁️</span>
                  </button>
                  <button
                    @click="editEmployee(emp)"
                    :aria-label="`Edit employee ${emp.first_name} ${emp.last_name || ''}`"
                    class="p-1.5 text-slate-500 hover:text-slate-800 hover:bg-slate-100 rounded-lg transition-colors cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    title="Edit Record"
                  >
                    <span aria-hidden="true">✏️</span>
                  </button>
                  <button
                    @click="openAssignShiftModal(emp)"
                    :aria-label="`Assign shift schedule for ${emp.first_name} ${emp.last_name || ''}`"
                    class="p-1.5 text-slate-500 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    title="Assign Shift"
                  >
                    <span aria-hidden="true">⏱️</span>
                  </button>
                  <button
                    @click="confirmDelete(emp)"
                    :aria-label="`Delete record for ${emp.first_name} ${emp.last_name || ''}`"
                    class="p-1.5 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition-colors cursor-pointer focus:outline-none focus:ring-2 focus:ring-rose-500"
                    title="Archive / Soft Delete"
                  >
                    <span aria-hidden="true">🗑️</span>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination Footer -->
      <div class="px-5 py-3 border-t border-slate-100 flex items-center justify-between text-xs text-slate-500">
        <div>
          Showing page <span class="font-bold text-slate-800">{{ store.pagination.current_page }}</span> of
          <span class="font-bold text-slate-800">{{ store.pagination.last_page }}</span>
          ({{ store.pagination.total }} records)
        </div>
        <div class="flex items-center gap-1.5">
          <button
            :disabled="store.pagination.current_page <= 1"
            @click="store.fetchEmployees(store.pagination.current_page - 1)"
            class="px-3 py-1 bg-white border border-slate-200 hover:bg-slate-50 disabled:opacity-40 rounded-lg cursor-pointer transition-colors shadow-xs"
          >
            Previous
          </button>
          <button
            :disabled="store.pagination.current_page >= store.pagination.last_page"
            @click="store.fetchEmployees(store.pagination.current_page + 1)"
            class="px-3 py-1 bg-white border border-slate-200 hover:bg-slate-50 disabled:opacity-40 rounded-lg cursor-pointer transition-colors shadow-xs"
          >
            Next
          </button>
        </div>
      </div>
    </div>

    <!-- Content: Mode 2 - Modern Card Grid View -->
    <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
      <div
        v-for="emp in store.employees"
        :key="emp.id"
        class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs hover:shadow-md transition-all flex flex-col justify-between"
      >
        <div class="flex items-start gap-4">
          <div class="w-14 h-14 rounded-2xl bg-slate-100 border border-slate-200 overflow-hidden flex items-center justify-center shrink-0">
            <img
              v-if="emp.avatar || emp.personnel?.photo_base64"
              :src="emp.avatar || emp.personnel?.photo_base64"
              alt="Avatar"
              class="w-full h-full object-cover"
            />
            <span v-else class="text-xl text-slate-400">👤</span>
          </div>

          <div class="flex-1 min-w-0">
            <div class="flex items-center justify-between gap-2">
              <h4 class="text-sm font-bold text-slate-900 truncate">
                {{ emp.first_name }} {{ emp.last_name }}
              </h4>
              <span
                class="px-2 py-0.5 rounded-full text-[9px] font-bold uppercase tracking-wider border shrink-0"
                :class="statusPillClass(emp.employment_status)"
              >
                {{ emp.employment_status || 'active' }}
              </span>
            </div>

            <div class="text-[11px] font-mono text-indigo-600 font-semibold mt-0.5">
              {{ emp.employee_code }}
            </div>
            <div class="text-xs text-slate-600 mt-1 truncate">
              {{ emp.designation?.name || 'Staff' }} &bull; {{ emp.department?.name || 'Unassigned' }}
            </div>
          </div>
        </div>

        <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs">
          <div class="flex items-center gap-1.5">
            <span class="w-2 h-2 rounded-full" :style="{ backgroundColor: emp.shift?.color || '#3B82F6' }"></span>
            <span class="text-[11px] text-slate-600">{{ emp.shift?.name || 'Standard Day' }}</span>
          </div>

          <div class="flex items-center gap-1">
            <button
              @click="viewProfile(emp)"
              :aria-label="`View profile of ${emp.first_name} ${emp.last_name || ''}`"
              class="px-2.5 py-1 text-[11px] font-semibold text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-lg transition-colors cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
            >
              Profile
            </button>
            <button
              @click="editEmployee(emp)"
              :aria-label="`Edit record for ${emp.first_name} ${emp.last_name || ''}`"
              class="px-2.5 py-1 text-[11px] font-semibold text-indigo-600 bg-indigo-50 hover:bg-indigo-100 rounded-lg transition-colors cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
            >
              Edit
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Modals -->
    <!-- 1. Profile Modal -->
    <EmployeeProfileModal
      :show="showProfileModal"
      :employee="selectedEmployee"
      @close="showProfileModal = false"
      @edit="editFromProfile"
      @assign-shift="openAssignShiftModal"
    />

    <!-- 2. Form Modal (Create / Edit) -->
    <EmployeeFormModal
      :show="showFormModal"
      :employee="selectedEmployee"
      @close="showFormModal = false"
      @saved="handleSaved"
    />

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
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { useEmployeeStore } from '../../stores/employeeStore';
import EmployeeProfileModal from './EmployeeProfileModal.vue';
import EmployeeFormModal from './EmployeeFormModal.vue';

const store = useEmployeeStore();

const searchQuery = ref('');
let searchDebounce = null;

const showProfileModal = ref(false);
const showFormModal = ref(false);
const showShiftModal = ref(false);
const showImportModal = ref(false);

const selectedEmployee = ref(null);
const importFile = ref(null);

const shiftForm = reactive({
  shift_id: '',
  effective_from: new Date().toISOString().slice(0, 10),
  effective_to: '',
});

onMounted(async () => {
  await store.fetchMetadata();
  await store.fetchEmployees(1);
});

function handleSearch() {
  clearTimeout(searchDebounce);
  searchDebounce = setTimeout(() => {
    store.setFilter('search', searchQuery.value);
  }, 300);
}

function resetAllFilters() {
  searchQuery.value = '';
  store.resetFilters();
}

function openCreateModal() {
  selectedEmployee.value = null;
  showFormModal.value = true;
}

function editEmployee(emp) {
  selectedEmployee.value = emp;
  showFormModal.value = true;
}

function viewProfile(emp) {
  selectedEmployee.value = emp;
  showProfileModal.value = true;
}

function editFromProfile(emp) {
  showProfileModal.value = false;
  editEmployee(emp);
}

function openAssignShiftModal(emp) {
  selectedEmployee.value = emp;
  shiftForm.shift_id = emp.shift_id || (store.shifts[0]?.id ?? '');
  shiftForm.effective_from = new Date().toISOString().slice(0, 10);
  shiftForm.effective_to = '';
  showShiftModal.value = true;
}

async function submitAssignShift() {
  if (!shiftForm.shift_id || !shiftForm.effective_from) return;
  await store.assignShift(selectedEmployee.value.id, shiftForm);
  showShiftModal.value = false;
}

async function confirmDelete(emp) {
  const confirmed = confirm(`Are you sure you want to delete ${emp.first_name} ${emp.last_name}? This will revoke camera biometric access.`);
  if (confirmed) {
    await store.deleteEmployee(emp.id);
  }
}

async function submitImport() {
  if (!importFile.value) return;
  await store.importCsv(importFile.value);
  showImportModal.value = false;
  importFile.value = null;
}

function handleSaved() {
  // Handled inside store
}

function statusPillClass(status) {
  switch (status) {
    case 'active':
      return 'bg-emerald-50 text-emerald-700 border-emerald-200';
    case 'probation':
      return 'bg-blue-50 text-blue-700 border-blue-200';
    case 'suspended':
      return 'bg-amber-50 text-amber-700 border-amber-200';
    case 'terminated':
    case 'resigned':
      return 'bg-rose-50 text-rose-700 border-rose-200';
    default:
      return 'bg-slate-50 text-slate-700 border-slate-200';
  }
}
</script>
