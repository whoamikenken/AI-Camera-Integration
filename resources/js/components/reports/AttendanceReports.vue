<template>
    <div class="space-y-6">
        <!-- Date Range & Filters -->
        <div class="flex flex-wrap items-center justify-between gap-3 bg-white border border-slate-200/80 p-5 rounded-2xl shadow-xs">
            <div class="flex flex-wrap items-center gap-3">
                <div>
                    <label for="report-period-select" class="block text-[10px] uppercase tracking-wider text-slate-500 font-bold mb-1">Report Period</label>
                    <select id="report-period-select" v-model="reportType" class="bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 cursor-pointer shadow-xs">
                        <option value="daily">Daily Summary</option>
                        <option value="monthly">Monthly Aggregate</option>
                    </select>
                </div>

                <div v-if="reportType === 'daily'">
                    <label for="report-date-input" class="block text-[10px] uppercase tracking-wider text-slate-500 font-bold mb-1">Date</label>
                    <input id="report-date-input" v-model="selectedDate" type="date" class="bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 cursor-pointer shadow-xs" />
                </div>

                <div v-else class="flex gap-2">
                    <div>
                        <label for="report-month-select" class="block text-[10px] uppercase tracking-wider text-slate-500 font-bold mb-1">Month</label>
                        <select id="report-month-select" v-model="selectedMonth" class="bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 cursor-pointer shadow-xs">
                            <option v-for="m in 12" :key="m" :value="m">{{ new Date(2026, m - 1).toLocaleString('default', { month: 'short' }) }}</option>
                        </select>
                    </div>
                    <div>
                        <label for="report-year-select" class="block text-[10px] uppercase tracking-wider text-slate-500 font-bold mb-1">Year</label>
                        <select id="report-year-select" v-model="selectedYear" class="bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 cursor-pointer shadow-xs">
                            <option :value="2026">2026</option>
                            <option :value="2025">2025</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label for="report-department-select" class="block text-[10px] uppercase tracking-wider text-slate-500 font-bold mb-1">Department</label>
                    <select id="report-department-select" v-model="selectedDepartmentId" class="bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 cursor-pointer shadow-xs">
                        <option value="">All Departments</option>
                        <option v-for="dept in employeeStore.departments" :key="dept.id" :value="dept.id">{{ dept.name }}</option>
                    </select>
                </div>
            </div>

            <div class="flex items-center gap-2 pt-3 sm:pt-0">
                <button
                    @click="generateReport"
                    :disabled="reportStore.loading"
                    class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed text-white rounded-lg text-xs font-semibold flex items-center gap-1.5 cursor-pointer shadow-xs transition-colors"
                    :aria-busy="reportStore.loading"
                >
                    <svg
                        v-if="reportStore.loading"
                        class="animate-spin h-3.5 w-3.5 text-white motion-reduce:animate-none"
                        fill="none"
                        viewBox="0 0 24 24"
                        aria-hidden="true"
                    >
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span v-else aria-hidden="true">⚡</span>
                    <span>{{ reportStore.loading ? 'Generating...' : 'Generate Report' }}</span>
                </button>
                <button
                    @click="exportReport('csv')"
                    :disabled="isExporting || reportStore.loading"
                    class="px-3 py-2 bg-slate-100 hover:bg-slate-200 disabled:opacity-50 disabled:cursor-not-allowed text-slate-700 border border-slate-200 rounded-lg text-xs font-semibold flex items-center gap-1.5 cursor-pointer transition-colors"
                    :aria-busy="isExporting"
                    aria-label="Export report to CSV spreadsheet"
                >
                    <svg
                        v-if="isExporting"
                        class="animate-spin h-3.5 w-3.5 text-slate-600 motion-reduce:animate-none"
                        fill="none"
                        viewBox="0 0 24 24"
                        aria-hidden="true"
                    >
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span v-else aria-hidden="true">📥</span>
                    <span>{{ isExporting ? 'Exporting...' : 'Export CSV' }}</span>
                </button>
            </div>
        </div>

        <!-- Report Results -->
        <div class="bg-white border border-slate-200/80 rounded-2xl p-5 space-y-4 shadow-xs">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                <h3 class="font-bold text-slate-900 text-base">Generated Attendance Report</h3>
                <span class="text-xs text-slate-500">Live Analytics Data</span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-slate-700">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500 font-semibold border-b border-slate-200">
                        <tr>
                            <th scope="col" class="px-4 py-3">Employee</th>
                            <th scope="col" class="px-4 py-3">Department</th>
                            <th scope="col" class="px-4 py-3">Present Days</th>
                            <th scope="col" class="px-4 py-3">Late Days</th>
                            <th scope="col" class="px-4 py-3">Absent Days</th>
                            <th scope="col" class="px-4 py-3">Leave Days</th>
                            <th scope="col" class="px-4 py-3">Total Hours</th>
                            <th scope="col" class="px-4 py-3">Overtime</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <template v-if="reportStore.loading">
                            <tr v-for="i in 5" :key="`skel-report-${i}`" class="animate-pulse">
                                <td class="px-4 py-3">
                                    <div class="h-4 bg-slate-200 rounded w-28 mb-1.5"></div>
                                    <div class="h-3 bg-slate-100 rounded w-16"></div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="h-4 bg-slate-200 rounded w-20"></div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="h-4 bg-slate-200 rounded w-10"></div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="h-4 bg-slate-200 rounded w-10"></div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="h-4 bg-slate-200 rounded w-10"></div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="h-4 bg-slate-200 rounded w-10"></div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="h-4 bg-slate-200 rounded w-12"></div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="h-4 bg-slate-200 rounded w-12"></div>
                                </td>
                            </tr>
                        </template>
                        <tr v-else-if="!reportData || reportData.length === 0">
                            <td colspan="8" class="py-12 text-center text-slate-500 text-sm">
                                Click "Generate Report" to view analytics.
                            </td>
                        </tr>
                        <tr v-else v-for="(row, idx) in reportData" :key="idx" class="hover:bg-slate-50/80 transition-colors">
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
const isExporting = ref(false);

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

const exportReport = async (format) => {
    if (isExporting.value) return;
    isExporting.value = true;
    try {
        await reportStore.exportReport(reportType.value, format, {
            date: selectedDate.value,
            month: selectedMonth.value,
            year: selectedYear.value,
            department_id: selectedDepartmentId.value,
        });
    } finally {
        isExporting.value = false;
    }
};
</script>
