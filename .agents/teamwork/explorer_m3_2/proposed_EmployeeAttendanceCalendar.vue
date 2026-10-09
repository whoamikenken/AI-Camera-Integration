<template>
    <div
        v-if="isOpen"
        ref="modalRef"
        role="dialog"
        aria-modal="true"
        aria-labelledby="calendar-modal-title"
        aria-describedby="calendar-modal-desc"
        tabindex="-1"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm"
        @click.self="close"
        @keydown.escape="close"
    >
        <div class="bg-white border border-slate-200 rounded-2xl max-w-3xl w-full p-6 shadow-2xl space-y-5 animate-in fade-in zoom-in-95 duration-150">
            <div class="flex items-center justify-between border-b border-slate-100 pb-4">
                <div>
                    <h3 id="calendar-modal-title" class="text-base font-bold text-slate-900 flex items-center gap-2">
                        <span aria-hidden="true">📅</span> Employee Attendance Calendar
                    </h3>
                    <p id="calendar-modal-desc" class="text-xs text-slate-500 mt-0.5">
                        {{ employee?.first_name }} {{ employee?.last_name || '' }} ({{ employee?.employee_code }}) — {{ currentMonthName }} {{ currentYear }}
                    </p>
                </div>
                <button
                    type="button"
                    @click="close"
                    aria-label="Close dialog"
                    class="text-slate-400 hover:text-slate-700 font-bold p-1 cursor-pointer rounded focus:outline-none focus:ring-2 focus:ring-indigo-500"
                >
                    <span aria-hidden="true">✕</span>
                </button>
            </div>

            <!-- Month Navigator & Summary Stats (CAL-02) -->
            <div class="flex items-center justify-between bg-slate-50 border border-slate-200 p-3 rounded-xl">
                <button
                    type="button"
                    @click="prevMonth"
                    :disabled="loading"
                    aria-label="Previous month"
                    class="px-3 py-1 bg-white border border-slate-200 hover:bg-slate-100 disabled:opacity-50 disabled:cursor-not-allowed text-xs font-semibold text-slate-700 rounded-lg cursor-pointer transition-colors shadow-2xs focus:outline-none focus:ring-2 focus:ring-indigo-500"
                >
                    <span aria-hidden="true">←</span> Prev
                </button>
                <div
                    class="text-sm font-bold text-slate-900"
                    aria-live="polite"
                    aria-atomic="true"
                >
                    {{ currentMonthName }} {{ currentYear }}
                </div>
                <button
                    type="button"
                    @click="nextMonth"
                    :disabled="loading"
                    aria-label="Next month"
                    class="px-3 py-1 bg-white border border-slate-200 hover:bg-slate-100 disabled:opacity-50 disabled:cursor-not-allowed text-xs font-semibold text-slate-700 rounded-lg cursor-pointer transition-colors shadow-2xs focus:outline-none focus:ring-2 focus:ring-indigo-500"
                >
                    Next <span aria-hidden="true">→</span>
                </button>
            </div>

            <div class="grid grid-cols-4 gap-3 text-center text-xs">
                <div class="bg-emerald-50 border border-emerald-200 p-2.5 rounded-xl text-emerald-800">
                    <div v-if="loading" class="h-6 w-8 bg-emerald-200/60 rounded mx-auto my-0.5 animate-pulse motion-reduce:animate-none"></div>
                    <div v-else class="text-xl font-bold font-mono">{{ stats.present }}</div>
                    <div class="text-[11px] font-medium text-emerald-700">Present Days</div>
                </div>
                <div class="bg-rose-50 border border-rose-200 p-2.5 rounded-xl text-rose-800">
                    <div v-if="loading" class="h-6 w-8 bg-rose-200/60 rounded mx-auto my-0.5 animate-pulse motion-reduce:animate-none"></div>
                    <div v-else class="text-xl font-bold font-mono">{{ stats.absent }}</div>
                    <div class="text-[11px] font-medium text-rose-700">Absent Days</div>
                </div>
                <div class="bg-amber-50 border border-amber-200 p-2.5 rounded-xl text-amber-800">
                    <div v-if="loading" class="h-6 w-8 bg-amber-200/60 rounded mx-auto my-0.5 animate-pulse motion-reduce:animate-none"></div>
                    <div v-else class="text-xl font-bold font-mono">{{ stats.late }}</div>
                    <div class="text-[11px] font-medium text-amber-700">Late Arrivals</div>
                </div>
                <div class="bg-indigo-50 border border-indigo-200 p-2.5 rounded-xl text-indigo-800">
                    <div v-if="loading" class="h-6 w-8 bg-indigo-200/60 rounded mx-auto my-0.5 animate-pulse motion-reduce:animate-none"></div>
                    <div v-else class="text-xl font-bold font-mono">{{ stats.leave }}</div>
                    <div class="text-[11px] font-medium text-indigo-700">Leave Days</div>
                </div>
            </div>

            <!-- Calendar Grid (CAL-03) -->
            <div
                role="grid"
                :aria-label="`Attendance calendar for ${currentMonthName} ${currentYear}`"
                :aria-busy="loading"
                class="space-y-1"
            >
                <!-- Day Headers Row -->
                <div role="row" class="grid grid-cols-7 gap-1 text-center text-xs font-semibold text-slate-500 pb-1 uppercase tracking-wider">
                    <div role="columnheader" aria-label="Sunday">Sun</div>
                    <div role="columnheader" aria-label="Monday">Mon</div>
                    <div role="columnheader" aria-label="Tuesday">Tue</div>
                    <div role="columnheader" aria-label="Wednesday">Wed</div>
                    <div role="columnheader" aria-label="Thursday">Thu</div>
                    <div role="columnheader" aria-label="Friday">Fri</div>
                    <div role="columnheader" aria-label="Saturday">Sat</div>
                </div>

                <!-- Skeleton Day Cells Grid during Loading (CAL-03) -->
                <div v-if="loading" role="status" aria-label="Loading calendar days" class="space-y-1">
                    <span class="sr-only">Loading attendance records...</span>
                    <div v-for="w in 5" :key="`skel-week-${w}`" class="grid grid-cols-7 gap-1">
                        <div
                            v-for="d in 7"
                            :key="`skel-cell-${w}-${d}`"
                            class="h-16 p-1.5 rounded-lg border border-slate-200/70 bg-slate-50/80 flex flex-col justify-between animate-pulse motion-reduce:animate-none"
                        >
                            <div class="flex justify-between items-center">
                                <div class="h-3 w-4 bg-slate-200 rounded"></div>
                                <div v-if="(w + d) % 2 === 0" class="h-2 w-10 bg-slate-200/70 rounded"></div>
                            </div>
                            <div v-if="(w + d) % 3 === 0" class="h-2 w-12 bg-slate-200/60 rounded"></div>
                        </div>
                    </div>
                </div>

                <!-- Active Calendar Weeks & Day Cells (CAL-03) -->
                <div v-else role="rowgroup" class="space-y-1">
                    <div
                        v-for="(week, wIdx) in calendarWeeks"
                        :key="`week-${wIdx}`"
                        role="row"
                        class="grid grid-cols-7 gap-1"
                    >
                        <div
                            v-for="(day, dIdx) in week"
                            :key="`day-${wIdx}-${dIdx}`"
                            role="gridcell"
                            :tabindex="day.isCurrentMonth ? 0 : -1"
                            :aria-label="getDayAriaLabel(day)"
                            :class="[
                                'h-16 p-1.5 rounded-lg border text-xs flex flex-col justify-between transition focus:outline-none focus:ring-2 focus:ring-indigo-500',
                                day.isCurrentMonth ? getStatusClass(day.status) : 'bg-slate-50/50 border-slate-100 text-slate-300'
                            ]"
                        >
                            <div class="flex justify-between items-center font-bold">
                                <span>{{ day.dayNum }}</span>
                                <span
                                    v-if="day.status && day.isCurrentMonth"
                                    class="text-[9px] uppercase tracking-tighter font-mono"
                                    aria-hidden="true"
                                >
                                    {{ formatStatusBadge(day.status) }}
                                </span>
                            </div>
                            <div
                                v-if="day.firstIn && day.isCurrentMonth"
                                class="text-[10px] text-slate-600 font-mono truncate font-medium"
                                aria-hidden="true"
                            >
                                In: {{ day.firstIn }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex justify-end pt-3 border-t border-slate-100">
                <button
                    type="button"
                    @click="close"
                    class="px-5 py-2 text-xs font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 border border-slate-200 rounded-xl transition-colors cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
                >
                    Close
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue';
import apiClient from '../../api/client';

const props = defineProps({
    isOpen: Boolean,
    employee: Object,
});
const emit = defineEmits(['close']);

const modalRef = ref(null);
const currentDate = ref(new Date());
const monthRecords = ref([]);
const loading = ref(false);

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
    if (loading.value) return;
    const d = new Date(currentDate.value);
    d.setMonth(d.getMonth() - 1);
    currentDate.value = d;
    fetchMonthData();
};

