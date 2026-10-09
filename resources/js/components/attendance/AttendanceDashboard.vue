<template>
    <div class="space-y-6">
        <!-- KPI Metrics Grid -->
        <div v-if="attendanceStore.loading" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4" aria-hidden="true">
            <div v-for="i in 6" :key="`skel-kpi-${i}`" class="bg-white border border-slate-200/80 p-4 rounded-2xl shadow-xs animate-pulse motion-reduce:animate-none space-y-2">
                <div class="h-3 bg-slate-200 rounded w-20"></div>
                <div class="h-8 bg-slate-200 rounded w-16"></div>
                <div class="h-2.5 bg-slate-100 rounded w-24"></div>
            </div>
        </div>
        <div v-else class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
            <div class="bg-white border border-slate-200/80 p-4 rounded-2xl shadow-xs">
                <div class="text-[11px] text-slate-500 uppercase font-bold tracking-wider">Total Scheduled</div>
                <div class="text-2xl font-bold text-slate-900 mt-1 font-mono">{{ attendanceStore.stats.total_employees }}</div>
                <div class="text-[10px] text-slate-400 mt-1">Total Headcount</div>
            </div>
            <div class="bg-white border border-slate-200/80 p-4 rounded-2xl shadow-xs">
                <div class="text-[11px] text-emerald-700 uppercase font-bold tracking-wider">Present Today</div>
                <div class="text-2xl font-bold text-emerald-600 mt-1 font-mono">{{ attendanceStore.stats.present }}</div>
                <div class="text-[10px] text-emerald-600 font-medium mt-1">{{ attendanceStore.stats.attendance_rate }}% Rate</div>
            </div>
            <div class="bg-white border border-slate-200/80 p-4 rounded-2xl shadow-xs">
                <div class="text-[11px] text-rose-700 uppercase font-bold tracking-wider">Absent</div>
                <div class="text-2xl font-bold text-rose-600 mt-1 font-mono">{{ attendanceStore.stats.absent }}</div>
                <div class="text-[10px] text-rose-500 font-medium mt-1">Unexcused / Missing</div>
            </div>
            <div class="bg-white border border-slate-200/80 p-4 rounded-2xl shadow-xs">
                <div class="text-[11px] text-amber-700 uppercase font-bold tracking-wider">Late Arrivals</div>
                <div class="text-2xl font-bold text-amber-600 mt-1 font-mono">{{ attendanceStore.stats.late }}</div>
                <div class="text-[10px] text-amber-600 font-medium mt-1">Past Grace Period</div>
            </div>
            <div class="bg-white border border-slate-200/80 p-4 rounded-2xl shadow-xs">
                <div class="text-[11px] text-indigo-700 uppercase font-bold tracking-wider">On Leave</div>
                <div class="text-2xl font-bold text-indigo-600 mt-1 font-mono">{{ attendanceStore.stats.on_leave }}</div>
                <div class="text-[10px] text-indigo-600 font-medium mt-1">Approved Leaves</div>
            </div>
            <div class="bg-white border border-slate-200/80 p-4 rounded-2xl shadow-xs">
                <div class="text-[11px] text-sky-700 uppercase font-bold tracking-wider">Early Out</div>
                <div class="text-2xl font-bold text-sky-600 mt-1 font-mono">{{ attendanceStore.stats.early_out }}</div>
                <div class="text-[10px] text-sky-600 font-medium mt-1">Left Before End</div>
            </div>
        </div>

        <!-- Live Real-Time Ticker & Quick Who's In Panel -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Left: Who's Currently In / Recent Arrivals -->
            <div class="lg:col-span-2 bg-white border border-slate-200/80 rounded-2xl p-5 space-y-4 shadow-xs">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                    <div class="flex items-center space-x-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse motion-reduce:animate-none"></span>
                        <h3 class="font-bold text-slate-900 text-base">Live Attendance Clock-In Stream</h3>
                    </div>
                    <span class="text-xs text-slate-400 font-mono">Channel: attendance</span>
                </div>

                <div v-if="attendanceStore.recentLivePunches.length === 0" class="py-12 text-center text-slate-500 text-xs">
                    Waiting for camera facial recognition punch events...
                </div>
                <div 
                    v-else 
                    role="log"
                    aria-live="polite" 
                    aria-relevant="additions text" 
                    class="space-y-2 max-h-80 overflow-y-auto pr-1"
                >
                    <div v-for="punch in attendanceStore.recentLivePunches" :key="punch.id"
                        class="flex items-center justify-between p-3.5 bg-slate-50 border border-slate-200/80 rounded-xl hover:bg-slate-100/70 transition-colors">
                        <div class="flex items-center space-x-3">
                            <div :class="punch.direction === 'in' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-indigo-50 text-indigo-700 border-indigo-200'"
                                class="w-8 h-8 rounded-lg border flex items-center justify-center font-bold text-xs uppercase shadow-2xs">
                                {{ punch.direction }}
                            </div>
                            <div>
                                <div class="text-sm font-semibold text-slate-900">{{ punch.employee_name }}</div>
                                <div class="text-xs text-slate-500">{{ punch.employee_code }} • {{ punch.department_name || 'General' }}</div>
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="text-xs font-mono font-bold text-emerald-600">{{ formatTime(punch.punch_time) }}</div>
                            <div class="text-[10px] text-slate-400 font-mono">{{ punch.device_id || punch.source }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right: Daily Roster Summary / Quick Actions -->
            <div class="bg-white border border-slate-200/80 rounded-2xl p-5 space-y-4 flex flex-col justify-between shadow-xs">
                <div class="space-y-4">
                    <h3 class="font-bold text-slate-900 text-base">Quick Attendance Actions</h3>
                    <p class="text-xs text-slate-500 leading-relaxed">
                        Biometric camera events automatically calculate clock-ins, late arrivals, and total work hours. Use the buttons below for manual interventions.
                    </p>

                    <div class="space-y-2.5 pt-2">
                        <button @click="showManualModal = true" class="w-full py-2.5 px-4 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-xl transition-colors flex items-center justify-center gap-2 shadow-xs cursor-pointer">
                            <span>✍️</span> Record Manual Clock Punch
                        </button>
                        <button @click="triggerFinalize" class="w-full py-2.5 px-4 bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 text-xs font-semibold rounded-xl transition-colors flex items-center justify-center gap-2 cursor-pointer">
                            <span>⚙️</span> Finalize Today's Attendance
                        </button>
                    </div>
                </div>

                <div class="p-4 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-600 space-y-1">
                    <div class="font-semibold text-slate-800">💡 Biometric Telemetry Tip</div>
                    <div class="text-slate-500 leading-relaxed">Cameras on <strong class="text-indigo-600">entry</strong> channels automatically register first clock-in. Overtime &amp; breaks are calculated against assigned shifts.</div>
                </div>
            </div>
        </div>

        <!-- Modals -->
        <ManualAttendanceEntry :isOpen="showManualModal" @close="showManualModal = false" @saved="attendanceStore.fetchDailyAttendance" />
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useAttendanceStore } from '../../stores/attendanceStore';
import ManualAttendanceEntry from './ManualAttendanceEntry.vue';
import notify from '../../utils/notify';

const attendanceStore = useAttendanceStore();
const showManualModal = ref(false);

onMounted(() => {
    if (!attendanceStore.dailyRoster.length && !attendanceStore.loading) {
        attendanceStore.fetchDailyAttendance();
    }
});

const triggerFinalize = async () => {
    const confirmed = await notify.confirm(
        'Finalize Today\'s Attendance',
        'Finalize today attendance records for all active employees? This will mark unexcused absences and calculate overtime.',
        'Yes, Finalize Today'
    );
    if (confirmed) {
        await attendanceStore.triggerDailyFinalizer();
    }
};

const formatTime = (ts) => {
    if (!ts) return '';
    try {
        const d = new Date(ts);
        return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    } catch {
        return ts;
    }
};
</script>
