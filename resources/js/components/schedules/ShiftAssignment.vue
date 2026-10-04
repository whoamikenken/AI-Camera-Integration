<template>
  <div class="space-y-6">
    <!-- Header -->
    <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs">
      <h3 class="text-base font-bold text-slate-900 tracking-tight">Shift Scheduling &amp; Roster Assignments</h3>
      <p class="text-xs text-slate-500 mt-0.5">
        Batch assign shifts to entire departments or individual staff members with custom effective dates and working days.
      </p>
    </div>

    <!-- Assignment Form Card -->
    <div class="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-xs space-y-6">
      <div class="border-b border-slate-100 pb-4">
        <h4 class="text-sm font-bold text-slate-900">Configure Shift Assignment</h4>
        <p class="text-xs text-slate-500 mt-0.5">Select assignment scope, target shift, and schedule parameters.</p>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-xs">
        <!-- Target Shift -->
        <div>
          <label class="block font-semibold text-slate-700 mb-1.5">1. Select Shift <span class="text-rose-500">*</span></label>
          <div class="space-y-2">
            <div
              v-for="s in scheduleStore.activeShifts"
              :key="s.id"
              @click="form.shift_id = s.id"
              class="p-3 rounded-xl border transition-all cursor-pointer flex items-center justify-between"
              :class="form.shift_id === s.id ? 'border-indigo-600 bg-indigo-50/50 shadow-xs' : 'border-slate-200 hover:border-slate-300 bg-white'"
            >
              <div class="flex items-center gap-3">
                <span class="w-3 h-3 rounded-full" :style="{ backgroundColor: s.color || '#3B82F6' }"></span>
                <div>
                  <div class="font-bold text-slate-900">{{ s.name }}</div>
                  <div class="text-[11px] text-slate-500 font-mono">
                    {{ s.shift_start?.slice(0, 5) }} - {{ s.shift_end?.slice(0, 5) }}
                    <span v-if="s.is_overnight" class="text-purple-600 font-semibold">(Overnight)</span>
                  </div>
                </div>
              </div>
              <input type="radio" :value="s.id" v-model="form.shift_id" class="text-indigo-600" />
            </div>
          </div>
        </div>

        <!-- Target Scope (Departments or Employees) -->
        <div class="space-y-4">
          <div>
            <label class="block font-semibold text-slate-700 mb-1.5">2. Assignment Scope <span class="text-rose-500">*</span></label>
            <div class="flex gap-2">
              <button
                type="button"
                @click="scopeMode = 'department'"
                class="flex-1 py-2 text-xs font-semibold rounded-xl border transition-all cursor-pointer"
                :class="scopeMode === 'department' ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50'"
              >
                🏢 By Department
              </button>
              <button
                type="button"
                @click="scopeMode = 'employee'"
                class="flex-1 py-2 text-xs font-semibold rounded-xl border transition-all cursor-pointer"
                :class="scopeMode === 'employee' ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50'"
              >
                👤 Individual Staff
              </button>
            </div>
          </div>

          <!-- Department Multi-select -->
          <div v-if="scopeMode === 'department'">
            <label class="block font-semibold text-slate-700 mb-1">Select Department(s)</label>
            <div class="max-h-48 overflow-y-auto border border-slate-200 rounded-xl p-2 space-y-1 bg-white">
              <label
                v-for="dept in employeeStore.departments"
                :key="dept.id"
                class="flex items-center gap-2 p-1.5 hover:bg-slate-50 rounded-lg cursor-pointer text-xs"
              >
                <input
                  type="checkbox"
                  :value="dept.id"
                  v-model="form.department_ids"
                  class="rounded text-indigo-600"
                />
                <span class="text-slate-800 font-medium">{{ dept.name }}</span>
                <span class="text-slate-400 font-mono text-[10px]">({{ dept.code }})</span>
              </label>
            </div>
          </div>

          <!-- Employee Multi-select -->
          <div v-else>
            <label class="block font-semibold text-slate-700 mb-1">Select Employee(s)</label>
            <div class="max-h-48 overflow-y-auto border border-slate-200 rounded-xl p-2 space-y-1 bg-white">
              <label
                v-for="emp in employeeStore.employees"
                :key="emp.id"
                class="flex items-center gap-2 p-1.5 hover:bg-slate-50 rounded-lg cursor-pointer text-xs"
              >
                <input
                  type="checkbox"
                  :value="emp.id"
                  v-model="form.employee_ids"
                  class="rounded text-indigo-600"
                />
                <span class="text-slate-800 font-medium">{{ emp.first_name }} {{ emp.last_name }}</span>
                <span class="text-slate-400 font-mono text-[10px]">({{ emp.employee_code }})</span>
              </label>
            </div>
          </div>
        </div>
      </div>

      <!-- Schedule Parameters: Dates & Assigned Days -->
      <div class="border-t border-slate-100 pt-5 space-y-4 text-xs">
        <h4 class="font-bold text-slate-900">3. Effective Period &amp; Working Days</h4>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block font-semibold text-slate-700 mb-1">Effective From <span class="text-rose-500">*</span></label>
            <input
              v-model="form.effective_from"
              type="date"
              class="w-full px-3 py-2 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
              required
            />
          </div>

          <div>
            <label class="block font-semibold text-slate-700 mb-1">Effective To (Leave empty for indefinite)</label>
            <input
              v-model="form.effective_to"
              type="date"
              placeholder="Indefinite"
              class="w-full px-3 py-2 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
            />
          </div>
        </div>

        <!-- Assigned Days of the Week Toggle -->
        <div>
          <div class="flex items-center justify-between mb-2">
            <label class="font-semibold text-slate-700">Assigned Working Days:</label>
            <div class="flex gap-1.5 text-[11px]">
              <button type="button" @click="setWeekdayPreset('standard')" class="text-indigo-600 hover:underline">Mon-Fri</button>
              <span class="text-slate-300">&bull;</span>
              <button type="button" @click="setWeekdayPreset('sixday')" class="text-indigo-600 hover:underline">Mon-Sat</button>
              <span class="text-slate-300">&bull;</span>
              <button type="button" @click="setWeekdayPreset('all')" class="text-indigo-600 hover:underline">All 7 Days</button>
            </div>
          </div>

          <div class="flex flex-wrap gap-2">
            <button
              v-for="day in weekDays"
              :key="day.id"
              type="button"
              @click="toggleDay(day.id)"
              class="px-3 py-2 rounded-xl border text-xs font-bold transition-all cursor-pointer"
              :class="form.assigned_days.includes(day.id) ? 'bg-indigo-600 text-white border-indigo-600 shadow-xs' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50'"
            >
              {{ day.label }}
            </button>
          </div>
        </div>
      </div>

      <!-- Submit Row -->
      <div class="border-t border-slate-100 pt-4 flex items-center justify-end gap-3">
        <button
          type="button"
          :disabled="scheduleStore.saving"
          @click="submitAssignment"
          class="px-6 py-2.5 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 rounded-xl shadow-xs transition-colors cursor-pointer"
        >
          <span v-if="scheduleStore.saving">Assigning Schedules...</span>
          <span v-else>Apply Shift Assignment</span>
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { useScheduleStore } from '../../stores/scheduleStore';
import { useEmployeeStore } from '../../stores/employeeStore';

