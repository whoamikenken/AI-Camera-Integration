<template>
    <div 
        v-if="isOpen" 
        role="dialog"
        aria-modal="true"
        aria-labelledby="manual-entry-modal-title"
        @keydown.escape="close"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm" 
        @click.self="close"
    >
        <div class="bg-white border border-slate-200 rounded-2xl max-w-lg w-full p-6 shadow-2xl space-y-5 animate-in fade-in zoom-in-95 duration-150">
            <div class="flex items-center justify-between border-b border-slate-100 pb-4">
                <div class="flex items-center space-x-2">
                    <span class="text-xl" aria-hidden="true">✍️</span>
                    <h3 id="manual-entry-modal-title" class="text-base font-bold text-slate-900">Manual Attendance Entry</h3>
                </div>
                <button 
                    type="button"
                    @click="close" 
                    aria-label="Close manual entry dialog"
                    class="text-slate-400 hover:text-slate-700 font-bold p-1 cursor-pointer rounded focus:outline-none focus:ring-2 focus:ring-indigo-500"
                >✕</button>
            </div>

            <form @submit.prevent="handleSubmit" class="space-y-4">
                <div>
                    <label for="manual_employee_id" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">Employee *</label>
                    <select 
                        id="manual_employee_id"
                        v-model="form.employee_id" 
                        required 
                        aria-required="true"
                        class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs cursor-pointer"
                    >
                        <option value="" disabled>Select Employee</option>
                        <option v-for="emp in employees" :key="emp.id" :value="emp.id">
                            {{ emp.employee_code }} - {{ emp.first_name }} {{ emp.last_name || '' }} ({{ emp.department?.name || 'General' }})
                        </option>
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="manual_date" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">Date *</label>
                        <input 
                            id="manual_date"
                            v-model="form.date" 
                            type="date" 
                            required 
                            aria-required="true"
                            class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs cursor-pointer" 
                        />
                    </div>
                    <div>
                        <label for="manual_time" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">Time *</label>
                        <input 
                            id="manual_time"
                            v-model="form.time" 
                            type="time" 
                            required 
                            aria-required="true"
                            class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs cursor-pointer" 
                        />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="manual_direction" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">Punch Direction *</label>
                        <select 
                            id="manual_direction"
                            v-model="form.direction" 
                            required 
                            aria-required="true"
                            class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs cursor-pointer"
                        >
                            <option value="in">Check-In (Arrival)</option>
                            <option value="out">Check-Out (Departure)</option>
                        </select>
                    </div>
                    <div>
                        <label for="manual_source" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">Source / Mode</label>
                        <input 
                            id="manual_source"
                            type="text" 
                            value="HR Manual Override" 
                            disabled 
                            class="w-full bg-slate-100 border border-slate-200 rounded-lg px-3 py-2 text-slate-500 text-xs font-medium cursor-not-allowed" 
                        />
                    </div>
                </div>

                <div>
                    <label for="manual_reason" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">Reason / HR Remarks *</label>
                    <textarea 
                        id="manual_reason"
                        v-model="form.reason" 
                        rows="2" 
                        placeholder="Reason for manual entry (e.g. Forgot badge, offsite meeting...)" 
                        required 
                        aria-required="true"
                        class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 placeholder-slate-400 focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs"
                    ></textarea>
                </div>

                <div class="flex items-center justify-end space-x-3 pt-3 border-t border-slate-100">
                    <button 
                        type="button" 
                        @click="close" 
                        class="px-4 py-2 text-xs font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 rounded-xl border border-slate-200 transition-colors cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    >Cancel</button>
                    <button 
                        type="submit" 
                        :disabled="submitting" 
                        class="px-5 py-2 text-xs font-semibold text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 rounded-xl transition-colors shadow-xs flex items-center space-x-2 cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    >
                        <svg v-if="submitting" class="animate-spin h-3.5 w-3.5 text-white" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                        </svg>
                        <span>{{ submitting ? 'Saving...' : 'Record Punch' }}</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, watch, onMounted, onUnmounted } from 'vue';
import { useAttendanceStore } from '../../stores/attendanceStore';
import { useEmployeeStore } from '../../stores/employeeStore';

const props = defineProps({
    isOpen: Boolean,
});
const emit = defineEmits(['close', 'saved']);

const attendanceStore = useAttendanceStore();
const employeeStore = useEmployeeStore();
const submitting = ref(false);
const employees = ref([]);

const form = reactive({
    employee_id: '',
    date: new Date().toISOString().slice(0, 10),
    time: '09:00',
    direction: 'in',
    reason: '',
});

function handleGlobalKeydown(e) {
    if (e.key === 'Escape' && props.isOpen) {
        close();
    }
}

onMounted(() => {
    window.addEventListener('keydown', handleGlobalKeydown);
});

onUnmounted(() => {
    window.removeEventListener('keydown', handleGlobalKeydown);
});

watch(() => props.isOpen, async (val) => {
    if (val) {
        if (employeeStore.employees.length === 0) {
            await employeeStore.fetchEmployees(1);
        }
        employees.value = employeeStore.employees;
        form.date = attendanceStore.selectedDate || new Date().toISOString().slice(0, 10);
    }
});

const close = () => {
    emit('close');
};

const handleSubmit = async () => {
    submitting.value = true;
    try {
        const punchDateTime = `${form.date} ${form.time}:00`;
        await attendanceStore.submitManualEntry({
            employee_id: form.employee_id,
            punch_time: punchDateTime,
            direction: form.direction,
            source: 'manual',
            reason: form.reason,
        });
        emit('saved');
        close();
    } catch (e) {
        // error handled in store
    } finally {
        submitting.value = false;
    }
};
</script>
