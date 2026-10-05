<template>
    <div v-if="isOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm" @click.self="close" @keydown.escape="close" tabindex="-1">
        <div class="bg-white border border-slate-200 rounded-2xl max-w-lg w-full p-6 shadow-2xl space-y-5" role="dialog" aria-modal="true" aria-labelledby="leave-modal-title">
            <div class="flex items-center justify-between border-b border-slate-100 pb-4">
                <div class="flex items-center space-x-2">
                    <span class="text-xl" aria-hidden="true">🏖️</span>
                    <h3 id="leave-modal-title" class="text-base font-bold text-slate-900">Apply for Leave</h3>
                </div>
                <button @click="close" aria-label="Close dialog" class="text-slate-400 hover:text-slate-700 font-bold p-1 cursor-pointer">✕</button>
            </div>

            <form @submit.prevent="handleSubmit" class="space-y-4">
                <div>
                    <label for="employee_id" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">Employee *</label>
                    <select id="employee_id" v-model="form.employee_id" required aria-required="true" class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs cursor-pointer">
                        <option value="" disabled>Select Employee</option>
                        <option v-for="emp in employees" :key="emp.id" :value="emp.id">
                            {{ emp.employee_code }} - {{ emp.first_name }} {{ emp.last_name || '' }}
                        </option>
                    </select>
                </div>

                <div>
                    <label for="leave_type_id" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">Leave Type *</label>
                    <select id="leave_type_id" v-model="form.leave_type_id" required aria-required="true" class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs cursor-pointer">
                        <option value="" disabled>Select Leave Category</option>
                        <option v-for="lt in leaveStore.leaveTypes" :key="lt.id" :value="lt.id">
                            {{ lt.name }} (Max: {{ lt.max_days_per_year }} days)
                        </option>
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="start_date" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">Start Date *</label>
                        <input id="start_date" v-model="form.start_date" type="date" required aria-required="true" class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs cursor-pointer" />
                    </div>
                    <div>
                        <label for="end_date" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">End Date *</label>
                        <input id="end_date" v-model="form.end_date" type="date" required aria-required="true" class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs cursor-pointer" />
                    </div>
                </div>

                <div>
                    <label for="reason" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">Reason *</label>
                    <textarea id="reason" v-model="form.reason" rows="3" placeholder="State reason for absence..." required aria-required="true" class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 placeholder-slate-400 focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs"></textarea>
                </div>

                <div class="flex items-center justify-end space-x-3 pt-3 border-t border-slate-100">
                    <button type="button" @click="close" class="px-4 py-2 text-xs font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 rounded-xl border border-slate-200 transition-colors cursor-pointer">Cancel</button>
                    <button type="submit" :disabled="submitting" class="px-5 py-2 text-xs font-semibold text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 rounded-xl transition-colors shadow-xs flex items-center space-x-2 cursor-pointer">
                        <svg v-if="submitting" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span>{{ submitting ? 'Submitting...' : 'Submit Request' }}</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, watch } from 'vue';
import { useLeaveStore } from '../../stores/leaveStore';
import { useEmployeeStore } from '../../stores/employeeStore';

const props = defineProps({
    isOpen: Boolean,
});
const emit = defineEmits(['close', 'saved']);

const leaveStore = useLeaveStore();
const employeeStore = useEmployeeStore();
const submitting = ref(false);
const employees = ref([]);

const form = reactive({
    employee_id: '',
    leave_type_id: '',
    start_date: new Date().toISOString().slice(0, 10),
    end_date: new Date().toISOString().slice(0, 10),
    reason: '',
});

watch(() => props.isOpen, async (val) => {
    if (val) {
        if (employeeStore.employees.length === 0) {
            await employeeStore.fetchEmployees(1);
        }
        employees.value = employeeStore.employees;
        if (leaveStore.leaveTypes.length === 0) {
            await leaveStore.fetchLeaveTypes();
        }
        if (leaveStore.leaveTypes.length > 0) {
            form.leave_type_id = leaveStore.leaveTypes[0].id;
        }
    }
});

const close = () => emit('close');

const handleSubmit = async () => {
    submitting.value = true;
    try {
        await leaveStore.createLeaveRequest(form);
        emit('saved');
        close();
    } catch (e) {
        // error handled in store
    } finally {
        submitting.value = false;
    }
};
</script>
