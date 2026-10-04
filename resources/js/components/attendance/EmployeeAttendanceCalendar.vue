<template>
    <div v-if="isOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm" @click.self="close">
        <div class="bg-white border border-slate-200 rounded-2xl max-w-3xl w-full p-6 shadow-2xl space-y-5">
            <div class="flex items-center justify-between border-b border-slate-100 pb-4">
                <div>
                    <h3 class="text-base font-bold text-slate-900 flex items-center gap-2">
                        <span>📅</span> Employee Attendance Calendar
                    </h3>
                    <p class="text-xs text-slate-500 mt-0.5">
                        {{ employee?.first_name }} {{ employee?.last_name || '' }} ({{ employee?.employee_code }}) — {{ currentMonthName }} {{ currentYear }}
                    </p>
                </div>
                <button @click="close" class="text-slate-400 hover:text-slate-700 font-bold p-1 cursor-pointer">✕</button>
            </div>

            <!-- Month Navigator & Summary Stats -->
            <div class="flex items-center justify-between bg-slate-50 border border-slate-200 p-3 rounded-xl">
                <button @click="prevMonth" class="px-3 py-1 bg-white border border-slate-200 hover:bg-slate-100 text-xs font-semibold text-slate-700 rounded-lg cursor-pointer transition-colors shadow-2xs">← Prev</button>
                <div class="text-sm font-bold text-slate-900">{{ currentMonthName }} {{ currentYear }}</div>
                <button @click="nextMonth" class="px-3 py-1 bg-white border border-slate-200 hover:bg-slate-100 text-xs font-semibold text-slate-700 rounded-lg cursor-pointer transition-colors shadow-2xs">Next →</button>
            </div>

            <div class="grid grid-cols-4 gap-3 text-center text-xs">
                <div class="bg-emerald-50 border border-emerald-200 p-2.5 rounded-xl text-emerald-800">
                    <div class="text-xl font-bold font-mono">{{ stats.present }}</div>
                    <div class="text-[11px] font-medium text-emerald-700">Present Days</div>
                </div>
                <div class="bg-rose-50 border border-rose-200 p-2.5 rounded-xl text-rose-800">
                    <div class="text-xl font-bold font-mono">{{ stats.absent }}</div>
                    <div class="text-[11px] font-medium text-rose-700">Absent Days</div>
                </div>
                <div class="bg-amber-50 border border-amber-200 p-2.5 rounded-xl text-amber-800">
                    <div class="text-xl font-bold font-mono">{{ stats.late }}</div>
                    <div class="text-[11px] font-medium text-amber-700">Late Arrivals</div>
                </div>
                <div class="bg-indigo-50 border border-indigo-200 p-2.5 rounded-xl text-indigo-800">
                    <div class="text-xl font-bold font-mono">{{ stats.leave }}</div>
                    <div class="text-[11px] font-medium text-indigo-700">Leave Days</div>
                </div>
            </div>

            <!-- Calendar Grid -->
            <div class="space-y-1">
                <div class="grid grid-cols-7 gap-1 text-center text-xs font-semibold text-slate-500 pb-1 uppercase tracking-wider">
                    <span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span>
                </div>
                <div class="grid grid-cols-7 gap-1">
                    <div v-for="(day, idx) in calendarDays" :key="idx"
                        :class="[
                            'h-16 p-1.5 rounded-lg border text-xs flex flex-col justify-between transition',
                            day.isCurrentMonth ? getStatusClass(day.status) : 'bg-slate-50/50 border-slate-100 text-slate-300'
                        ]">
                        <div class="flex justify-between items-center font-bold">
                            <span>{{ day.dayNum }}</span>
                            <span v-if="day.status && day.isCurrentMonth" class="text-[9px] uppercase tracking-tighter font-mono">{{ day.status }}</span>
                        </div>
                        <div v-if="day.firstIn && day.isCurrentMonth" class="text-[10px] text-slate-600 font-mono truncate font-medium">
                            In: {{ day.firstIn }}
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex justify-end pt-3 border-t border-slate-100">
                <button @click="close" class="px-5 py-2 text-xs font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 border border-slate-200 rounded-xl transition-colors cursor-pointer">Close</button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import apiClient from '../../api/client';

const props = defineProps({
    isOpen: Boolean,
    employee: Object,
});
const emit = defineEmits(['close']);

const currentDate = ref(new Date());
const monthRecords = ref([]);

const currentYear = computed(() => currentDate.value.getFullYear());
const currentMonth = computed(() => currentDate.value.getMonth() + 1);
const currentMonthName = computed(() => currentDate.value.toLocaleString('default', { month: 'long' }));

const stats = computed(() => {
    return {
        present: monthRecords.value.filter(r => ['present', 'late', 'early_out', 'late_and_early_out', 'half_day'].includes(r.status)).length,
        absent: monthRecords.value.filter(r => r.status === 'absent').length,
        late: monthRecords.value.filter(r => r.is_late || ['late', 'late_and_early_out'].includes(r.status)).length,
        leave: monthRecords.value.filter(r => r.status === 'on_leave').length,
    };
});

const prevMonth = () => {
    const d = new Date(currentDate.value);
    d.setMonth(d.getMonth() - 1);
    currentDate.value = d;
    fetchMonthData();
};

const nextMonth = () => {
    const d = new Date(currentDate.value);
    d.setMonth(d.getMonth() + 1);
    currentDate.value = d;
    fetchMonthData();
};

const fetchMonthData = async () => {
    if (!props.employee?.id) return;
    try {
        const res = await apiClient.get('/attendance/records', {
            params: {
                employee_id: props.employee.id,
                month: currentMonth.value,
                year: currentYear.value,
            }
        });
        monthRecords.value = res.data.data || res.data || [];
    } catch (e) {
        monthRecords.value = [];
    }
};

watch(() => props.isOpen, (val) => {
    if (val && props.employee) {
        fetchMonthData();
    }
});

const calendarDays = computed(() => {
    const year = currentYear.value;
    const month = currentMonth.value - 1;
    const firstDayIndex = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const days = [];

    // Preceding empty/prev-month days
    for (let i = 0; i < firstDayIndex; i++) {
        days.push({ isCurrentMonth: false, dayNum: '' });
    }

    // Days in current month
    for (let d = 1; d <= daysInMonth; d++) {
        const dStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
        const record = monthRecords.value.find(r => r.date?.startsWith(dStr) || r.date === dStr);
        days.push({
            isCurrentMonth: true,
            dayNum: d,
            status: record?.status || '',
            firstIn: record?.first_clock_in ? record.first_clock_in.substring(11, 16) : null,
        });
    }

    return days;
});

const getStatusClass = (status) => {
    switch (status) {
        case 'present': return 'bg-emerald-50 border-emerald-200 text-emerald-800';
        case 'late': case 'late_and_early_out': return 'bg-amber-50 border-amber-200 text-amber-800';
        case 'absent': return 'bg-rose-50 border-rose-200 text-rose-800';
        case 'on_leave': return 'bg-indigo-50 border-indigo-200 text-indigo-800';
        case 'holiday': return 'bg-purple-50 border-purple-200 text-purple-800';
        default: return 'bg-slate-50 border-slate-200 text-slate-500';
    }
};

const close = () => emit('close');
</script>
