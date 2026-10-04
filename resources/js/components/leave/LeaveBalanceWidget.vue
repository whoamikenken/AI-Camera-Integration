<template>
    <div class="bg-white border border-slate-200/80 rounded-2xl p-5 space-y-4 shadow-xs">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
            <h3 class="font-bold text-slate-900 text-base flex items-center gap-2">
                <span>💳</span> Leave Balances &amp; Entitlements
            </h3>
            <span class="text-xs text-slate-500 font-mono">Year: {{ new Date().getFullYear() }}</span>
        </div>

        <div v-if="leaveStore.leaveBalances.length === 0" class="py-6 text-center text-slate-500 text-sm">
            No leave balance quotas assigned yet.
        </div>
        <div v-else class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
            <div v-for="bal in leaveStore.leaveBalances" :key="bal.id" class="bg-slate-50 border border-slate-200/80 p-4 rounded-xl space-y-2.5">
                <div class="flex justify-between items-center">
                    <span class="text-xs font-bold text-slate-700 uppercase tracking-wide">{{ bal.leave_type?.name || 'Leave' }}</span>
                    <span class="text-xs px-2.5 py-0.5 bg-indigo-50 text-indigo-700 border border-indigo-200 rounded-full font-mono font-semibold">
                        {{ bal.allocated - bal.used }} left
                    </span>
                </div>
                <div class="w-full bg-slate-200 h-2 rounded-full overflow-hidden">
                    <div class="bg-indigo-600 h-full rounded-full transition-all duration-500" :style="{ width: `${Math.min(100, (bal.used / (bal.allocated || 1)) * 100)}%` }"></div>
                </div>
                <div class="flex justify-between text-[11px] text-slate-500 font-medium">
                    <span>Used: <strong class="text-slate-700">{{ bal.used }}d</strong></span>
                    <span>Total: <strong class="text-slate-700">{{ bal.allocated }}d</strong></span>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { onMounted } from 'vue';
import { useLeaveStore } from '../../stores/leaveStore';

const leaveStore = useLeaveStore();

onMounted(() => {
    leaveStore.fetchLeaveBalances();
});
</script>
