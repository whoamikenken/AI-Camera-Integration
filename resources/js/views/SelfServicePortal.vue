<template>
    <div class="p-6 space-y-6">
        <!-- Header -->
        <div class="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-xs flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-bold text-slate-900 tracking-tight flex items-center gap-2">
                    <span>👤</span> Employee Self-Service Portal
                </h1>
                <p class="text-xs text-slate-500 mt-1">
                    Manage your leave applications, attendance regularizations, and view quotas.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <button @click="showLeaveForm = true" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-lg transition-colors flex items-center gap-1.5 shadow-xs cursor-pointer">
                    <span>➕</span> Apply Leave
                </button>
            </div>
        </div>

        <!-- Quick Summary Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="bg-white border border-slate-200/80 p-4 rounded-2xl shadow-xs">
                <div class="text-[11px] text-slate-500 uppercase font-bold tracking-wider">Leave Balances</div>
                <div class="text-2xl font-bold text-slate-900 mt-1 font-mono">
                    {{ totalRemainingDays }} <span class="text-xs font-normal text-slate-400">days left</span>
                </div>
                <div class="text-[10px] text-slate-500 mt-1">Across active leave categories</div>
            </div>

            <div class="bg-white border border-slate-200/80 p-4 rounded-2xl shadow-xs">
                <div class="text-[11px] text-amber-700 uppercase font-bold tracking-wider">Pending Leaves</div>
                <div class="text-2xl font-bold text-amber-600 mt-1 font-mono">
                    {{ pendingLeavesCount }}
                </div>
                <div class="text-[10px] text-amber-600 mt-1">Awaiting manager approval</div>
            </div>

            <div class="bg-white border border-slate-200/80 p-4 rounded-2xl shadow-xs">
                <div class="text-[11px] text-indigo-700 uppercase font-bold tracking-wider">Pending Regularizations</div>
                <div class="text-2xl font-bold text-indigo-600 mt-1 font-mono">
                    {{ pendingRegsCount }}
                </div>
                <div class="text-[10px] text-indigo-600 mt-1">Punch adjustments submitted</div>
            </div>
        </div>

        <!-- Navigation Tabs -->
        <div class="flex items-center gap-2 border-b border-slate-200">
            <button
                @click="activeTab = 'leaves'"
                class="px-4 py-2.5 text-xs font-bold transition-colors border-b-2 cursor-pointer"
                :class="activeTab === 'leaves' ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-slate-500 hover:text-slate-800'"
            >
                🏖️ My Leave Requests ({{ leaveStore.leaveRequests.length }})
            </button>
            <button
                @click="activeTab = 'regularizations'"
                class="px-4 py-2.5 text-xs font-bold transition-colors border-b-2 cursor-pointer"
                :class="activeTab === 'regularizations' ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-slate-500 hover:text-slate-800'"
            >
                ⏱️ Attendance Regularizations ({{ leaveStore.regularizationRequests.length }})
            </button>
        </div>

        <!-- Tab 1: Leave Requests -->
        <div v-if="activeTab === 'leaves'" class="space-y-4">
            <div class="bg-white border border-slate-200/80 rounded-2xl overflow-hidden shadow-xs">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-slate-700">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500 font-semibold border-b border-slate-200">
                            <tr>
                                <th scope="col" class="px-4 py-3">Leave Type</th>
                                <th scope="col" class="px-4 py-3">Dates</th>
                                <th scope="col" class="px-4 py-3">Days</th>
                                <th scope="col" class="px-4 py-3">Reason</th>
                                <th scope="col" class="px-4 py-3">Status</th>
                                <th scope="col" class="px-4 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-if="leaveStore.loading" class="text-center">
                                <td colspan="6" class="py-10 text-slate-500 text-xs">Loading leave requests...</td>
                            </tr>
                            <tr v-else-if="leaveStore.leaveRequests.length === 0" class="text-center">
                                <td colspan="6" class="py-10 text-slate-500 text-xs">No leave requests found.</td>
                            </tr>
                            <tr v-for="req in leaveStore.leaveRequests" :key="req.id" class="hover:bg-slate-50/80 transition-colors">
                                <td class="px-4 py-3">
                                    <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-slate-100 border border-slate-200 text-slate-700">
                                        {{ req.leave_type?.name || 'General Leave' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-xs font-mono text-slate-600">
                                    {{ req.start_date }} → {{ req.end_date }}
                                </td>
                                <td class="px-4 py-3 font-bold text-slate-900 text-xs">
                                    {{ req.total_days }} day(s)
                                </td>
                                <td class="px-4 py-3 text-xs text-slate-500 max-w-xs truncate">
                                    {{ req.reason }}
                                </td>
                                <td class="px-4 py-3">
                                    <span :class="getStatusBadgeClass(req.status)" class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider">
                                        {{ req.status }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right space-x-2">
                                    <button
                                        v-if="req.status === 'pending' || req.status === 'approved'"
                                        :aria-label="`Cancel leave request #${req.id}`"
                                        @click="openCancelLeaveModal(req)"
                                        class="px-2.5 py-1 bg-rose-600 hover:bg-rose-700 text-white rounded-lg text-xs font-semibold transition-colors cursor-pointer shadow-xs inline-flex items-center"
                                    >
                                        Cancel Request
                                    </button>
                                    <span v-else-if="req.status === 'cancelled'" class="text-xs text-slate-400 italic">
                                        Cancelled: {{ req.cancellation_reason || 'Self-service' }}
                                    </span>
                                    <span v-else class="text-xs text-slate-400 font-medium">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Tab 2: Attendance Regularizations -->
        <div v-else class="space-y-4">
            <div class="bg-white border border-slate-200/80 rounded-2xl overflow-hidden shadow-xs">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-slate-700">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500 font-semibold border-b border-slate-200">
                            <tr>
                                <th scope="col" class="px-4 py-3">Work Date</th>
                                <th scope="col" class="px-4 py-3">Requested In</th>
                                <th scope="col" class="px-4 py-3">Requested Out</th>
                                <th scope="col" class="px-4 py-3">Reason</th>
                                <th scope="col" class="px-4 py-3">Status</th>
                                <th scope="col" class="px-4 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-if="leaveStore.regularizationRequests.length === 0" class="text-center">
                                <td colspan="6" class="py-10 text-slate-500 text-xs">No regularization requests found.</td>
                            </tr>
                            <tr v-for="reg in leaveStore.regularizationRequests" :key="reg.id" class="hover:bg-slate-50/80 transition-colors">
                                <td class="px-4 py-3 font-semibold text-slate-900 text-xs font-mono">
                                    {{ reg.date }}
                                </td>
                                <td class="px-4 py-3 text-xs font-mono text-slate-600">
                                    {{ formatDateTime(reg.requested_clock_in || reg.requested_in) }}
                                </td>
                                <td class="px-4 py-3 text-xs font-mono text-slate-600">
                                    {{ formatDateTime(reg.requested_clock_out || reg.requested_out) }}
                                </td>
                                <td class="px-4 py-3 text-xs text-slate-500 max-w-xs truncate">
                                    {{ reg.reason }}
                                </td>
                                <td class="px-4 py-3">
                                    <span :class="getStatusBadgeClass(reg.status)" class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider">
                                        {{ reg.status }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right space-x-2">
                                    <button
                                        v-if="reg.status === 'pending'"
                                        :aria-label="`Cancel regularization request #${reg.id}`"
                                        @click="openCancelRegModal(reg)"
                                        class="px-2.5 py-1 bg-rose-600 hover:bg-rose-700 text-white rounded-lg text-xs font-semibold transition-colors cursor-pointer shadow-xs inline-flex items-center"
                                    >
                                        Cancel Request
                                    </button>
                                    <span v-else-if="reg.status === 'cancelled'" class="text-xs text-slate-400 italic">
                                        Cancelled: {{ reg.cancellation_reason || 'Self-service' }}
                                    </span>
                                    <span v-else class="text-xs text-slate-400 font-medium">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Modals -->
        <LeaveRequestForm :isOpen="showLeaveForm" @close="showLeaveForm = false" @saved="refreshAll" />

        <!-- Cancel Leave Modal -->
        <div v-if="showCancelLeaveModal" role="dialog" aria-modal="true" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-white rounded-2xl border border-slate-200 max-w-md w-full p-6 shadow-2xl space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-base font-bold text-slate-900">Cancel Leave Request</h3>
                    <button @click="showCancelLeaveModal = false" class="text-slate-400 hover:text-slate-600 text-lg leading-none cursor-pointer">&times;</button>
                </div>
                <p class="text-xs text-slate-500">
                    Are you sure you want to cancel your leave request for
                    <span class="font-semibold text-slate-700">{{ leaveToCancel?.start_date }} → {{ leaveToCancel?.end_date }}</span>?
                    Allocated quota will be immediately restored.
                </p>
                <div>
                    <label class="block text-xs font-medium text-slate-700 mb-1">Reason for Cancellation</label>
                    <textarea v-model="leaveCancelReason" rows="3" placeholder="Enter reason for cancelling your leave..." class="w-full text-xs border border-slate-200 rounded-lg p-2.5 focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"></textarea>
                </div>
                <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
                    <button @click="showCancelLeaveModal = false" class="px-3.5 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-medium rounded-lg cursor-pointer">
                        Dismiss
                    </button>
                    <button :disabled="cancelling" @click="confirmCancelLeave" class="px-4 py-2 bg-rose-600 hover:bg-rose-700 disabled:opacity-50 text-white text-xs font-semibold rounded-lg shadow-sm cursor-pointer">
                        <span v-if="cancelling">Cancelling...</span>
                        <span v-else>Confirm Cancel</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Cancel Regularization Modal -->
        <div v-if="showCancelRegModal" role="dialog" aria-modal="true" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-white rounded-2xl border border-slate-200 max-w-md w-full p-6 shadow-2xl space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-base font-bold text-slate-900">Cancel Regularization Request</h3>
                    <button @click="showCancelRegModal = false" class="text-slate-400 hover:text-slate-600 text-lg leading-none cursor-pointer">&times;</button>
                </div>
                <p class="text-xs text-slate-500">
                    Are you sure you want to cancel your attendance regularization request for
                    <span class="font-semibold text-slate-700">{{ regToCancel?.date }}</span>?
                </p>
                <div>
                    <label class="block text-xs font-medium text-slate-700 mb-1">Reason for Cancellation</label>
                    <textarea v-model="regCancelReason" rows="3" placeholder="Enter reason for cancelling request..." class="w-full text-xs border border-slate-200 rounded-lg p-2.5 focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"></textarea>
                </div>
                <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
                    <button @click="showCancelRegModal = false" class="px-3.5 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-medium rounded-lg cursor-pointer">
                        Dismiss
                    </button>
                    <button :disabled="cancelling" @click="confirmCancelReg" class="px-4 py-2 bg-rose-600 hover:bg-rose-700 disabled:opacity-50 text-white text-xs font-semibold rounded-lg shadow-sm cursor-pointer">
                        <span v-if="cancelling">Cancelling...</span>
                        <span v-else>Confirm Cancel</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useLeaveStore } from '../stores/leaveStore';
import LeaveRequestForm from '../components/leave/LeaveRequestForm.vue';

const leaveStore = useLeaveStore();
const activeTab = ref('leaves');
const showLeaveForm = ref(false);

const showCancelLeaveModal = ref(false);
const leaveToCancel = ref(null);
const leaveCancelReason = ref('');

const showCancelRegModal = ref(false);
const regToCancel = ref(null);
const regCancelReason = ref('');

const cancelling = ref(false);

onMounted(() => {
    refreshAll();
});

const refreshAll = () => {
    leaveStore.fetchLeaveTypes();
    leaveStore.fetchLeaveBalances();
    leaveStore.fetchLeaveRequests(1);
    leaveStore.fetchRegularizations(1);
};

const totalRemainingDays = computed(() => {
    return leaveStore.leaveBalances.reduce((sum, b) => sum + (Number(b.remaining_days) || 0), 0);
});

const pendingLeavesCount = computed(() => {
    return leaveStore.leaveRequests.filter(r => r.status === 'pending').length;
});

const pendingRegsCount = computed(() => {
    return leaveStore.regularizationRequests.filter(r => r.status === 'pending').length;
});

const getStatusBadgeClass = (status) => {
    switch (status) {
        case 'approved': return 'bg-emerald-50 border border-emerald-200 text-emerald-700';
        case 'rejected': return 'bg-rose-50 border border-rose-200 text-rose-700';
        case 'pending': return 'bg-amber-50 border border-amber-200 text-amber-700';
        case 'cancelled': return 'bg-slate-100 border border-slate-300 text-slate-600 line-through';
        default: return 'bg-slate-100 border border-slate-200 text-slate-600';
    }
};

const formatDateTime = (ts) => {
    if (!ts) return '—';
    try {
        const d = new Date(ts);
        return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    } catch {
        return ts;
    }
};

const openCancelLeaveModal = (req) => {
    leaveToCancel.value = req;
    leaveCancelReason.value = '';
    showCancelLeaveModal.value = true;
};

const confirmCancelLeave = async () => {
    if (!leaveToCancel.value) return;
    cancelling.value = true;
    try {
        await leaveStore.cancelLeaveRequest(leaveToCancel.value.id, leaveCancelReason.value);
        showCancelLeaveModal.value = false;
        leaveToCancel.value = null;
        leaveCancelReason.value = '';
        leaveStore.fetchLeaveBalances();
    } finally {
        cancelling.value = false;
    }
};

const openCancelRegModal = (reg) => {
    regToCancel.value = reg;
    regCancelReason.value = '';
    showCancelRegModal.value = true;
};

const confirmCancelReg = async () => {
    if (!regToCancel.value) return;
    cancelling.value = true;
    try {
        await leaveStore.cancelRegularization(regToCancel.value.id, regCancelReason.value);
        showCancelRegModal.value = false;
        regToCancel.value = null;
        regCancelReason.value = '';
    } finally {
        cancelling.value = false;
    }
};
</script>
