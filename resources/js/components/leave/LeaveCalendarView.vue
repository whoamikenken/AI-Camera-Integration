<template>
    <div class="bg-white border border-slate-200/80 rounded-2xl p-5 space-y-4 shadow-xs">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
            <h3 class="font-bold text-slate-900 text-base flex items-center gap-2">
                <span>🗓️</span> Department Leave Schedule
            </h3>
            <span class="text-xs text-slate-500">Out-of-Office Calendar</span>
        </div>

        <div v-if="approvedLeaves.length === 0" class="py-10 text-center text-slate-500 text-xs">
            No approved leaves scheduled for this period.
        </div>
        <div v-else class="space-y-2.5">
            <div v-for="leave in approvedLeaves" :key="leave.id" class="flex items-center justify-between p-3.5 bg-slate-50 border border-slate-200/80 rounded-xl hover:bg-slate-100/70 transition-colors">
                <div class="flex items-center space-x-3">
                    <span class="text-lg">🏖️</span>
                    <div>
                        <div class="text-sm font-semibold text-slate-900">{{ leave.employee?.first_name }} {{ leave.employee?.last_name || '' }}</div>
                        <div class="text-xs text-slate-500">{{ leave.leave_type?.name }} • {{ leave.employee?.department?.name || 'General' }}</div>
                    </div>
                </div>
                <div class="text-right">
                    <div class="text-xs font-mono text-indigo-600 font-bold">{{ leave.start_date }} → {{ leave.end_date }}</div>
                    <div class="text-[11px] text-slate-500 font-medium">{{ leave.total_days }} day(s)</div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { useLeaveStore } from '../../stores/leaveStore';

const leaveStore = useLeaveStore();

const approvedLeaves = computed(() => {
    return leaveStore.leaveRequests.filter(r => r.status === 'approved');
});
</script>
