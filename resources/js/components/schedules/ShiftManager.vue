<template>
  <div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs">
      <div>
        <h3 class="text-base font-bold text-slate-900 tracking-tight">Shift Definitions</h3>
        <p class="text-xs text-slate-500 mt-0.5">
          Configure working hours, tolerance grace periods, overtime thresholds, and overnight shifts.
        </p>
      </div>

      <button
        type="button"
        @click="openCreateModal"
        class="px-4 py-2 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl shadow-xs transition-colors cursor-pointer inline-flex items-center gap-1.5"
      >
        <span>➕</span> New Shift
      </button>
    </div>

    <!-- Empty State -->
    <div v-if="store.shifts.length === 0" class="bg-white rounded-2xl border border-slate-200 p-8 text-center shadow-xs">
      <div class="text-4xl mb-3">🕒</div>
      <h4 class="text-sm font-bold text-slate-900 mb-1">No Shifts Defined</h4>
      <p class="text-xs text-slate-500 max-w-sm mx-auto">
        Get started by creating your first shift schedule. Configure working hours, grace periods, and break times.
      </p>
      <button
        type="button"
        @click="openCreateModal"
        class="mt-4 px-4 py-2 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl shadow-xs transition-colors cursor-pointer inline-flex items-center gap-1.5"
      >
        <span>➕</span> New Shift
      </button>
    </div>

    <!-- Shifts Grid -->
    <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
      <div
        v-for="shift in store.shifts"
        :key="shift.id"
        class="bg-white rounded-2xl border border-slate-200/80 p-5 shadow-xs hover:shadow-md transition-all flex flex-col justify-between relative overflow-hidden"
        :style="{ borderLeftColor: shift.color || '#3B82F6', borderLeftWidth: '6px' }"
      >
        <div>
          <!-- Title & Badges -->
          <div class="flex items-start justify-between gap-2">
            <div>
              <h4 class="text-sm font-bold text-slate-900">{{ shift.name }}</h4>
              <div class="text-[11px] font-mono text-indigo-600 font-semibold mt-0.5">
                {{ shift.code }}
              </div>
            </div>

            <div class="flex flex-col items-end gap-1">
              <span
                class="px-2 py-0.5 rounded-full text-[9px] font-bold uppercase tracking-wider border"
                :class="shift.is_active ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-slate-100 text-slate-600 border-slate-200'"
              >
                {{ shift.is_active ? 'Active' : 'Inactive' }}
              </span>
              <span
                v-if="shift.is_overnight"
                class="px-2 py-0.5 rounded-full text-[9px] font-bold bg-purple-50 text-purple-700 border border-purple-200"
              >
                🌙 Overnight
              </span>
            </div>
          </div>

          <!-- Time & Details -->
          <div class="mt-4 p-3 bg-slate-50 rounded-xl border border-slate-100 flex items-center justify-between text-xs">
            <div>
              <div class="text-[10px] text-slate-500 font-medium uppercase">Work Hours</div>
              <div class="font-bold text-slate-900 mt-0.5 font-mono">
                {{ shift.shift_start?.slice(0, 5) }} - {{ shift.shift_end?.slice(0, 5) }}
              </div>
            </div>
            <div class="text-right">
              <div class="text-[10px] text-slate-500 font-medium uppercase">Break Time</div>
              <div class="font-bold text-slate-900 mt-0.5">
                {{ shift.break_duration_minutes || 0 }} min
              </div>
            </div>
          </div>

          <!-- Metric Badges -->
          <div class="mt-3 grid grid-cols-2 gap-2 text-[11px] text-slate-600">
            <div class="flex items-center gap-1.5">
              <span>⏱️ Grace:</span>
              <span class="font-bold text-slate-800">{{ shift.grace_period_minutes || 0 }} min</span>
            </div>
            <div class="flex items-center gap-1.5">
              <span>🚪 Early Out:</span>
              <span class="font-bold text-slate-800">{{ shift.early_out_threshold_minutes || 0 }} min</span>
            </div>
          </div>
        </div>

        <!-- Action Row -->
        <div class="mt-5 pt-3 border-t border-slate-100 flex items-center justify-between text-xs">
          <span class="text-[11px] text-slate-400 font-medium">
            {{ shift.employees_count !== undefined ? `${shift.employees_count} staff assigned` : '' }}
          </span>

          <div class="flex items-center gap-1">
            <button
              @click="editShift(shift)" :aria-label="`Edit shift ${shift.name}`"
              class="px-2.5 py-1 text-[11px] font-semibold text-indigo-600 bg-indigo-50 hover:bg-indigo-100 rounded-lg transition-colors cursor-pointer"
            >
              Edit
            </button>
            <button
              @click="deleteShift(shift)" :aria-label="`Delete shift ${shift.name}`"
              class="px-2.5 py-1 text-[11px] font-semibold text-rose-600 hover:bg-rose-50 rounded-lg transition-colors cursor-pointer"
            >
              Delete
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Create / Edit Shift Modal -->
    <div v-if="showModal" class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="shift-modal-title" @keydown.escape="showModal = false">
      <div class="bg-white rounded-2xl shadow-xl border border-slate-200 max-w-lg w-full p-6 space-y-4">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
          <h3 id="shift-modal-title" class="text-sm font-bold text-slate-900">
            {{ isEditing ? 'Edit Shift Definition' : 'Create New Shift' }}
          </h3>
          <button @click="showModal = false" class="text-slate-400 hover:text-slate-600 cursor-pointer" aria-label="Close dialog">✕</button>
        </div>

        <form @submit.prevent="saveShift" class="space-y-4 text-xs">
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label for="shift_name" class="block font-semibold text-slate-700 mb-1">Shift Name <span class="text-rose-500">*</span></label>
              <input
                id="shift_name"
                v-model="form.name"
                type="text"
                placeholder="e.g. Morning Shift"
                class="w-full px-3 py-2 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
                required
              />
            </div>
            <div>
              <label for="shift_code" class="block font-semibold text-slate-700 mb-1">Shift Code <span class="text-rose-500">*</span></label>
              <input
                id="shift_code"
                v-model="form.code"
                type="text"
                placeholder="e.g. SHIFT-AM"
                class="w-full px-3 py-2 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 font-mono"
                required
              />
            </div>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label for="shift_start" class="block font-semibold text-slate-700 mb-1">Shift Start Time <span class="text-rose-500">*</span></label>
              <input
                id="shift_start"
                v-model="form.shift_start"
                type="time"
                step="1"
                class="w-full px-3 py-2 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 font-mono"
                required
              />
            </div>
            <div>
              <label for="shift_end" class="block font-semibold text-slate-700 mb-1">Shift End Time <span class="text-rose-500">*</span></label>
              <input
                id="shift_end"
                v-model="form.shift_end"
                type="time"
                step="1"
                class="w-full px-3 py-2 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 font-mono"
                required
              />
            </div>
          </div>

          <div class="grid grid-cols-3 gap-3">
            <div>
              <label for="grace_period" class="block font-semibold text-slate-700 mb-1">Grace Period (min)</label>
              <input
                id="grace_period"
                v-model.number="form.grace_period_minutes"
                type="number"
                min="0"
                class="w-full px-3 py-2 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
              />
            </div>
            <div>
              <label for="early_out" class="block font-semibold text-slate-700 mb-1">Early Out (min)</label>
              <input
                id="early_out"
                v-model.number="form.early_out_threshold_minutes"
                type="number"
                min="0"
                class="w-full px-3 py-2 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
              />
            </div>
            <div>
              <label for="break_time" class="block font-semibold text-slate-700 mb-1">Break Time (min)</label>
              <input
                id="break_time"
                v-model.number="form.break_duration_minutes"
                type="number"
                min="0"
                class="w-full px-3 py-2 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
              />
            </div>
          </div>

          <!-- Color & Overnight Checkbox -->
          <div class="flex items-center justify-between pt-2">
            <div class="flex items-center gap-2">
              <label for="shift_color" class="font-semibold text-slate-700">Display Color:</label>
              <input id="shift_color" v-model="form.color" type="color" class="w-8 h-8 rounded-lg cursor-pointer border border-slate-200 p-0.5" />
            </div>

            <div class="flex items-center gap-4">
              <div class="inline-flex items-center gap-1.5 cursor-pointer">
                <input id="shift_overnight" v-model="form.is_overnight" type="checkbox" class="rounded text-indigo-600" />
                <label for="shift_overnight" class="font-semibold text-slate-700 cursor-pointer">Overnight Shift (Crosses Midnight)</label>
              </div>

              <div class="inline-flex items-center gap-1.5 cursor-pointer">
                <input id="shift_active" v-model="form.is_active" type="checkbox" class="rounded text-indigo-600" />
                <label for="shift_active" class="font-semibold text-slate-700 cursor-pointer">Active</label>
              </div>
            </div>
          </div>

        <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-100">
          <button
            type="button"
            @click="showModal = false"
            class="px-3 py-1.5 text-xs text-slate-600 hover:bg-slate-100 rounded-xl cursor-pointer"
          >
            Cancel
          </button>
          <button
            type="submit"
            class="px-4 py-1.5 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl cursor-pointer shadow-xs"
          >
            {{ isEditing ? 'Save Changes' : 'Create Shift' }}
          </button>
        </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { useScheduleStore } from '../../stores/scheduleStore';

