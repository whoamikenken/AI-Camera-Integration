<template>
    <div class="space-y-6">
        <!-- Date Range & Filters -->
        <div class="flex flex-wrap items-center justify-between gap-3 bg-white border border-slate-200/80 p-5 rounded-2xl shadow-xs">
            <div class="flex flex-wrap items-center gap-3">
                <div>
                    <label class="block text-[10px] uppercase tracking-wider text-slate-500 font-bold mb-1">Report Period</label>
                    <select v-model="reportType" class="bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 cursor-pointer shadow-xs">
                        <option value="daily">Daily Summary</option>
                        <option value="monthly">Monthly Aggregate</option>
                    </select>
                </div>

                <div v-if="reportType === 'daily'">
                    <label class="block text-[10px] uppercase tracking-wider text-slate-500 font-bold mb-1">Date</label>
                    <input v-model="selectedDate" type="date" class="bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 cursor-pointer shadow-xs" />
                </div>

                <div v-else class="flex gap-2">
                    <div>
                        <label class="block text-[10px] uppercase tracking-wider text-slate-500 font-bold mb-1">Month</label>
                        <select v-model="selectedMonth" class="bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 cursor-pointer shadow-xs">
                            <option v-for="m in 12" :key="m" :value="m">{{ new Date(2026, m - 1).toLocaleString('default', { month: 'short' }) }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] uppercase tracking-wider text-slate-500 font-bold mb-1">Year</label>
                        <select v-model="selectedYear" class="bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 cursor-pointer shadow-xs">
                            <option :value="2026">2026</option>
                            <option :value="2025">2025</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-[10px] uppercase tracking-wider text-slate-500 font-bold mb-1">Department</label>
                    <select v-model="selectedDepartmentId" class="bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 cursor-pointer shadow-xs">
                        <option value="">All Departments</option>
                        <option v-for="dept in employeeStore.departments" :key="dept.id" :value="dept.id">{{ dept.name }}</option>
                    </select>
                </div>
            </div>

            <div class="flex items-center gap-2 pt-3 sm:pt-0">
                <button @click="generateReport" :disabled="reportStore.loading" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-xs font-semibold flex items-center gap-1.5 cursor-pointer shadow-xs transition-colors disabled:opacity-50">
                    <span :class="{'animate-spin': reportStore.loading}">⚡</span> Generate Report
                </button>
                <button @click="exportReport('csv')" class="px-3 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 rounded-lg text-xs font-semibold flex items-center gap-1.5 cursor-pointer transition-colors">
                    <span>📥</span> Export CSV
                </button>
            </div>
        </div>

        <!-- Report Results -->
        <div class="bg-white border border-slate-200/80 rounded-2xl p-5 space-y-4 shadow-xs">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                <h3 class="font-bold text-slate-900 text-base">Generated Attendance Report</h3>
                <span class="text-xs text-slate-500">Live Analytics Data</span>
            </div>

            <div v-if="reportStore.loading" class="py-12 text-center text-slate-500 text-sm">
                Generating comprehensive report calculations...
            </div>
            <div v-else-if="!reportData || reportData.length === 0" class="py-12 text-center text-slate-500 text-sm">
                Click "Generate Report" to view analytics.
            </div>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-left text-sm text-slate-700">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500 font-semibold border-b border-slate-200">
                        <tr>
                            <th class="px-4 py-3">Employee</th>
                            <th class="px-4 py-3">Department</th>
                            <th class="px-4 py-3">Present Days</th>
                            <th class="px-4 py-3">Late Days</th>
                            <th class="px-4 py-3">Absent Days</th>
                            <th class="px-4 py-3">Leave Days</th>
                            <th class="px-4 py-3">Total Hours</th>
                            <th class="px-4 py-3">Overtime</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="(row, idx) in reportData" :key="idx" class="hover:bg-slate-50/80 transition-colors">
                            <td class="px-4 py-3 font-semibold text-slate-900">
                                {{ row.employee_name || row.name }}
                                <div class="text-xs text-slate-400 font-mono font-normal">{{ row.employee_code }}</div>
                            </td>
                            <td class="px-4 py-3 text-slate-600 text-xs">{{ row.department || row.department_name || 'General' }}</td>
                            <td class="px-4 py-3 font-bold text-emerald-600">{{ row.present_days ?? row.present_count ?? 0 }}</td>
                            <td class="px-4 py-3 font-bold text-amber-600">{{ row.late_days ?? row.late_count ?? 0 }}</td>
                            <td class="px-4 py-3 font-bold text-rose-600">{{ row.absent_days ?? row.absent_count ?? 0 }}</td>
                            <td class="px-4 py-3 font-bold text-indigo-600">{{ row.leave_days ?? row.leave_count ?? 0 }}</td>
                            <td class="px-4 py-3 font-mono text-xs font-semibold text-slate-800">{{ Number(row.total_hours || 0).toFixed(1) }}h</td>
                            <td class="px-4 py-3 font-mono text-xs font-bold text-amber-600">{{ Number(row.overtime_hours || 0).toFixed(1) }}h</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue';
import { useReportStore } from '../../stores/reportStore';
import { useEmployeeStore } from '../../stores/employeeStore';

const reportStore = useReportStore();
const employeeStore = useEmployeeStore();

const reportType = ref('monthly');
const selectedDate = ref(new Date().toISOString().slice(0, 10));
const selectedMonth = ref(new Date().getMonth() + 1);
const selectedYear = ref(2026);
const selectedDepartmentId = ref('');

onMounted(() => {
    employeeStore.fetchMetadata();
    generateReport();
});

const generateReport = async () => {
    if (reportType.value === 'daily') {
        await reportStore.fetchDailyReport(selectedDate.value, selectedDepartmentId.value);
    } else {
        await reportStore.fetchMonthlyReport(selectedMonth.value, selectedYear.value, selectedDepartmentId.value);
    }
};

const reportData = computed(() => {
    if (reportType.value === 'daily') {
        return reportStore.dailyReportData?.data || reportStore.dailyReportData || [];
    }
    return reportStore.monthlyReportData?.data || reportStore.monthlyReportData || [];
});

const exportReport = (format) => {
    reportStore.exportReport(reportType.value, format, {
        date: selectedDate.value,
        month: selectedMonth.value,
        year: selectedYear.value,
        department_id: selectedDepartmentId.value,
    });
};
</script>
