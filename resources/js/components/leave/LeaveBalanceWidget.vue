<template>
    <div class="bg-white border border-slate-200/80 rounded-2xl p-5 space-y-4 shadow-xs">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
            <h3 class="font-bold text-slate-900 text-base flex items-center gap-2">
                <span>💳</span> Leave Balances &amp; Entitlements
            </h3>
            <span class="text-xs text-slate-500 font-mono">Year: {{ new Date().getFullYear() }}</span>
        </div>

        <div v-if="leaveStore.loading" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
            <div v-for="i in 3" :key="i" class="bg-slate-50 border border-slate-200/80 p-4 rounded-xl space-y-2.5 animate-pulse">
                <div class="flex justify-between items-center">
                    <div class="h-4 bg-slate-200 rounded w-1/3"></div>
                    <div class="h-5 bg-slate-200 rounded-full w-16"></div>
                </div>
                <div class="w-full bg-slate-200 h-2 rounded-full"></div>
                <div class="flex justify-between">
                    <div class="h-3 bg-slate-200 rounded w-1/4"></div>
                    <div class="h-3 bg-slate-200 rounded w-1/4"></div>
                </div>
            </div>
        </div>
        <div v-else-if="leaveStore.leaveBalances.length === 0" class="py-6 text-center text-slate-500 text-sm">
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
                <div class="w-full bg-slate-200 h-2 rounded-full overflow-hidden" role="progressbar" :aria-valuenow="bal.used" aria-valuemin="0" :aria-valuemax="bal.allocated || 1" :aria-label="`${bal.leave_type?.name || 'Leave'} quota usage: ${bal.used} days out of ${bal.allocated} days`">
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
