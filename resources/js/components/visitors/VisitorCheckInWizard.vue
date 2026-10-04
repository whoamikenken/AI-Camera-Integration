<template>
    <div 
        v-if="isOpen" 
        role="dialog"
        aria-modal="true"
        aria-labelledby="visitor-wizard-title"
        @keydown.escape="close"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm" 
        @click.self="close"
    >
        <div class="bg-white border border-slate-200 rounded-2xl max-w-xl w-full p-6 shadow-2xl space-y-5">
            <div class="flex items-center justify-between border-b border-slate-100 pb-4">
                <div class="flex items-center space-x-2">
                    <span class="text-xl" aria-hidden="true">🪪</span>
                    <div>
                        <h3 id="visitor-wizard-title" class="text-base font-bold text-slate-900">Visitor Check-In &amp; Camera Provisioning</h3>
                        <!-- Multi-Step Progress Tracker with aria-current="step" & aria-live -->
                        <nav aria-label="Check-in Steps" class="pt-1">
                            <ol class="flex items-center gap-2">
                                <li 
                                    v-for="step in 3" 
                                    :key="step" 
                                    :aria-current="currentStep === step ? 'step' : undefined"
                                    class="flex items-center gap-1.5 text-xs font-semibold"
                                    :class="currentStep === step ? 'text-indigo-600' : (currentStep > step ? 'text-emerald-600' : 'text-slate-400')"
                                >
                                    <span 
                                        class="w-5 h-5 rounded-full flex items-center justify-center text-[10px] border"
                                        :class="currentStep === step ? 'border-indigo-600 bg-indigo-50 font-bold' : (currentStep > step ? 'border-emerald-600 bg-emerald-50 text-emerald-700' : 'border-slate-300')"
                                    >
                                        {{ currentStep > step ? '✓' : step }}
                                    </span>
                                    <span class="hidden sm:inline">{{ step === 1 ? 'Visitor Information' : (step === 2 ? 'Host & Purpose' : 'Biometrics & Agreement') }}</span>
                                    <span v-if="step < 3" class="text-slate-300" aria-hidden="true">/</span>
                                </li>
                            </ol>
                            <div aria-live="polite" class="sr-only">Currently on Step {{ currentStep }} of 3: {{ stepTitle }}</div>
                        </nav>
                    </div>
                </div>
                <button 
                    type="button"
                    @click="close" 
                    aria-label="Close visitor check-in wizard"
                    class="text-slate-400 hover:text-slate-700 font-bold p-1 cursor-pointer rounded focus:outline-none focus:ring-2 focus:ring-indigo-500"
                >✕</button>
            </div>

            <!-- Error Banner -->
            <div 
                v-if="stepError" 
                role="alert" 
                aria-live="assertive" 
                class="p-3 bg-rose-50 border border-rose-200 text-rose-700 text-xs rounded-xl flex items-center justify-between shadow-xs"
            >
                <div class="flex items-center gap-1.5">
                    <span aria-hidden="true">⚠️</span>
                    <span>{{ stepError }}</span>
                </div>
                <button 
                    type="button" 
                    @click="stepError = ''" 
                    aria-label="Dismiss error" 
                    class="text-rose-400 hover:text-rose-600 font-bold ml-2 cursor-pointer"
                >&times;</button>
            </div>

            <!-- Step 1: Visitor Info -->
            <div v-if="currentStep === 1" class="space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="vis_first_name" class="block text-xs font-semibold text-slate-700 mb-1">First Name *</label>
                        <input 
                            id="vis_first_name"
                            v-model="form.first_name" 
                            type="text" 
                            required 
                            aria-required="true"
                            class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs" 
                        />
                    </div>
                    <div>
                        <label for="vis_last_name" class="block text-xs font-semibold text-slate-700 mb-1">Last Name</label>
                        <input 
                            id="vis_last_name"
                            v-model="form.last_name" 
                            type="text" 
                            class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs" 
                        />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="vis_company" class="block text-xs font-semibold text-slate-700 mb-1">Company / Organization</label>
                        <input 
                            id="vis_company"
                            v-model="form.company" 
                            type="text" 
                            class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs" 
                        />
                    </div>
                    <div>
                        <label for="vis_phone" class="block text-xs font-semibold text-slate-700 mb-1">Phone Number</label>
                        <input 
                            id="vis_phone"
                            v-model="form.phone" 
                            type="text" 
                            class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs" 
                        />
                    </div>
                </div>

                <div>
                    <label for="vis_email" class="block text-xs font-semibold text-slate-700 mb-1">Email Address</label>
                    <input 
                        id="vis_email"
                        v-model="form.email" 
                        type="email" 
                        class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs" 
                    />
                </div>
            </div>

            <!-- Step 2: Host & Purpose -->
            <div v-if="currentStep === 2" class="space-y-4">
                <div>
                    <label for="vis_host_employee" class="block text-xs font-semibold text-slate-700 mb-1">Host Employee</label>
                    <select 
                        id="vis_host_employee"
                        v-model="form.host_employee_id" 
                        class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs cursor-pointer"
                    >
                        <option value="">Select Host (Optional)</option>
                        <option v-for="emp in employeeStore.employees" :key="emp.id" :value="emp.id">
                            {{ emp.first_name }} {{ emp.last_name || '' }} ({{ emp.department?.name || 'Staff' }})
                        </option>
                    </select>
                </div>

                <div>
                    <label for="vis_purpose" class="block text-xs font-semibold text-slate-700 mb-1">Purpose of Visit</label>
                    <select 
                        id="vis_purpose"
                        v-model="form.purpose" 
                        class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs cursor-pointer"
                    >
                        <option value="meeting">Business Meeting</option>
                        <option value="interview">Job Interview</option>
                        <option value="vendor">Vendor / Supplier Service</option>
                        <option value="delivery">Courier / Delivery</option>
                        <option value="maintenance">Facility Maintenance</option>
                        <option value="other">Other</option>
                    </select>
                </div>

                <div>
                    <label for="vis_badge_number" class="block text-xs font-semibold text-slate-700 mb-1">Badge Number / Access Card</label>
                    <input 
                        id="vis_badge_number"
                        v-model="form.badge_number" 
                        type="text" 
                        placeholder="e.g. V-102" 
                        class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs" 
                    />
                </div>
            </div>

            <!-- Step 3: Biometric Face & NDA -->
            <div v-if="currentStep === 3" class="space-y-4">
                <div class="p-4 bg-indigo-50 border border-indigo-200 rounded-xl space-y-1.5">
                    <div class="text-xs font-bold text-indigo-900 flex items-center gap-1.5">
                        <span aria-hidden="true">⚡</span> Biometric Edge Camera Whitelist
                    </div>
                    <p class="text-xs text-indigo-700 leading-relaxed">
                        Checking in will automatically generate a temporary biometric whitelist profile and dispatch it to LAN access cameras and turnstiles.
                    </p>
                </div>

                <div class="flex items-center space-x-3 p-3.5 bg-slate-50 border border-slate-200 rounded-xl">
                    <input v-model="form.nda_signed" type="checkbox" id="ndaCheck" class="w-4 h-4 rounded text-indigo-600 border-slate-300 focus:ring-indigo-500" />
                    <label for="ndaCheck" class="text-xs text-slate-700 font-medium cursor-pointer">Visitor has signed standard confidentiality agreement / NDA</label>
                </div>
            </div>

            <!-- Footer Buttons -->
            <div class="flex items-center justify-between pt-3 border-t border-slate-100">
                <button 
                    v-if="currentStep > 1" 
                    type="button" 
                    @click="prevStep" 
                    class="px-4 py-2 text-xs font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 rounded-xl border border-slate-200 transition-colors cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
                >
                    ← Back
                </button>
                <div v-else></div>

                <div class="flex space-x-2">
                    <button 
                        type="button" 
                        @click="close" 
                        class="px-4 py-2 text-xs font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 rounded-xl border border-slate-200 transition-colors cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    >Cancel</button>
                    <button 
                        v-if="currentStep < 3" 
                        type="button" 
                        @click="nextStep" 
                        class="px-5 py-2 text-xs font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl shadow-xs transition-colors cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    >
                        Continue →
                    </button>
                    <button 
                        v-else 
                        type="button" 
                        @click="handleCompleteCheckIn" 
                        :disabled="submitting" 
                        class="px-5 py-2 text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 rounded-xl shadow-xs flex items-center gap-2 transition-colors cursor-pointer focus:outline-none focus:ring-2 focus:ring-emerald-500"
                    >
                        <svg v-if="submitting" class="animate-spin h-4 w-4 text-white" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                        </svg>
                        <span>{{ submitting ? 'Provisioning Cameras...' : 'Confirm Check-In' }}</span>
                        <span v-if="submitting" class="sr-only" role="status" aria-live="polite">Provisioning biometric access to camera hardware...</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted, onUnmounted } from 'vue';