const store = useScheduleStore();
const showModal = ref(false);
const editingId = ref(null);
const isEditing = computed(() => !!editingId.value);

const form = reactive({
  name: '',
  code: '',
  shift_start: '09:00:00',
  shift_end: '18:00:00',
  grace_period_minutes: 15,
  early_out_threshold_minutes: 30,
  break_duration_minutes: 60,
  min_hours_full_day: 8.0,
  half_day_threshold_hours: 4.0,
  is_overnight: false,
  is_flexible: false,
  color: '#3B82F6',
  is_active: true,
});

onMounted(() => {
  store.fetchShifts();
});

function openCreateModal() {
  editingId.value = null;
  Object.assign(form, {
    name: '',
    code: 'SHIFT-' + Math.floor(10 + Math.random() * 90),
    shift_start: '09:00:00',
    shift_end: '18:00:00',
    grace_period_minutes: 15,
    early_out_threshold_minutes: 30,
    break_duration_minutes: 60,
    min_hours_full_day: 8.0,
    half_day_threshold_hours: 4.0,
    is_overnight: false,
    is_flexible: false,
    color: '#3B82F6',
    is_active: true,
  });
  showModal.value = true;
}

function editShift(s) {
  editingId.value = s.id;
  Object.assign(form, {
    name: s.name,
    code: s.code,
    shift_start: s.shift_start,
    shift_end: s.shift_end,
    grace_period_minutes: s.grace_period_minutes,
    early_out_threshold_minutes: s.early_out_threshold_minutes,
    break_duration_minutes: s.break_duration_minutes,
    min_hours_full_day: s.min_hours_full_day,
    half_day_threshold_hours: s.half_day_threshold_hours,
    is_overnight: !!s.is_overnight,
    is_flexible: !!s.is_flexible,
    color: s.color || '#3B82F6',
    is_active: s.is_active !== false,
  });
  showModal.value = true;
}

async function saveShift() {
  if (!form.name || !form.code || !form.shift_start || !form.shift_end) {
    alert('Please fill in required fields.');
    return;
  }

  if (isEditing.value) {
    await store.updateShift(editingId.value, { ...form });
  } else {
    await store.createShift({ ...form });
  }
  showModal.value = false;
}

async function deleteShift(s) {
  if (confirm(`Delete shift "${s.name}"?`)) {
    await store.deleteShift(s.id);
  }
}
</script>
