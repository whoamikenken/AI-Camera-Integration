<template>
    <div class="space-y-6">
        <div class="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-xs flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h1 class="text-lg font-bold text-slate-900 tracking-tight flex items-center gap-2">
                    <span>📈</span> Reports, Analytics &amp; Payroll
                </h1>
                <p class="text-xs text-slate-500 mt-1">Audit attendance patterns, department analytics, and export payroll-compliant records.</p>
            </div>

            <button @click="showPayrollModal = true" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-semibold flex items-center gap-2 shadow-xs cursor-pointer transition-colors">
                <span>💰</span> Export Payroll Data
            </button>
        </div>

        <AttendanceReports />

        <PayrollExportModal :isOpen="showPayrollModal" @close="showPayrollModal = false" />
    </div>
</template>

<script setup>
import { ref } from 'vue';
import AttendanceReports from './AttendanceReports.vue';
import PayrollExportModal from './PayrollExportModal.vue';

const showPayrollModal = ref(false);
</script>
