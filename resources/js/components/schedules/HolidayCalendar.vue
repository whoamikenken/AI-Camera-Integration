<template>
  <div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs">
      <div>
        <h3 class="text-base font-bold text-slate-900 tracking-tight">Holiday Calendar</h3>
        <p class="text-xs text-slate-500 mt-0.5">
          Manage official public non-working holidays, company observances, and optional floaters.
        </p>
      </div>

      <div class="flex items-center gap-2">
        <!-- View Toggle (Month Grid vs List Table) -->
        <div class="flex items-center bg-slate-100 p-1 rounded-xl border border-slate-200">
          <button
            type="button"
            @click="store.setCalendarView('month')"
            class="px-2.5 py-1 text-xs font-semibold rounded-lg transition-all cursor-pointer"
            :class="store.calendarViewMode === 'month' ? 'bg-white text-slate-900 shadow-xs' : 'text-slate-500 hover:text-slate-800'"
          >
            🗓️ Month
          </button>
          <button
            type="button"
            @click="store.setCalendarView('list')"
            class="px-2.5 py-1 text-xs font-semibold rounded-lg transition-all cursor-pointer"
            :class="store.calendarViewMode === 'list' ? 'bg-white text-slate-900 shadow-xs' : 'text-slate-500 hover:text-slate-800'"
          >
            📋 List
          </button>
        </div>

        <button
          type="button"
          @click="openCreateModal"
          class="px-4 py-2 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl shadow-xs transition-colors cursor-pointer inline-flex items-center gap-1.5"
        >
          <span>➕</span> Add Holiday
        </button>
      </div>
    </div>

    <!-- Calendar Month Navigation Bar -->
    <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-xs flex items-center justify-between">
      <div class="flex items-center gap-2">
        <button
          @click="store.navigateMonth(-1)"
          aria-label="Previous month"
          class="p-2 text-slate-600 hover:bg-slate-100 rounded-xl cursor-pointer transition-colors"
          title="Previous Month"
        >
          ◀
        </button>
        <span class="text-sm font-bold text-slate-900 font-mono">
          {{ monthNames[store.calendarMonth] }} {{ store.calendarYear }}
        </span>
        <button
          @click="store.navigateMonth(1)"
          aria-label="Next month"
          class="p-2 text-slate-600 hover:bg-slate-100 rounded-xl cursor-pointer transition-colors"
          title="Next Month"
        >
          ▶
        </button>
        <button
          @click="store.setToday"
          class="ml-2 px-2.5 py-1 text-[11px] font-semibold text-slate-600 hover:bg-slate-100 rounded-lg cursor-pointer border border-slate-200"
        >
          Today
        </button>
      </div>

      <!-- Legend -->
      <div class="hidden sm:flex items-center gap-3 text-[11px]">
        <span class="flex items-center gap-1 text-rose-700 font-medium">
          <span class="w-2.5 h-2.5 rounded-full bg-rose-500"></span> Public Holiday
        </span>
        <span class="flex items-center gap-1 text-indigo-700 font-medium">
          <span class="w-2.5 h-2.5 rounded-full bg-indigo-500"></span> Company Observance
        </span>
        <span class="flex items-center gap-1 text-amber-700 font-medium">
          <span class="w-2.5 h-2.5 rounded-full bg-amber-500"></span> Optional / Floater
        </span>
      </div>
    </div>

    <!-- View Mode A: Native 7-Column Month Grid -->
    <div v-if="store.calendarViewMode === 'month'" class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
      <!-- Weekday Headers -->
      <div class="grid grid-cols-7 border-b border-slate-200 bg-slate-50/80 text-center py-2.5 text-xs font-bold text-slate-600">
        <div>Sun</div>
        <div>Mon</div>
        <div>Tue</div>
        <div>Wed</div>
        <div>Thu</div>
        <div>Fri</div>
        <div>Sat</div>
      </div>

      <!-- Days Grid -->
      <div class="grid grid-cols-7 divide-x divide-y divide-slate-100 min-h-[480px]">
        <div
          v-for="(day, idx) in calendarDays"
          :key="idx"
          class="p-2 min-h-[90px] flex flex-col justify-between transition-colors relative"
          :class="[
            !day.currentMonth ? 'bg-slate-50/40 text-slate-300' : 'bg-white text-slate-800',
            day.isWeekend && day.currentMonth ? 'bg-slate-50/20' : '',
            day.isToday ? 'ring-2 ring-indigo-500/50 ring-inset bg-indigo-50/10' : ''
          ]"
        >
          <div class="flex items-center justify-between">
            <span
              class="text-xs font-bold rounded-full w-6 h-6 flex items-center justify-center"
              :class="day.isToday ? 'bg-indigo-600 text-white' : ''"
            >
              {{ day.dayNum }}
            </span>
          </div>

          <!-- Holiday Chips on this date -->
          <div class="space-y-1 mt-1 flex-1 overflow-y-auto max-h-16">
            <button
              v-for="h in day.holidays"
              :key="h.id"
              type="button"
              @click="editHoliday(h)"
              :aria-label="`Edit holiday ${h.name}`"
              class="w-full text-left p-1 rounded-md text-[10px] font-bold border truncate cursor-pointer transition-transform hover:scale-[1.02]"
              :class="holidayChipClass(h.type)"
              :title="h.name + (h.is_recurring ? ' (Annual Recurring)' : '')"
            >
              {{ h.name }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- View Mode B: Holiday List View Table -->
    <div v-else class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-left text-xs">
          <thead class="bg-slate-50/80 border-b border-slate-200/80 text-[11px] font-semibold text-slate-500 uppercase tracking-wider">
            <tr>
              <th class="px-5 py-3.5">Holiday Name</th>
              <th class="px-4 py-3.5">Date</th>
              <th class="px-4 py-3.5">Category</th>
              <th class="px-4 py-3.5">Recurrence</th>
              <th class="px-5 py-3.5 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr
              v-for="h in store.holidays"
              :key="h.id"
              class="hover:bg-slate-50/60 transition-colors"
            >
              <td class="px-5 py-3.5 font-bold text-slate-900">{{ h.name }}</td>
              <td class="px-4 py-3.5 font-mono text-slate-700">{{ h.date }}</td>
              <td class="px-4 py-3.5">
                <span
                  class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider border"
                  :class="holidayChipClass(h.type)"
                >
                  {{ h.type }}
                </span>
              </td>
              <td class="px-4 py-3.5 text-slate-500">
                {{ h.is_recurring ? '🔄 Annual Recurring' : 'Single Event' }}
              </td>
              <td class="px-5 py-3.5 text-right space-x-1">
                <button
                  @click="editHoliday(h)"
                  class="p-1.5 text-indigo-600 hover:bg-indigo-50 rounded-lg cursor-pointer"
                >
                  ✏️
                </button>
                <button
                  @click="deleteHoliday(h)"
                  class="p-1.5 text-rose-600 hover:bg-rose-50 rounded-lg cursor-pointer"
                >
                  🗑️
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Create / Edit Holiday Modal -->
    <div v-if="showModal" class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
      <div class="bg-white rounded-2xl shadow-xl border border-slate-200 max-w-md w-full p-6 space-y-4">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
          <h3 class="text-sm font-bold text-slate-900">
            {{ isEditing ? 'Edit Holiday' : 'Add New Holiday' }}
          </h3>
          <button @click="showModal = false" class="text-slate-400 hover:text-slate-600 cursor-pointer" aria-label="Close dialog">✕</button>
        </div>

        <div class="space-y-3 text-xs">
          <div>
            <label class="block font-semibold text-slate-700 mb-1">Holiday Name <span class="text-rose-500">*</span></label>
            <input
              v-model="form.name"
              type="text"
              placeholder="e.g. Independence Day"
              class="w-full px-3 py-2 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
              required
            />
          </div>

          <div>
            <label class="block font-semibold text-slate-700 mb-1">Holiday Date <span class="text-rose-500">*</span></label>
            <input
              v-model="form.date"
              type="date"
              class="w-full px-3 py-2 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
              required
            />
          </div>

          <div>
            <label class="block font-semibold text-slate-700 mb-1">Holiday Category</label>
            <select
              v-model="form.type"
              class="w-full px-3 py-2 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 bg-white"
            >
              <option value="public">Public Holiday (Official)</option>
              <option value="company">Company Holiday</option>
              <option value="optional">Optional / Floater</option>
            </select>
          </div>

          <div class="pt-1">
            <label class="inline-flex items-center gap-2 cursor-pointer">
              <input v-model="form.is_recurring" type="checkbox" class="rounded text-indigo-600" />
              <span class="font-semibold text-slate-700">Repeats Annually on this Month &amp; Day</span>
            </label>
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
            type="button"
            @click="saveHoliday"
            class="px-4 py-1.5 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl cursor-pointer shadow-xs"
          >
            {{ isEditing ? 'Save Changes' : 'Create Holiday' }}
          </button>
        </div>
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

const monthNames = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December'
];

