<template>
    <div class="p-6 space-y-6">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Visitor Management</h1>
            <p class="text-sm text-slate-500">Track on-site visitors, overstay security alerts, credentials, and access logs.</p>
        </div>
        <VisitorDashboardComponent />
    </div>
</template>

<script setup>
import VisitorDashboardComponent from '../components/visitors/VisitorDashboard.vue';
</script>
