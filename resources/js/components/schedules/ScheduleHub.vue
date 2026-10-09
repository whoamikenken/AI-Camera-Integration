<template>
  <div class="space-y-6">
    <!-- Hub Header & Segmented Pill Navigation -->
    <div class="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-xs flex flex-col sm:flex-row sm:items-center justify-between gap-4">
      <div>
        <h2 class="text-lg font-bold text-slate-900 tracking-tight flex items-center gap-2">
          <span>🕐</span> Work Schedules &amp; Rosters
        </h2>
        <p class="text-xs text-slate-500 mt-1">
          Configure shift policies, assign working hours to employees &amp; departments, and manage holiday calendars.
        </p>
      </div>

      <!-- Segmented Pill Tabs -->
      <div role="tablist" aria-label="Schedule navigation tabs" class="flex flex-wrap items-center bg-slate-100 p-1.5 rounded-xl border border-slate-200 gap-1">
        <button
          v-for="tab in tabs"
          :key="tab.id"
          type="button"
          role="tab"
          :id="'schedule-tab-' + tab.id"
          :aria-selected="activeTab === tab.id ? 'true' : 'false'"
          :aria-controls="'schedule-panel-' + tab.id"
          @click="activeTab = tab.id"
          class="px-4 py-2 text-xs font-bold rounded-lg transition-all whitespace-nowrap cursor-pointer flex items-center gap-2"
          :class="activeTab === tab.id ? 'bg-white text-indigo-600 shadow-xs' : 'text-slate-600 hover:text-slate-900'"
        >
          <span>{{ tab.icon }}</span>
          <span>{{ tab.label }}</span>
        </button>
      </div>
    </div>

    <!-- Active Sub-View -->
    <div>
      <div
        v-if="activeTab === 'shifts'"
        id="schedule-panel-shifts"
        role="tabpanel"
        aria-labelledby="schedule-tab-shifts"
        tabindex="0"
      >
        <ShiftManager />
      </div>
      <div
        v-else-if="activeTab === 'assignments'"
        id="schedule-panel-assignments"
        role="tabpanel"
        aria-labelledby="schedule-tab-assignments"
        tabindex="0"
      >
        <ShiftAssignment />
      </div>
      <div
        v-else-if="activeTab === 'holidays'"
        id="schedule-panel-holidays"
        role="tabpanel"
        aria-labelledby="schedule-tab-holidays"
        tabindex="0"
      >
        <HolidayCalendar />
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import ShiftManager from './ShiftManager.vue';
import ShiftAssignment from './ShiftAssignment.vue';
import HolidayCalendar from './HolidayCalendar.vue';

const activeTab = ref('shifts');

const tabs = [
  { id: 'shifts', label: 'Shift Definitions', icon: '⏱️' },
  { id: 'assignments', label: 'Roster Assignments', icon: '📋' },
  { id: 'holidays', label: 'Holiday Calendar', icon: '🗓️' },
];
</script>
