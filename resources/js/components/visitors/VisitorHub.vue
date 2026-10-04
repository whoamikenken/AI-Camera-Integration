<template>
    <div class="space-y-6">
        <div class="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-xs flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h1 class="text-lg font-bold text-slate-900 tracking-tight flex items-center gap-2">
                    <span>🏢</span> Visitor Management System
                </h1>
                <p class="text-xs text-slate-500 mt-1">Visitor registration, host notifications, badge issuance, and temporary biometric whitelist sync.</p>
            </div>

            <div class="flex items-center bg-slate-100 p-1.5 rounded-xl border border-slate-200 overflow-x-auto">
                <button @click="activeSubTab = 'dashboard'"
                    :class="activeSubTab === 'dashboard' ? 'bg-white text-indigo-600 shadow-xs font-bold' : 'text-slate-600 hover:text-slate-900'"
                    class="px-4 py-2 rounded-lg transition-all text-xs cursor-pointer flex items-center gap-1.5 font-medium">
                    <span>🪪</span> Visitor Overview
                </button>
                <button @click="activeSubTab = 'watchlist'"
                    :class="activeSubTab === 'watchlist' ? 'bg-white text-indigo-600 shadow-xs font-bold' : 'text-slate-600 hover:text-slate-900'"
                    class="px-4 py-2 rounded-lg transition-all text-xs cursor-pointer flex items-center gap-1.5 font-medium">
                    <span>🛡️</span> Security Watchlist
                </button>
            </div>
        </div>

        <VisitorDashboard v-if="activeSubTab === 'dashboard'" />
        <WatchlistManager v-else-if="activeSubTab === 'watchlist'" />
    </div>
</template>

<script setup>
import { ref } from 'vue';
import VisitorDashboard from './VisitorDashboard.vue';
import WatchlistManager from './WatchlistManager.vue';

const activeSubTab = ref('dashboard');
</script>
