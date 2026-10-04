<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3 bg-white border border-slate-200/80 p-4 rounded-2xl shadow-xs">
            <div class="flex items-center gap-3">
                <select aria-label="Filter by status" v-model="leaveStore.filters.status" @change="leaveStore.fetchLeaveRequests(1)"
                    class="bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 cursor-pointer shadow-xs">
                    <option value="">All Statuses</option>
                    <option value="pending">Pending Approval</option>
                    <option value="approved">Approved</option>
                    <option value="rejected">Rejected</option>
                </select>

                <select aria-label="Filter by leave type" v-model="leaveStore.filters.leave_type_id" @change="leaveStore.fetchLeaveRequests(1)"
                    class="bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 cursor-pointer shadow-xs">
                    <option value="">All Leave Types</option>
                    <option v-for="lt in leaveStore.leaveTypes" :key="lt.id" :value="lt.id">{{ lt.name }}</option>
                </select>
            </div>

            <button @click="showRequestModal = true" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-lg transition-colors flex items-center gap-1.5 shadow-xs cursor-pointer">
                <span>➕</span> Apply Leave
            </button>
        </div>

        <div class="bg-white border border-slate-200/80 rounded-2xl overflow-hidden shadow-xs">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-slate-700">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500 font-semibold border-b border-slate-200">
                        <tr>
                            <th scope="col" class="px-4 py-3">Employee</th>
                            <th scope="col" class="px-4 py-3">Leave Type</th>
                            <th scope="col" class="px-4 py-3">Duration</th>
                            <th scope="col" class="px-4 py-3">Days</th>
                            <th scope="col" class="px-4 py-3">Reason</th>
                            <th scope="col" class="px-4 py-3">Status</th>
                            <th scope="col" class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-if="leaveStore.loading" class="text-center">
                            <td colspan="7" class="py-12 text-slate-500 text-xs">Loading leave requests...</td>
                        </tr>
                        <tr v-else-if="leaveStore.leaveRequests.length === 0" class="text-center">
                            <td colspan="7" class="py-12 text-slate-500 text-xs">No leave requests found.</td>
                        </tr>
                        <tr v-for="req in leaveStore.leaveRequests" :key="req.id" class="hover:bg-slate-50/80 transition-colors">
                            <td class="px-4 py-3">
                                <div class="font-semibold text-slate-900">{{ req.employee?.first_name }} {{ req.employee?.last_name || '' }}</div>
                                <div class="text-xs text-slate-400 font-mono">{{ req.employee?.employee_code }} • {{ req.employee?.department?.name || 'General' }}</div>
                            </td>
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
                                <template v-if="req.status === 'pending'">
                                    <button :aria-label="`Approve leave request for ${req.employee?.first_name || 'Employee'}`" :disabled="processingRequest === req.id" @click="handleApprove(req.id)" class="px-2.5 py-1 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white rounded-lg text-xs font-semibold transition-colors cursor-pointer shadow-xs inline-flex items-center">
                                        <svg v-if="processingRequest === req.id && processingAction === 'approve'" class="animate-spin -ml-1 mr-1 h-3 w-3 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                        <span v-else>✓</span> <span class="ml-1">Approve</span>
                                    </button>
                                    <button :aria-label="`Reject leave request for ${req.employee?.first_name || 'Employee'}`" :disabled="processingRequest === req.id" @click="handleReject(req.id)" class="px-2.5 py-1 bg-rose-600 hover:bg-rose-700 disabled:opacity-50 text-white rounded-lg text-xs font-semibold transition-colors cursor-pointer shadow-xs inline-flex items-center">
                                        <svg v-if="processingRequest === req.id && processingAction === 'reject'" class="animate-spin -ml-1 mr-1 h-3 w-3 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                        <span v-else>✕</span> <span class="ml-1">Reject</span>
                                    </button>
                                </template>
                                <span v-else class="text-xs text-slate-400 font-medium">Resolved</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <LeaveRequestForm :isOpen="showRequestModal" @close="showRequestModal = false" @saved="leaveStore.fetchLeaveRequests(1)" />
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useLeaveStore } from '../../stores/leaveStore';
import LeaveRequestForm from './LeaveRequestForm.vue';

const leaveStore = useLeaveStore();
const showRequestModal = ref(false);
const processingRequest = ref(null);
const processingAction = ref(null);

onMounted(() => {
    leaveStore.fetchLeaveTypes();
    leaveStore.fetchLeaveRequests(1);
});

const getStatusBadgeClass = (status) => {
    switch (status) {
        case 'approved': return 'bg-emerald-50 border border-emerald-200 text-emerald-700';
        case 'rejected': return 'bg-rose-50 border border-rose-200 text-rose-700';
        case 'pending': return 'bg-amber-50 border border-amber-200 text-amber-700';
        default: return 'bg-slate-100 border border-slate-200 text-slate-600';
    }
};

const handleApprove = async (id) => {
    processingRequest.value = id;
    processingAction.value = 'approve';
    try {
        await leaveStore.approveLeaveRequest(id);
    } finally {
        processingRequest.value = null;
        processingAction.value = null;
    }
};

const handleReject = async (id) => {
    processingRequest.value = id;
    processingAction.value = 'reject';
    try {
        await leaveStore.rejectLeaveRequest(id);
    } finally {
        processingRequest.value = null;
        processingAction.value = null;
    }
};
</script>
