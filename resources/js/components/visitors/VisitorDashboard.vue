<template>
    <div class="space-y-6">
        <!-- KPI Metrics Grid -->
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
            <div class="bg-white border border-slate-200/80 p-4 rounded-2xl shadow-xs">
                <div class="text-[11px] text-slate-500 uppercase font-bold tracking-wider">Expected Today</div>
                <div class="text-2xl font-bold text-slate-900 mt-1 font-mono">{{ visitorStore.stats.expected_today }}</div>
                <div class="text-[10px] text-slate-400 mt-1">Pre-registered</div>
            </div>
            <div class="bg-white border border-slate-200/80 p-4 rounded-2xl shadow-xs">
                <div class="text-[11px] text-emerald-700 uppercase font-bold tracking-wider">Currently On-Site</div>
                <div class="text-2xl font-bold text-emerald-600 mt-1 font-mono">{{ visitorStore.stats.checked_in }}</div>
                <div class="text-[10px] text-emerald-600 font-medium mt-1">Active Camera Whitelist</div>
            </div>
            <div class="bg-white border border-slate-200/80 p-4 rounded-2xl shadow-xs">
                <div class="text-[11px] text-rose-700 uppercase font-bold tracking-wider">Overstay Alert</div>
                <div class="text-2xl font-bold text-rose-600 mt-1 font-mono">{{ visitorStore.stats.overdue }}</div>
                <div class="text-[10px] text-rose-600 font-medium mt-1">Security Action Required</div>
            </div>
            <div class="bg-white border border-slate-200/80 p-4 rounded-2xl shadow-xs">
                <div class="text-[11px] text-sky-700 uppercase font-bold tracking-wider">Checked Out</div>
                <div class="text-2xl font-bold text-sky-600 mt-1 font-mono">{{ visitorStore.stats.checked_out }}</div>
                <div class="text-[10px] text-sky-600 font-medium mt-1">Access Revoked</div>
            </div>
            <div class="bg-white border border-slate-200/80 p-4 rounded-2xl shadow-xs flex flex-col justify-between">
                <div class="text-[11px] text-indigo-700 uppercase font-bold tracking-wider">Express Desk</div>
                <button @click="showWizard = true" class="w-full mt-2 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-semibold transition-colors flex items-center justify-center gap-1.5 shadow-xs cursor-pointer">
                    <span>🪪</span> New Check-In
                </button>
            </div>
        </div>

        <!-- Active Visits Table -->
        <div class="bg-white border border-slate-200/80 rounded-2xl p-5 space-y-4 shadow-xs">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 pb-3">
                <div class="flex items-center space-x-2">
                    <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse"></span>
                    <h3 class="font-bold text-slate-900 text-base">Visitor Management & Log</h3>
                </div>

                <div class="flex items-center gap-2">
                    <select aria-label="Filter visits by status" v-model="visitorStore.filters.status" @change="visitorStore.fetchVisits(1)"
                        class="bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 cursor-pointer shadow-xs">
                        <option value="">All Visits</option>
                        <option value="checked_in">Checked In (Active)</option>
                        <option value="overstayed">Overstayed</option>
                        <option value="expected">Expected</option>
                        <option value="checked_out">Checked Out</option>
                        <option value="no_show">No Show</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                    <button aria-label="Refresh visits" @click="visitorStore.fetchVisits(1)" class="p-2 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-lg border border-slate-200 text-xs cursor-pointer transition-colors" title="Refresh">
                        🔄
                    </button>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-slate-700">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500 font-semibold border-b border-slate-200">
                        <tr>
                            <th scope="col" class="px-4 py-3">Visitor</th>
                            <th scope="col" class="px-4 py-3">Company</th>
                            <th scope="col" class="px-4 py-3">Host Employee</th>
                            <th scope="col" class="px-4 py-3">Purpose</th>
                            <th scope="col" class="px-4 py-3">Check-In Time</th>
                            <th scope="col" class="px-4 py-3">Status</th>
                            <th scope="col" class="px-4 py-3">Badge</th>
                            <th scope="col" class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-if="visitorStore.loading" class="text-center">
                            <td colspan="8" class="py-10 text-slate-500 text-xs">Loading visitors...</td>
                        </tr>
                        <tr v-else-if="visitorStore.visits.length === 0" class="text-center">
                            <td colspan="8" class="py-10 text-slate-500 text-xs">No visitors found.</td>
                        </tr>
                        <tr v-for="visit in visitorStore.visits" :key="visit.id" class="hover:bg-slate-50/80 transition-colors">
                            <td class="px-4 py-3">
                                <div class="font-semibold text-slate-900">{{ visit.visitor?.first_name }} {{ visit.visitor?.last_name || '' }}</div>
                                <div class="text-xs text-slate-400 font-mono">{{ visit.visitor?.phone || visit.visitor?.email || 'Guest' }}</div>
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-600">{{ visit.visitor?.company || 'Individual' }}</td>
                            <td class="px-4 py-3 text-xs text-slate-600">{{ visit.host?.first_name || visit.host_employee_id || 'Front Desk' }}</td>
                            <td class="px-4 py-3 text-xs">
                                <span class="capitalize px-2.5 py-0.5 bg-slate-100 border border-slate-200 rounded-full font-medium text-slate-700">{{ visit.purpose }}</span>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-slate-500">
                                {{ formatTime(visit.check_in_time) }}
                            </td>
                            <td class="px-4 py-3">
                                <span v-if="visit.status === 'overstayed' || isOverstay(visit)" class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-rose-100 border border-rose-300 text-rose-700 animate-pulse">
                                    ⚠️ Overstay
                                </span>
                                <span v-else-if="visit.status === 'checked_in'" class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-emerald-50 border border-emerald-200 text-emerald-700">
                                    Checked In
                                </span>
                                <span v-else-if="visit.status === 'expected'" class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-sky-50 border border-sky-200 text-sky-700">
                                    Expected
                                </span>
                                <span v-else-if="visit.status === 'checked_out'" class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-slate-100 border border-slate-200 text-slate-600">
                                    Checked Out
                                </span>
                                <span v-else-if="visit.status === 'no_show'" class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-amber-50 border border-amber-200 text-amber-700">
                                    No Show
                                </span>
                                <span v-else-if="visit.status === 'cancelled'" class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-slate-100 border border-slate-300 text-slate-500 line-through">
                                    Cancelled
                                </span>
                                <span v-else class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-slate-100 text-slate-600">
                                    {{ visit.status }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-xs font-mono text-indigo-600 font-bold">
                                {{ visit.badge_number || '—' }}
                            </td>
                            <td class="px-4 py-3 text-right space-x-2">
                                <button :aria-label="'Issue pass for ' + (visit.visitor?.first_name || '') + ' ' + (visit.visitor?.last_name || '')" @click="openBadge(visit)" class="px-2.5 py-1 bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 rounded-lg text-xs font-semibold transition-colors cursor-pointer">
                                    🪪 Pass
                                </button>
                                <button v-if="visit.status === 'checked_in' || visit.status === 'overstayed'" :aria-label="'Check out ' + (visit.visitor?.first_name || '') + ' ' + (visit.visitor?.last_name || '')" @click="confirmCheckOut(visit)" class="px-2.5 py-1 bg-rose-600 hover:bg-rose-700 text-white rounded-lg text-xs font-semibold transition-colors shadow-xs cursor-pointer">
                                    Check Out
                                </button>
                                <button v-else-if="visit.status === 'expected'" :aria-label="'Check in ' + (visit.visitor?.first_name || '') + ' ' + (visit.visitor?.last_name || '')" @click="handleDirectCheckIn(visit)" class="px-2.5 py-1 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-xs font-semibold transition-colors shadow-xs cursor-pointer">
                                    Check In
                                </button>
                                <button v-if="visit.status === 'expected' || visit.status === 'checked_in' || visit.status === 'overstayed'" :aria-label="'Cancel visit for ' + (visit.visitor?.first_name || '') + ' ' + (visit.visitor?.last_name || '')" @click="openCancelModal(visit)" class="px-2.5 py-1 bg-slate-600 hover:bg-slate-700 text-white rounded-lg text-xs font-semibold transition-colors shadow-xs cursor-pointer">
                                    Cancel
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination Controls -->
            <div v-if="visitorStore.pagination.last_page > 1" class="flex items-center justify-between pt-3 border-t border-slate-100 text-xs text-slate-500">
                <div>
                    Page {{ visitorStore.pagination.current_page }} of {{ visitorStore.pagination.last_page }}
                    ({{ visitorStore.pagination.total }} total)
                </div>
                <div class="flex items-center gap-1.5">
                    <button
                        :disabled="visitorStore.pagination.current_page <= 1"
                        @click="visitorStore.fetchVisits(visitorStore.pagination.current_page - 1)"
                        aria-label="Previous page"
                        class="px-3 py-1 bg-white border border-slate-200 hover:bg-slate-50 disabled:opacity-40 rounded-lg cursor-pointer transition-colors shadow-xs"
                    >
                        Previous
                    </button>
                    <button
                        :disabled="visitorStore.pagination.current_page >= visitorStore.pagination.last_page"
                        @click="visitorStore.fetchVisits(visitorStore.pagination.current_page + 1)"
                        aria-label="Next page"
                        class="px-3 py-1 bg-white border border-slate-200 hover:bg-slate-50 disabled:opacity-40 rounded-lg cursor-pointer transition-colors shadow-xs"
                    >
                        Next
                    </button>
                </div>
            </div>
        </div>

        <!-- Modals -->
        
        <VisitorCheckInWizard :isOpen="showWizard" @close="showWizard = false" @completed="visitorStore.fetchVisits(1)" />
        <VisitorBadge :isOpen="showBadgeModal" :visit="selectedVisit" @close="showBadgeModal = false" />
        
        <!-- Confirmation Modal -->
        <div v-if="showConfirmModal" role="dialog" aria-modal="true" aria-labelledby="confirm-dialog-title" tabindex="-1" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4" @keydown.escape="showConfirmModal = false">
            <div class="bg-white rounded-2xl border border-slate-200 max-w-sm w-full p-6 shadow-2xl space-y-4">
                <h3 id="confirm-dialog-title" class="text-base font-bold text-slate-900">Confirm Action</h3>
                <p class="text-sm text-slate-600">{{ confirmMessage }}</p>
                <div class="pt-4 border-t border-slate-100 flex items-center justify-end gap-2">
                    <button @click="showConfirmModal = false" class="px-3.5 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-medium rounded-lg cursor-pointer">Cancel</button>
                    <button @click="executeCheckOut" class="px-4 py-2 bg-rose-600 hover:bg-rose-700 text-white text-xs font-semibold rounded-lg shadow-sm cursor-pointer">Confirm</button>
                </div>
            </div>
        </div>

        <!-- Cancellation Modal -->
        <div v-if="showCancelModal" role="dialog" aria-modal="true" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4" @keydown.escape="showCancelModal = false">
            <div class="bg-white rounded-2xl border border-slate-200 max-w-md w-full p-6 shadow-2xl space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-base font-bold text-slate-900">Cancel Visit</h3>
                    <button @click="showCancelModal = false" class="text-slate-400 hover:text-slate-600 text-lg leading-none cursor-pointer">&times;</button>
                </div>
                <p class="text-xs text-slate-500">
                    Are you sure you want to cancel the visit for
                    <span class="font-semibold text-slate-700">{{ visitToCancel?.visitor?.first_name }} {{ visitToCancel?.visitor?.last_name || '' }}</span>?
                    Camera biometric access will be immediately de-provisioned.
                </p>
                <div>
                    <label class="block text-xs font-medium text-slate-700 mb-1">Cancellation Reason</label>
                    <textarea v-model="cancelReason" rows="3" placeholder="Enter reason for visit cancellation..." class="w-full text-xs border border-slate-200 rounded-lg p-2.5 focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"></textarea>
                </div>
                <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
                    <button @click="showCancelModal = false" class="px-3.5 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-medium rounded-lg cursor-pointer">
                        Dismiss
                    </button>
                    <button :disabled="cancelling" @click="executeCancelVisit" class="px-4 py-2 bg-rose-600 hover:bg-rose-700 disabled:opacity-50 text-white text-xs font-semibold rounded-lg shadow-sm cursor-pointer">
                        <span v-if="cancelling">Cancelling...</span>
                        <span v-else>Confirm Cancel</span>
                    </button>
                </div>
            </div>
        </div>

    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useVisitorStore } from '../../stores/visitorStore';