const nextMonth = () => {
    if (loading.value) return;
    const d = new Date(currentDate.value);
    d.setMonth(d.getMonth() + 1);
    currentDate.value = d;
    fetchMonthData();
};

const fetchMonthData = async () => {
    if (!props.employee?.id) return;
    loading.value = true;
    try {
        const year = currentYear.value;
        const month = currentMonth.value;
        const daysInMonth = new Date(year, month, 0).getDate();
        const fromDate = `${year}-${String(month).padStart(2, '0')}-01`;
        const toDate = `${year}-${String(month).padStart(2, '0')}-${String(daysInMonth).padStart(2, '0')}`;

        const res = await apiClient.get('/attendance/records', {
            params: {
                employee_id: props.employee.id,
                month: month,
                year: year,
                from_date: fromDate,
                to_date: toDate,
                per_page: 50,
            }
        });
        monthRecords.value = res.data.data || res.data || [];
    } catch (e) {
        monthRecords.value = [];
    } finally {
        loading.value = false;
    }
};

const handleKeyDown = (e) => {
    if (e.key === 'Escape' && props.isOpen) {
        close();
    }
};

watch(() => props.isOpen, async (val) => {
    if (val) {
        window.addEventListener('keydown', handleKeyDown);
        if (props.employee) {
            fetchMonthData();
        }
        await nextTick();
        modalRef.value?.focus();
    } else {
        window.removeEventListener('keydown', handleKeyDown);
    }
});

