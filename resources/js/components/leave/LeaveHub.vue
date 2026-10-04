<template>
    <div class="space-y-6">
        <div class="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-xs flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h1 class="text-lg font-bold text-slate-900 tracking-tight flex items-center gap-2">
                    <span>🏖️</span> Leave Management &amp; Quotas
                </h1>
                <p class="text-xs text-slate-500 mt-1">Manage employee leave applications, approvals, balances, and team calendar.</p>
            </div>
        </div>

        <LeaveBalanceWidget />

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2">
                <LeaveApprovalQueue />
            </div>
            <div>
                <LeaveCalendarView />
            </div>
        </div>
    </div>
</template>

<script setup>
import LeaveBalanceWidget from './LeaveBalanceWidget.vue';
import LeaveApprovalQueue from './LeaveApprovalQueue.vue';
import LeaveCalendarView from './LeaveCalendarView.vue';
</script>
