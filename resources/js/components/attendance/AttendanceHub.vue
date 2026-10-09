<template>
    <div class="space-y-6">
        <!-- Sub Header & Navigation Tabs -->
        <div class="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-xs flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h1 class="text-lg font-bold text-slate-900 tracking-tight flex items-center gap-2">
                    <span>📊</span> Biometric Attendance Engine
                </h1>
                <p class="text-xs text-slate-500 mt-1">Real-time biometric clock logs, shift reconciliation, and daily roster.</p>
            </div>

            <!-- Segmented Pill Tabs -->
            <div role="tablist" aria-label="Attendance navigation tabs" class="flex flex-wrap items-center bg-slate-100 p-1.5 rounded-xl border border-slate-200 gap-1">
                <button
                    type="button"
                    role="tab"
                    id="attendance-tab-dashboard"
                    :aria-selected="activeSubTab === 'dashboard' ? 'true' : 'false'"
                    aria-controls="attendance-panel-dashboard"
                    @click="activeSubTab = 'dashboard'"
                    :class="activeSubTab === 'dashboard' ? 'bg-white text-indigo-600 shadow-xs font-bold' : 'text-slate-600 hover:text-slate-900'"
                    class="px-4 py-2 rounded-lg transition-all text-xs cursor-pointer flex items-center gap-1.5 font-medium"
                >
                    <span>📈</span> Overview Dashboard
                </button>
                <button
                    type="button"
                    role="tab"
                    id="attendance-tab-roster"
                    :aria-selected="activeSubTab === 'roster' ? 'true' : 'false'"
                    aria-controls="attendance-panel-roster"
                    @click="activeSubTab = 'roster'"
                    :class="activeSubTab === 'roster' ? 'bg-white text-indigo-600 shadow-xs font-bold' : 'text-slate-600 hover:text-slate-900'"
                    class="px-4 py-2 rounded-lg transition-all text-xs cursor-pointer flex items-center gap-1.5 font-medium"
                >
                    <span>📋</span> Daily Roster &amp; Records
                </button>
            </div>
        </div>

        <!-- Sub Tabs Content -->
        <div
            v-if="activeSubTab === 'dashboard'"
            id="attendance-panel-dashboard"
            role="tabpanel"
            aria-labelledby="attendance-tab-dashboard"
            tabindex="0"
        >
            <AttendanceDashboard />
        </div>
        <div
            v-else-if="activeSubTab === 'roster'"
            id="attendance-panel-roster"
            role="tabpanel"
            aria-labelledby="attendance-tab-roster"
            tabindex="0"
        >
            <DailyAttendanceRoster />
        </div>
    </div>
</template>

<script setup>
import { ref } from 'vue';
import AttendanceDashboard from './AttendanceDashboard.vue';
import DailyAttendanceRoster from './DailyAttendanceRoster.vue';

const activeSubTab = ref('dashboard');
</script>
