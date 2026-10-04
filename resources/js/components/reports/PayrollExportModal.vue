<template>
    <div
        v-if="isOpen"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm"
        role="dialog"
        aria-modal="true"
        aria-labelledby="payroll-export-modal-title"
        @click.self="close"
        @keydown.escape="close"
    >
        <div class="bg-white border border-slate-200 rounded-2xl max-w-md w-full p-6 shadow-2xl space-y-5">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                <div class="flex items-center space-x-2">
                    <span class="text-xl" aria-hidden="true">💰</span>
                    <h3 id="payroll-export-modal-title" class="font-bold text-slate-900 text-base">Export Payroll Attendance Data</h3>
                </div>
                <button
                    @click="close"
                    aria-label="Close export dialog"
                    class="text-slate-400 hover:text-slate-700 font-bold p-1 cursor-pointer rounded-lg focus:outline-hidden focus:ring-2 focus:ring-indigo-500"
                >✕</button>
            </div>

            <p class="text-xs text-slate-500 leading-relaxed">
                Generate a payroll-ready CSV or JSON export including payable days, total work hours, deductions, and overtime calculations.
            </p>

            <div class="space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="payroll_month" class="block text-xs font-semibold text-slate-700 mb-1">Payroll Month</label>
                        <select
                            id="payroll_month"
                            v-model="month"
                            class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs cursor-pointer"
                        >
                            <option v-for="m in 12" :key="m" :value="m">{{ new Date(2026, m - 1).toLocaleString('default', { month: 'long' }) }}</option>
                        </select>
                    </div>
                    <div>
                        <label for="payroll_year" class="block text-xs font-semibold text-slate-700 mb-1">Payroll Year</label>
                        <select
                            id="payroll_year"
                            v-model="year"
                            class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs cursor-pointer"
                        >
                            <option :value="2026">2026</option>
                            <option :value="2025">2025</option>
                        </select>
                    </div>
                </div>

                <div>
                    <fieldset class="space-y-1 border-0 p-0 m-0">
                        <legend class="block text-xs font-semibold text-slate-700 mb-1">Export Format</legend>
                        <div class="grid grid-cols-2 gap-2">
                            <label class="flex items-center space-x-2 p-3 bg-slate-50 border border-slate-200 rounded-xl cursor-pointer hover:bg-slate-100/70 transition-colors" :class="{'border-indigo-500 ring-2 ring-indigo-500/20 bg-indigo-50/30': format === 'csv'}">
                                <input type="radio" v-model="format" value="csv" class="text-indigo-600 focus:ring-indigo-500" />
                                <span class="text-xs font-medium text-slate-800">CSV Spreadsheet</span>
                            </label>
                            <label class="flex items-center space-x-2 p-3 bg-slate-50 border border-slate-200 rounded-xl cursor-pointer hover:bg-slate-100/70 transition-colors" :class="{'border-indigo-500 ring-2 ring-indigo-500/20 bg-indigo-50/30': format === 'json'}">
                                <input type="radio" v-model="format" value="json" class="text-indigo-600 focus:ring-indigo-500" />
                                <span class="text-xs font-medium text-slate-800">JSON API Format</span>
                            </label>
                        </div>
                    </fieldset>
                </div>
            </div>

            <div class="flex justify-end space-x-2 pt-3 border-t border-slate-100">
                <button
                    @click="close"
                    class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 rounded-xl text-xs font-semibold transition-colors cursor-pointer"
                >Cancel</button>
                <button
                    @click="handleExport"
                    :disabled="exporting"
                    class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white rounded-xl text-xs font-semibold flex items-center gap-2 shadow-xs transition-colors cursor-pointer"
                >
                    <svg v-if="exporting" class="animate-spin h-3.5 w-3.5 text-white" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span v-else aria-hidden="true">📥</span>
                    <span>{{ exporting ? 'Exporting...' : 'Download Export' }}</span>
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { useReportStore } from '../../stores/reportStore';

const props = defineProps({
    isOpen: Boolean,
});
const emit = defineEmits(['close']);

const reportStore = useReportStore();
const month = ref(new Date().getMonth() + 1);
const year = ref(2026);
const format = ref('csv');
const exporting = ref(false);

const close = () => emit('close');

const handleExport = async () => {
    try {
        exporting.value = true;
        await reportStore.exportPayroll(month.value, year.value, format.value);
        close();
    } finally {
        exporting.value = false;
    }
};

const handleKeyDown = (e) => {
    if (e.key === 'Escape' && props.isOpen) {
        close();
    }
};

onMounted(() => window.addEventListener('keydown', handleKeyDown));
onUnmounted(() => window.removeEventListener('keydown', handleKeyDown));
</script>
