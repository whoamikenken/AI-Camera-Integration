<template>
    <div class="space-y-4">
        <!-- Controls & Filters Bar -->
        <div class="flex flex-wrap items-center justify-between gap-3 bg-white border border-slate-200/80 p-4 rounded-2xl shadow-xs">
            <div class="flex flex-wrap items-center gap-3">
                <input v-model="attendanceStore.selectedDate" @change="handleDateChange" type="date"
                    class="bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 cursor-pointer shadow-xs" />

                <select v-model="attendanceStore.selectedDepartmentId" @change="attendanceStore.fetchDailyAttendance"
                    class="bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 cursor-pointer shadow-xs">
                    <option value="">All Departments</option>
                    <option v-for="dept in employeeStore.departments" :key="dept.id" :value="dept.id">{{ dept.name }}</option>
                </select>

                <select v-model="attendanceStore.selectedStatus" @change="attendanceStore.fetchDailyAttendance"
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
                        class="bg-white border border-slate-200 rounded-lg pl-8 pr-3 py-1.5 text-xs text-slate-900 placeholder-slate-400 focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 w-56 shadow-xs" />
                    <span class="absolute left-2.5 top-2 text-slate-400 text-xs">🔍</span>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <button @click="showManualModal = true" class="px-3.5 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-lg transition-colors flex items-center gap-1.5 shadow-xs cursor-pointer">
                    <span>✍️</span> Manual Punch
                </button>
                <button @click="handleFinalize" :disabled="attendanceStore.finalizing" class="px-3.5 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 text-xs font-semibold rounded-lg transition-colors flex items-center gap-1.5 cursor-pointer disabled:opacity-50">
                    <span :class="{'animate-spin': attendanceStore.finalizing}">⚙️</span> Finalize Day
                </button>
                <button @click="attendanceStore.fetchDailyAttendance" class="p-2 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-lg border border-slate-200 text-xs cursor-pointer transition-colors" title="Refresh">
                    🔄
                </button>
            </div>
        </div>

        <!-- Attendance Roster Table -->
        <div class="bg-white border border-slate-200/80 rounded-2xl overflow-hidden shadow-xs">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-slate-700">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500 font-semibold border-b border-slate-200">
                        <tr>
                            <th class="px-4 py-3">Employee</th>
                            <th class="px-4 py-3">Department</th>
                            <th class="px-4 py-3">Shift</th>
                            <th class="px-4 py-3">First In</th>
                            <th class="px-4 py-3">Last Out</th>
                            <th class="px-4 py-3">Work Hours</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-if="attendanceStore.loading" class="text-center">
                            <td colspan="8" class="py-12 text-slate-500 text-xs">Loading daily attendance roster...</td>
                        </tr>
                        <tr v-else-if="attendanceStore.filteredRoster.length === 0" class="text-center">
                            <td colspan="8" class="py-12 text-slate-500 text-xs">No attendance records found for this date.</td>
                        </tr>
                        <tr v-for="item in attendanceStore.filteredRoster" :key="item.id || item.employee_id" class="hover:bg-slate-50/80 transition-colors">
                            <td class="px-4 py-3">
                                <div class="flex items-center space-x-3">
                                    <div class="w-9 h-9 rounded-full bg-slate-100 border border-slate-200 flex items-center justify-center overflow-hidden flex-shrink-0 shadow-2xs">
                                        <img v-if="item.employee?.personnel?.photo_path" :src="item.employee?.personnel?.photo_path" class="w-full h-full object-cover" />
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
                                <button @click="openCalendar(item.employee)" title="View Calendar" class="px-2.5 py-1 bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 rounded-lg text-xs font-semibold transition-colors cursor-pointer">
                                    📅 Calendar
                                </button>
                                <button @click="openOverrideModal(item)" title="Override Status" class="px-2.5 py-1 bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 rounded-lg text-xs font-semibold transition-colors cursor-pointer">
                                    ✏️ Edit
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

        <!-- Status Override Modal -->
        <div v-if="showOverrideModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm" @click.self="showOverrideModal = false">
            <div class="bg-white border border-slate-200 rounded-2xl max-w-md w-full p-6 shadow-2xl space-y-4">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                    <h3 class="text-base font-bold text-slate-900">Override Attendance Status</h3>
                    <button @click="showOverrideModal = false" aria-label="Close dialog" class="text-slate-400 hover:text-slate-700 font-bold p-1 cursor-pointer">✕</button>
                </div>
                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Status</label>
                        <select v-model="overrideForm.status" class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs cursor-pointer">
                            <option value="present">Present</option>
                            <option value="late">Late</option>
                            <option value="early_out">Early Out</option>
                            <option value="half_day">Half Day</option>
                            <option value="on_leave">On Leave</option>
                            <option value="absent">Absent</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">HR Remarks</label>
                        <textarea v-model="overrideForm.remarks" rows="2" class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 placeholder-slate-400 focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs" placeholder="Reason for override..."></textarea>
                    </div>
                </div>
                <div class="flex justify-end space-x-2 pt-2 border-t border-slate-100">
                    <button @click="showOverrideModal = false" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 rounded-xl text-xs font-semibold transition-colors cursor-pointer">Cancel</button>
                    <button @click="saveOverride" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-semibold transition-colors shadow-xs cursor-pointer">Save</button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted, reactive } from 'vue';
import { useAttendanceStore } from '../../stores/attendanceStore';
import { useEmployeeStore } from '../../stores/employeeStore';
import ManualAttendanceEntry from './ManualAttendanceEntry.vue';
import EmployeeAttendanceCalendar from './EmployeeAttendanceCalendar.vue';

const attendanceStore = useAttendanceStore();
const employeeStore = useEmployeeStore();

const showManualModal = ref(false);
const showCalendarModal = ref(false);
const showOverrideModal = ref(false);
const selectedEmployee = ref(null);
const currentRecord = ref(null);

const overrideForm = reactive({
    status: 'present',
    remarks: '',
});

onMounted(() => {
    employeeStore.fetchMetadata();
    attendanceStore.fetchDailyAttendance();
});

const handleDateChange = () => {
    attendanceStore.fetchDailyAttendance();
};

const handleFinalize = async () => {
    if (confirm(`Finalize daily attendance records for ${attendanceStore.selectedDate}?`)) {
        await attendanceStore.triggerDailyFinalizer();
    }
};

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
    if (currentRecord.value?.id) {
        await attendanceStore.overrideStatus(currentRecord.value.id, overrideForm);
        showOverrideModal.value = false;
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
