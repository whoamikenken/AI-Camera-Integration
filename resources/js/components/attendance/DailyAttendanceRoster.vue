<template>
    <div class="space-y-4">
        <!-- Controls & Filters Bar -->
        <div class="flex flex-wrap items-center justify-between gap-3 bg-white border border-slate-200/80 p-4 rounded-2xl shadow-xs">
            <div class="flex flex-wrap items-center gap-3">
                <input v-model="attendanceStore.selectedDate" @change="handleDateChange" type="date"
                    aria-label="Filter attendance by date"
                    class="bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 cursor-pointer shadow-xs" />

                <select v-model="attendanceStore.selectedDepartmentId" @change="attendanceStore.fetchDailyAttendance"
                    aria-label="Filter attendance by department"
                    class="bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 cursor-pointer shadow-xs">
                    <option value="">All Departments</option>
                    <option v-for="dept in employeeStore.departments" :key="dept.id" :value="dept.id">{{ dept.name }}</option>
                </select>

                <select v-model="attendanceStore.selectedStatus" @change="attendanceStore.fetchDailyAttendance"
                    aria-label="Filter attendance by status"
                    class="bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 cursor-pointer shadow-xs">
                    <option value="">All Statuses</option>
                    <option value="present">Present</option>
                    <option value="late">Late</option>
                    <option value="absent">Absent</option>
                    <option value="on_leave">On Leave</option>
                    <option value="early_out">Early Out</option>
                    <option value="half_day">Half Day</option>
                </select>

                <div class="relative">
                    <input v-model="attendanceStore.searchQuery" type="text" placeholder="Search employee or code..."
                        aria-label="Search employee by name or code"
                        class="bg-white border border-slate-200 rounded-lg pl-8 pr-3 py-1.5 text-xs text-slate-900 placeholder-slate-400 focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 w-56 shadow-xs" />
                    <span class="absolute left-2.5 top-2 text-slate-400 text-xs" aria-hidden="true">🔍</span>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <button type="button" @click="showManualModal = true" class="px-3.5 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-lg transition-colors flex items-center gap-1.5 shadow-xs cursor-pointer">
                    <span aria-hidden="true">✍️</span> Manual Punch
                </button>
                <button type="button" @click="handleFinalize" :disabled="attendanceStore.finalizing" class="px-3.5 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 text-xs font-semibold rounded-lg transition-colors flex items-center gap-1.5 cursor-pointer disabled:opacity-50">
                    <span :class="{'animate-spin': attendanceStore.finalizing}" class="motion-reduce:animate-none" aria-hidden="true">⚙️</span> Finalize Day
                </button>
                <button type="button" @click="attendanceStore.fetchDailyAttendance" :disabled="attendanceStore.loading" aria-label="Refresh daily attendance roster" class="p-2 bg-slate-100 hover:bg-slate-200 disabled:opacity-50 text-slate-600 rounded-lg border border-slate-200 text-xs cursor-pointer transition-colors" title="Refresh">
                    <span :class="{'animate-spin': attendanceStore.loading}" class="inline-block motion-reduce:animate-none" aria-hidden="true">🔄</span>
                </button>
            </div>
        </div>

        <!-- Attendance Roster Table -->
        <div class="bg-white border border-slate-200/80 rounded-2xl overflow-hidden shadow-xs">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-slate-700">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500 font-semibold border-b border-slate-200">
                        <tr>
                            <th scope="col" class="px-4 py-3">Employee</th>
                            <th scope="col" class="px-4 py-3">Department</th>
                            <th scope="col" class="px-4 py-3">Shift</th>
                            <th scope="col" class="px-4 py-3">First In</th>
                            <th scope="col" class="px-4 py-3">Last Out</th>
                            <th scope="col" class="px-4 py-3">Work Hours</th>
                            <th scope="col" class="px-4 py-3">Status</th>
                            <th scope="col" class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <!-- 5 Animated Skeleton Table Rows Matching Column Dimensions (ROST-04) -->
                        <template v-if="attendanceStore.loading">
                            <tr v-for="i in 5" :key="`skel-roster-${i}`" class="animate-pulse motion-reduce:animate-none">
                                <td class="px-4 py-3">
                                    <div class="flex items-center space-x-3">
                                        <div class="w-9 h-9 rounded-full bg-slate-200 shrink-0"></div>
                                        <div class="space-y-1.5">
                                            <div class="h-3.5 bg-slate-200 rounded w-28"></div>
                                            <div class="h-2.5 bg-slate-100 rounded w-16"></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="h-3.5 bg-slate-200 rounded w-20"></div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="h-3.5 bg-slate-200 rounded w-16"></div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="h-3.5 bg-slate-200 rounded w-14"></div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="h-3.5 bg-slate-200 rounded w-14"></div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="h-3.5 bg-slate-200 rounded w-16"></div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="h-5 bg-slate-200 rounded-full w-16"></div>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div class="inline-flex space-x-2 justify-end">
                                        <div class="h-6 bg-slate-200 rounded-lg w-16"></div>
                                        <div class="h-6 bg-slate-200 rounded-lg w-12"></div>
                                    </div>
                                </td>
                            </tr>
                        </template>
                        <tr v-else-if="attendanceStore.filteredRoster.length === 0" class="text-center">
                            <td colspan="8" class="py-12 text-slate-500 text-xs">No attendance records found for this date.</td>
                        </tr>
                        <tr v-for="item in attendanceStore.filteredRoster" :key="item.id || item.employee_id" class="hover:bg-slate-50/80 transition-colors">
                            <td class="px-4 py-3">
                                <div class="flex items-center space-x-3">
                                    <div class="w-9 h-9 rounded-full bg-slate-100 border border-slate-200 flex items-center justify-center overflow-hidden flex-shrink-0 shadow-2xs">
                                        <img v-if="item.employee?.personnel?.photo_path" :src="item.employee?.personnel?.photo_path" alt="" class="w-full h-full object-cover" />
                                        <span v-else class="text-xs font-bold text-slate-500">{{ (item.employee?.first_name || 'E')[0] }}</span>
                                    </div>
                                    <div>
                                        <div class="font-semibold text-slate-900">{{ item.employee?.first_name }} {{ item.employee?.last_name || '' }}</div>
                                        <div class="text-xs text-slate-400 font-mono">{{ item.employee?.employee_code }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-slate-600 text-xs">{{ item.employee?.department?.name || '—' }}</td>
                            <td class="px-4 py-3 text-slate-600 text-xs">{{ item.shift?.name || 'General' }}</td>
                            <td class="px-4 py-3 font-mono text-xs">
                                <span v-if="item.first_clock_in" class="text-emerald-600 font-bold">{{ formatTime(item.first_clock_in) }}</span>
                                <span v-else class="text-slate-400">—</span>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs">
                                <span v-if="item.last_clock_out" class="text-indigo-600 font-bold">{{ formatTime(item.last_clock_out) }}</span>
                                <span v-else class="text-slate-400">—</span>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs">
                                <span v-if="item.total_work_hours > 0" class="text-slate-800 font-semibold">{{ Number(item.total_work_hours).toFixed(1) }}h</span>
                                <span v-else class="text-slate-400">0.0h</span>
                                <span v-if="item.overtime_hours > 0" class="ml-1 text-[10px] text-amber-600 font-bold">(+{{ Number(item.overtime_hours).toFixed(1) }} OT)</span>
                            </td>
                            <td class="px-4 py-3">
                                <span :class="getStatusBadgeClass(item.status)" class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider">
                                    {{ formatStatus(item.status) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right space-x-2">
                                <button type="button" @click="openCalendar(item.employee)" :aria-label="`View calendar for ${item.employee?.first_name || 'employee'}`" title="View Calendar" class="px-2.5 py-1 bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 rounded-lg text-xs font-semibold transition-colors cursor-pointer">
                                    <span aria-hidden="true">📅</span> Calendar
                                </button>
                                <button type="button" @click="openOverrideModal(item)" :aria-label="`Override status for ${item.employee?.first_name || 'employee'}`" title="Override Status" class="px-2.5 py-1 bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 rounded-lg text-xs font-semibold transition-colors cursor-pointer">
                                    <span aria-hidden="true">✏️</span> Edit
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Modals -->
        <ManualAttendanceEntry :isOpen="showManualModal" @close="showManualModal = false" @saved="attendanceStore.fetchDailyAttendance" />
        <EmployeeAttendanceCalendar :isOpen="showCalendarModal" :employee="selectedEmployee" @close="showCalendarModal = false" />

        <!-- Status Override Modal (ROST-05) -->
        <div
            v-if="showOverrideModal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="override-modal-title"
            tabindex="-1"
            class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm"
            @click.self="showOverrideModal = false"
            @keydown.escape="showOverrideModal = false"
        >
            <div class="bg-white border border-slate-200 rounded-2xl max-w-md w-full p-6 shadow-2xl space-y-4 animate-in fade-in zoom-in-95 duration-150">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                    <div class="flex items-center space-x-2">
                        <span class="text-base" aria-hidden="true">✏️</span>
                        <h3 id="override-modal-title" class="text-base font-bold text-slate-900">Override Attendance Status</h3>
                    </div>
                    <button
                        type="button"
                        @click="showOverrideModal = false"
                        aria-label="Close dialog"
                        class="text-slate-400 hover:text-slate-700 font-bold p-1 cursor-pointer rounded focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    >✕</button>
                </div>

                <div v-if="currentRecord" class="bg-slate-50 border border-slate-200/80 p-3 rounded-xl text-xs space-y-1">
                    <div class="flex justify-between text-slate-600">
                        <span>Employee:</span>
                        <span class="font-semibold text-slate-900">{{ currentRecord.employee?.first_name }} {{ currentRecord.employee?.last_name || '' }} ({{ currentRecord.employee?.employee_code }})</span>
                    </div>
                    <div class="flex justify-between text-slate-600">
                        <span>Current Status:</span>
                        <span class="font-bold uppercase tracking-wider text-[10px]" :class="getStatusBadgeClass(currentRecord.status)">
                            {{ formatStatus(currentRecord.status) }}
                        </span>
                    </div>
                </div>

                <div class="space-y-3">
                    <div>
                        <label for="override_status" class="block text-xs font-semibold text-slate-700 mb-1">Status</label>
                        <select
                            id="override_status"
                            v-model="overrideForm.status"
                            class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs cursor-pointer"
                        >
                            <option value="present">Present</option>
                            <option value="late">Late</option>
                            <option value="early_out">Early Out</option>
                            <option value="half_day">Half Day</option>
                            <option value="on_leave">On Leave</option>
                            <option value="absent">Absent</option>
                        </select>
                    </div>
                    <div>
                        <label for="override_remarks" class="block text-xs font-semibold text-slate-700 mb-1">HR Remarks</label>
                        <textarea
                            id="override_remarks"
                            v-model="overrideForm.remarks"
                            rows="2"
                            class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 placeholder-slate-400 focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs"
                            placeholder="Reason for override..."
                        ></textarea>
                    </div>
                </div>
                <div class="flex justify-end space-x-2 pt-2 border-t border-slate-100">
                    <button
                        type="button"
                        @click="showOverrideModal = false"
                        class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 rounded-xl text-xs font-semibold transition-colors cursor-pointer"
                    >Cancel</button>
                    <button
                        type="button"
                        @click="saveOverride"
                        :disabled="isSavingOverride"
                        class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white rounded-xl text-xs font-semibold transition-colors shadow-xs cursor-pointer flex items-center gap-1.5"
                    >
                        <svg
                            v-if="isSavingOverride"
                            class="animate-spin h-3.5 w-3.5 text-white motion-reduce:animate-none"
                            fill="none"
                            viewBox="0 0 24 24"
                            aria-hidden="true"
                        >
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span>{{ isSavingOverride ? 'Saving...' : 'Save' }}</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted, reactive } from 'vue';
import { useAttendanceStore } from '../../stores/attendanceStore';
import { useEmployeeStore } from '../../stores/employeeStore';
import ManualAttendanceEntry from './ManualAttendanceEntry.vue';
import EmployeeAttendanceCalendar from './EmployeeAttendanceCalendar.vue';
import notify from '../../utils/notify';

const attendanceStore = useAttendanceStore();
const employeeStore = useEmployeeStore();

const showManualModal = ref(false);
const showCalendarModal = ref(false);
const showOverrideModal = ref(false);
const isSavingOverride = ref(false);
const selectedEmployee = ref(null);
const currentRecord = ref(null);

const overrideForm = reactive({
    status: 'present',
    remarks: '',
});

const closeOverrideModal = () => {
    showOverrideModal.value = false;
    currentRecord.value = null;
};

const handleGlobalKeydown = (e) => {
    if (e.key === 'Escape' && showOverrideModal.value) {
        closeOverrideModal();
    }
};

onMounted(() => {
    employeeStore.fetchMetadata();
    attendanceStore.fetchDailyAttendance();
    window.addEventListener('keydown', handleGlobalKeydown);
});

onUnmounted(() => {
    window.removeEventListener('keydown', handleGlobalKeydown);
});

const handleDateChange = () => {
    attendanceStore.fetchDailyAttendance();
};

const handleFinalize = async () => {
    const confirmed = await notify.confirm(
        'Finalize Daily Attendance',
        `Finalize daily attendance records for ${attendanceStore.selectedDate}?`,
        'Yes, Finalize'
    );
    if (confirmed) {
        await attendanceStore.triggerDailyFinalizer();
    }
};
const finalizeAttendance = handleFinalize;

const openCalendar = (employee) => {
    selectedEmployee.value = employee;
    showCalendarModal.value = true;
};

const openOverrideModal = (record) => {
    currentRecord.value = record;
    overrideForm.status = record.status || 'present';
    overrideForm.remarks = record.remarks || '';
    showOverrideModal.value = true;
};

const saveOverride = async () => {
    if (!currentRecord.value?.id) return;
    isSavingOverride.value = true;
    try {
        await attendanceStore.overrideStatus(currentRecord.value.id, overrideForm);
        closeOverrideModal();
    } catch {
        // error handled by store/notify
    } finally {
        isSavingOverride.value = false;
    }
};

const formatTime = (ts) => {
    if (!ts) return '';
    try {
        const d = new Date(ts);
        return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    } catch {
        return ts;
    }
};

const formatStatus = (s) => (s || 'absent').replace(/_/g, ' ');

const getStatusBadgeClass = (status) => {
    switch (status) {
        case 'present': return 'bg-emerald-50 border border-emerald-200 text-emerald-700';
        case 'late': case 'late_and_early_out': return 'bg-amber-50 border border-amber-200 text-amber-700';
        case 'absent': return 'bg-rose-50 border border-rose-200 text-rose-700';
        case 'on_leave': return 'bg-indigo-50 border border-indigo-200 text-indigo-700';
        case 'half_day': case 'early_out': return 'bg-sky-50 border border-sky-200 text-sky-700';
        default: return 'bg-slate-100 border border-slate-200 text-slate-600';
    }
};
</script>
