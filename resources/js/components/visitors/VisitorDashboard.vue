<template>
    <div class="space-y-6">
        <!-- KPI Metrics Grid -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
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
                    <h3 class="font-bold text-slate-900 text-base">Currently On-Site Visitors</h3>
                </div>

                <div class="flex items-center gap-2">
                    <select v-model="visitorStore.filters.status" @change="visitorStore.fetchVisits(1)"
                        class="bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-900 font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 cursor-pointer shadow-xs">
                        <option value="">All Visits</option>
                        <option value="checked_in">Checked In (Active)</option>
                        <option value="expected">Expected</option>
                        <option value="checked_out">Checked Out</option>
                    </select>
                    <button @click="visitorStore.fetchVisits(1)" class="p-2 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-lg border border-slate-200 text-xs cursor-pointer transition-colors" title="Refresh">
                        🔄
                    </button>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-slate-700">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500 font-semibold border-b border-slate-200">
                        <tr>
                            <th class="px-4 py-3">Visitor</th>
                            <th class="px-4 py-3">Company</th>
                            <th class="px-4 py-3">Host Employee</th>
                            <th class="px-4 py-3">Purpose</th>
                            <th class="px-4 py-3">Check-In Time</th>
                            <th class="px-4 py-3">Badge</th>
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-if="visitorStore.loading" class="text-center">
                            <td colspan="7" class="py-10 text-slate-500 text-xs">Loading visitors...</td>
                        </tr>
                        <tr v-else-if="visitorStore.visits.length === 0" class="text-center">
                            <td colspan="7" class="py-10 text-slate-500 text-xs">No visitors found.</td>
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
                            <td class="px-4 py-3 text-xs font-mono text-indigo-600 font-bold">
                                {{ visit.badge_number || '—' }}
                            </td>
                            <td class="px-4 py-3 text-right space-x-2">
                                <button @click="openBadge(visit)" class="px-2.5 py-1 bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 rounded-lg text-xs font-semibold transition-colors cursor-pointer">
                                    🪪 Pass
                                </button>
                                <button v-if="visit.status === 'checked_in'" @click="handleCheckOut(visit)" class="px-2.5 py-1 bg-rose-600 hover:bg-rose-700 text-white rounded-lg text-xs font-semibold transition-colors shadow-xs cursor-pointer">
                                    Check Out
                                </button>
                                <button v-else-if="visit.status === 'expected'" @click="handleDirectCheckIn(visit)" class="px-2.5 py-1 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-xs font-semibold transition-colors shadow-xs cursor-pointer">
                                    Check In
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Modals -->
        <VisitorCheckInWizard :isOpen="showWizard" @close="showWizard = false" @completed="visitorStore.fetchVisits(1)" />
        <VisitorBadge :isOpen="showBadgeModal" :visit="selectedVisit" @close="showBadgeModal = false" />
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
const selectedVisit = ref(null);

onMounted(() => {
    visitorStore.fetchVisits(1);
});

const openBadge = (visit) => {
    selectedVisit.value = visit;
    showBadgeModal.value = true;
};

const handleCheckOut = async (visit) => {
    if (confirm(`Check out visitor ${visit.visitor?.first_name || ''} and revoke camera access?`)) {
        await visitorStore.checkOutVisit(visit.id);
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