const form = reactive({
  name: '',
  date: new Date().toISOString().slice(0, 10),
  type: 'public',
  is_recurring: false,
});

onMounted(() => {
  store.fetchHolidays();
});

// Compute 35-42 calendar days for current month view
const calendarDays = computed(() => {
  const year = store.calendarYear;
  const month = store.calendarMonth;

  const firstDayOfMonth = new Date(year, month, 1).getDay(); // 0 = Sun
  const daysInMonth = new Date(year, month + 1, 0).getDate();
  const daysInPrevMonth = new Date(year, month, 0).getDate();

  const days = [];
  const todayStr = new Date().toISOString().slice(0, 10);

  // 1. Previous month trailing days
  for (let i = firstDayOfMonth - 1; i >= 0; i--) {
    const d = daysInPrevMonth - i;
    const dateStr = `${month === 0 ? year - 1 : year}-${String(month === 0 ? 12 : month).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
    days.push({
      dayNum: d,
      currentMonth: false,
      isWeekend: false,
      isToday: dateStr === todayStr,
      date: dateStr,
      holidays: store.holidaysMap[dateStr] || [],
    });
  }

  // 2. Current month days
  for (let d = 1; d <= daysInMonth; d++) {
    const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
    const dayOfWeek = new Date(year, month, d).getDay();
    const isWeekend = dayOfWeek === 0 || dayOfWeek === 6;

    // Check exact date or annual recurrence
    const monthDay = `${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
    const matchedHolidays = store.holidays.filter(h => {
      if (h.date === dateStr) return true;
      if (h.is_recurring && h.date && h.date.slice(5) === monthDay) return true;
      return false;
    });

    days.push({
      dayNum: d,
      currentMonth: true,
      isWeekend,
      isToday: dateStr === todayStr,
      date: dateStr,
      holidays: matchedHolidays,
    });
  }

  // 3. Next month leading days to complete grid (multiples of 7)
  const remaining = (7 - (days.length % 7)) % 7;
  for (let d = 1; d <= remaining; d++) {
    const dateStr = `${month === 11 ? year + 1 : year}-${String(month === 11 ? 1 : month + 2).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
    days.push({
      dayNum: d,
      currentMonth: false,
      isWeekend: false,
      isToday: dateStr === todayStr,
      date: dateStr,
      holidays: store.holidaysMap[dateStr] || [],
    });
  }

  return days;
});

function holidayChipClass(type) {
  switch (type) {
    case 'public':
      return 'bg-rose-100 text-rose-800 border-rose-200';
    case 'company':
      return 'bg-indigo-100 text-indigo-800 border-indigo-200';
    case 'optional':
      return 'bg-amber-100 text-amber-800 border-amber-200';
    default:
      return 'bg-slate-100 text-slate-800 border-slate-200';
  }
}

function openCreateModal() {
  editingId.value = null;
  form.name = '';
  form.date = new Date().toISOString().slice(0, 10);
  form.type = 'public';
  form.is_recurring = false;
  showModal.value = true;
}

function editHoliday(h) {
  editingId.value = h.id;
  form.name = h.name;
  form.date = h.date;
  form.type = h.type || 'public';
  form.is_recurring = !!h.is_recurring;
  showModal.value = true;
}

async function saveHoliday() {
  if (!form.name || !form.date) {
    alert('Please enter name and date.');
    return;
  }

  if (isEditing.value) {
    await store.updateHoliday(editingId.value, { ...form });
  } else {
    await store.createHoliday({ ...form });
  }
  showModal.value = false;
}

async function deleteHoliday(h) {
  if (confirm(`Remove holiday "${h.name}"?`)) {
    await store.deleteHoliday(h.id);
  }
}
</script>
