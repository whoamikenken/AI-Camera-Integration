<template>
    <div class="space-y-6">
        <div class="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-xs flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h1 class="text-lg font-bold text-slate-900 tracking-tight flex items-center gap-2">
                    <span>🏢</span> Visitor Management System
                </h1>
                <p class="text-xs text-slate-500 mt-1">Visitor registration, host notifications, badge issuance, and temporary biometric whitelist sync.</p>
            </div>

            <div role="tablist" aria-label="Visitor management tabs" class="flex flex-wrap items-center bg-slate-100 p-1.5 rounded-xl border border-slate-200 gap-1">
                <button
                    type="button"
                    role="tab"
                    id="visitor-tab-dashboard"
                    :aria-selected="activeSubTab === 'dashboard' ? 'true' : 'false'"
                    aria-controls="visitor-panel-dashboard"
                    @click="activeSubTab = 'dashboard'"
                    :class="activeSubTab === 'dashboard' ? 'bg-white text-indigo-600 shadow-xs font-bold' : 'text-slate-600 hover:text-slate-900'"
                    class="px-4 py-2 rounded-lg transition-all text-xs cursor-pointer flex items-center gap-1.5 font-medium"
                >
                    <span>🪪</span> Visitor Overview
                </button>
                <button
                    type="button"
                    role="tab"
                    id="visitor-tab-watchlist"
                    :aria-selected="activeSubTab === 'watchlist' ? 'true' : 'false'"
                    aria-controls="visitor-panel-watchlist"
                    @click="activeSubTab = 'watchlist'"
                    :class="activeSubTab === 'watchlist' ? 'bg-white text-indigo-600 shadow-xs font-bold' : 'text-slate-600 hover:text-slate-900'"
                    class="px-4 py-2 rounded-lg transition-all text-xs cursor-pointer flex items-center gap-1.5 font-medium"
                >
                    <span>🛡️</span> Security Watchlist
                </button>
            </div>
        </div>

        <!-- Sub Tabs Content -->
        <div
            v-if="activeSubTab === 'dashboard'"
            id="visitor-panel-dashboard"
            role="tabpanel"
            aria-labelledby="visitor-tab-dashboard"
            tabindex="0"
        >
            <VisitorDashboard />
        </div>
        <div
            v-else-if="activeSubTab === 'watchlist'"
            id="visitor-panel-watchlist"
            role="tabpanel"
            aria-labelledby="visitor-tab-watchlist"
            tabindex="0"
        >
            <WatchlistManager />
        </div>
    </div>
</template>

<script setup>
import { ref } from 'vue';
import VisitorDashboard from './VisitorDashboard.vue';
import WatchlistManager from './WatchlistManager.vue';

const activeSubTab = ref('dashboard');
</script>