import { useVisitorStore } from '../../stores/visitorStore';
import { useEmployeeStore } from '../../stores/employeeStore';

const props = defineProps({
    isOpen: Boolean,
});
const emit = defineEmits(['close', 'completed']);

const visitorStore = useVisitorStore();
const employeeStore = useEmployeeStore();

const currentStep = ref(1);
const submitting = ref(false);
const stepError = ref('');

const form = reactive({
    first_name: '',
    last_name: '',
    company: '',
    phone: '',
    email: '',
    host_employee_id: '',
    purpose: 'meeting',
    badge_number: '',
    nda_signed: true,
});

const stepTitle = computed(() => {
    switch (currentStep.value) {
        case 1: return 'Visitor Information';
        case 2: return 'Host & Purpose';
        case 3: return 'Biometrics & Agreement';
        default: return '';
    }
});

const nextStep = () => {
    stepError.value = '';
    if (currentStep.value === 1) {
        if (!form.first_name || !form.first_name.trim()) {
            stepError.value = "Please provide the visitor's first name.";
            return;
        }
    }
    if (currentStep.value < 3) {
        currentStep.value++;
    }
};

const prevStep = () => {
    stepError.value = '';
    if (currentStep.value > 1) {
        currentStep.value--;
    }
};

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

watch(() => props.isOpen, (val) => {
    if (val) {
        currentStep.value = 1;
        stepError.value = '';
        if (employeeStore.employees.length === 0) {
            employeeStore.fetchEmployees(1);
        }
    }
});

const close = () => emit('close');

const handleCompleteCheckIn = async () => {
    stepError.value = '';
    submitting.value = true;
    try {
        // 1. Create or resolve visitor
        const visitor = await visitorStore.createVisitor({
            first_name: form.first_name,
            last_name: form.last_name,
            company: form.company,
            phone: form.phone,
            email: form.email,
        });

        // 2. Pre-register visit
        const visitRes = await visitorStore.preRegisterVisit({
            visitor_id: visitor.id,
            host_employee_id: form.host_employee_id || null,
            purpose: form.purpose,
        });
        const visit = visitRes.data || visitRes;

        // 3. Check-in and provision face
        await visitorStore.checkInVisit(visit.id, {
            badge_number: form.badge_number,
            nda_signed: form.nda_signed,
        });

        emit('completed');
        close();
    } catch (e) {
        stepError.value = e.response?.data?.message || 'Check-in failed. Please verify the information and try again.';
    } finally {
        submitting.value = false;
    }
};
</script>