import VisitorCheckInWizard from './VisitorCheckInWizard.vue';
import VisitorBadge from './VisitorBadge.vue';

const visitorStore = useVisitorStore();
const showWizard = ref(false);
const showBadgeModal = ref(false);
const showConfirmModal = ref(false);
const confirmMessage = ref('');
const visitToCheckOut = ref(null);
const selectedVisit = ref(null);

const showCancelModal = ref(false);
const visitToCancel = ref(null);
const cancelReason = ref('');
const cancelling = ref(false);

onMounted(() => {
    visitorStore.fetchVisits(1);
});

const isOverstay = (visit) => {
    if (visit.status === 'overstayed' || visit.is_overstay) return true;
    if (visit.status === 'checked_in' && visit.expected_departure) {
        return new Date(visit.expected_departure) < new Date();
    }
    return false;
};

const openBadge = (visit) => {
    selectedVisit.value = visit;
    showBadgeModal.value = true;
};

const confirmCheckOut = (visit) => {
    visitToCheckOut.value = visit;
    confirmMessage.value = `Check out visitor ${visit.visitor?.first_name || ''} and revoke camera access?`;
    showConfirmModal.value = true;
};

const executeCheckOut = async () => {
    if (visitToCheckOut.value) {
        await visitorStore.checkOutVisit(visitToCheckOut.value.id);
        showConfirmModal.value = false;
        visitToCheckOut.value = null;
    }
};

const openCancelModal = (visit) => {
    visitToCancel.value = visit;
    cancelReason.value = '';
    showCancelModal.value = true;
};

const executeCancelVisit = async () => {
    if (visitToCancel.value) {
        cancelling.value = true;
        try {
            await visitorStore.cancelVisit(visitToCancel.value.id, cancelReason.value);
            showCancelModal.value = false;
            visitToCancel.value = null;
            cancelReason.value = '';
        } finally {
            cancelling.value = false;
        }
    }
};

const handleDirectCheckIn = async (visit) => {
    await visitorStore.checkInVisit(visit.id);
};

const formatTime = (ts) => {
    if (!ts) return 'Expected';
    try {
        const d = new Date(ts);
        return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    } catch {
        return ts;
    }
};
</script>