const scheduleStore = useScheduleStore();
const employeeStore = useEmployeeStore();

const scopeMode = ref('department');

const weekDays = [
  { id: 1, label: 'Mon' },
  { id: 2, label: 'Tue' },
  { id: 3, label: 'Wed' },
  { id: 4, label: 'Thu' },
  { id: 5, label: 'Fri' },
  { id: 6, label: 'Sat' },
  { id: 7, label: 'Sun' },
];

const form = reactive({
  shift_id: '',
  department_ids: [],
  employee_ids: [],
  effective_from: new Date().toISOString().slice(0, 10),
  effective_to: '',
  assigned_days: [1, 2, 3, 4, 5],
});

onMounted(async () => {
  await scheduleStore.fetchShifts();
  await employeeStore.fetchMetadata();
  await employeeStore.fetchEmployees(1);
  if (scheduleStore.shifts[0]) {
    form.shift_id = scheduleStore.shifts[0].id;
  }
});

function toggleDay(dayId) {
  const idx = form.assigned_days.indexOf(dayId);
  if (idx > -1) {
    form.assigned_days.splice(idx, 1);
  } else {
    form.assigned_days.push(dayId);
    form.assigned_days.sort();
  }
}

function setWeekdayPreset(preset) {
  if (preset === 'standard') {
    form.assigned_days = [1, 2, 3, 4, 5];
  } else if (preset === 'sixday') {
    form.assigned_days = [1, 2, 3, 4, 5, 6];
  } else if (preset === 'all') {
    form.assigned_days = [1, 2, 3, 4, 5, 6, 7];
  }
}

async function submitAssignment() {
  if (!form.shift_id) {
    alert('Please select a shift.');
    return;
  }
  if (scopeMode.value === 'department' && form.department_ids.length === 0) {
    alert('Please select at least one department.');
    return;
  }
  if (scopeMode.value === 'employee' && form.employee_ids.length === 0) {
    alert('Please select at least one employee.');
    return;
  }

  const payload = {
    shift_id: form.shift_id,
    effective_from: form.effective_from,
    effective_to: form.effective_to || null,
    assigned_days: form.assigned_days,
  };

  if (scopeMode.value === 'department') {
    payload.department_ids = form.department_ids;
  } else {
    payload.employee_ids = form.employee_ids;
  }

  await scheduleStore.bulkAssignShift(payload);

  // Clear selections
  form.department_ids = [];
  form.employee_ids = [];
}
</script>