watch(() => props.employee?.id, (newId) => {
    if (props.isOpen && newId) {
        fetchMonthData();
    }
});

onUnmounted(() => {
    window.removeEventListener('keydown', handleKeyDown);
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
        const dateObj = new Date(year, month, d);
        const formattedDate = dateObj.toLocaleDateString('default', {
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric',
        });

        days.push({
            isCurrentMonth: true,
            dayNum: d,
            dateStr: dStr,
            formattedDate,
            status: record?.status || '',
            firstIn: record?.first_clock_in ? record.first_clock_in.substring(11, 16) : null,
            lastOut: record?.last_clock_out ? record.last_clock_out.substring(11, 16) : null,
            totalWorkHours: record?.total_work_hours ? Number(record.total_work_hours).toFixed(1) : null,
            remarks: record?.remarks || null,
        });
    }

    // Trailing empty days to complete the final 7-day row
    const remainder = days.length % 7;
    if (remainder > 0) {
        for (let i = 0; i < 7 - remainder; i++) {
            days.push({ isCurrentMonth: false, dayNum: '' });
        }
    }

    return days;
});

const calendarWeeks = computed(() => {
    const days = calendarDays.value;
    const weeks = [];
    for (let i = 0; i < days.length; i += 7) {
        weeks.push(days.slice(i, i + 7));
    }
    return weeks;
});

const formatStatus = (status) => {
    switch (status) {
        case 'present': return 'Present';
        case 'late': return 'Late arrival';
        case 'late_and_early_out': return 'Late arrival and early departure';
        case 'early_out': return 'Early departure';
        case 'half_day': return 'Half day';
        case 'absent': return 'Absent';
        case 'on_leave': return 'On leave';
        case 'holiday': return 'Holiday';
        default: return status || 'Unknown';
    }
};

const formatStatusBadge = (status) => {
    switch (status) {
        case 'present': return 'PRESENT';
        case 'late': return 'LATE';
        case 'late_and_early_out': return 'LT+EO';
        case 'early_out': return 'EARLY';
        case 'half_day': return 'HALF';
        case 'absent': return 'ABSENT';
        case 'on_leave': return 'LEAVE';
        case 'holiday': return 'HOLIDAY';
        default: return status?.toUpperCase() || '';
    }
};

const getDayAriaLabel = (day) => {
    if (!day.isCurrentMonth) {
        return 'Empty';
    }

    const parts = [day.formattedDate];

    if (day.status) {
        parts.push(`Status: ${formatStatus(day.status)}`);
    } else {
        parts.push('No attendance recorded');
    }

    if (day.firstIn) {
        parts.push(`Clock in: ${day.firstIn}`);
    }

    if (day.lastOut) {
        parts.push(`Clock out: ${day.lastOut}`);
    }

    if (day.totalWorkHours && day.totalWorkHours > 0) {
        parts.push(`Total hours: ${day.totalWorkHours}h`);
    }

    if (day.remarks) {
        parts.push(`Notes: ${day.remarks}`);
    }

    return parts.join(', ');
};

const getStatusClass = (status) => {
    switch (status) {
        case 'present': return 'bg-emerald-50 border-emerald-200 text-emerald-800';
        case 'late': case 'late_and_early_out': return 'bg-amber-50 border-amber-200 text-amber-800';
        case 'early_out': return 'bg-amber-50 border-amber-200 text-amber-800';
        case 'half_day': return 'bg-sky-50 border-sky-200 text-sky-800';
        case 'absent': return 'bg-rose-50 border-rose-200 text-rose-800';
        case 'on_leave': return 'bg-indigo-50 border-indigo-200 text-indigo-800';
        case 'holiday': return 'bg-purple-50 border-purple-200 text-purple-800';
        default: return 'bg-slate-50 border-slate-200 text-slate-500';
    }
};

const close = () => emit('close');
</script>
