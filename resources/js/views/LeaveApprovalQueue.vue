<template>
    <div class="p-6 space-y-6">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Leave Approval Queue</h1>
            <p class="text-sm text-slate-500">Review, approve, reject, or cancel employee leave requests.</p>
        </div>
        <LeaveApprovalQueueComponent />
    </div>
</template>

<script setup>
import LeaveApprovalQueueComponent from '../components/leave/LeaveApprovalQueue.vue';
</script>
