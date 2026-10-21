<template>
    <div class="space-y-4">
        <div class="bg-white border border-slate-200/80 rounded-2xl p-5 space-y-4 shadow-xs">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                <div class="flex items-center space-x-2">
                    <span class="text-xl">🛑</span>
                    <div>
                        <h3 class="font-bold text-slate-900 text-base">Security Watchlist &amp; Blocked Persons</h3>
                        <p class="text-xs text-slate-500">Individuals on this watchlist are blocked from visit check-in and trigger alerts on camera detection.</p>
                    </div>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-slate-700">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500 font-semibold border-b border-slate-200">
                        <tr>
                            <th class="px-4 py-3">Person Name</th>
                            <th class="px-4 py-3">Company</th>
                            <th class="px-4 py-3">Block Reason</th>
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-if="blockedVisitors.length === 0" class="text-center">
                            <td colspan="4" class="py-8 text-slate-500 text-xs">Security watchlist is clean. No individuals currently blocked.</td>
                        </tr>
                        <tr v-for="visitor in blockedVisitors" :key="visitor.id" class="hover:bg-slate-50/80 transition-colors">
                            <td class="px-4 py-3 font-semibold text-slate-900 flex items-center gap-2">
                                <span class="w-2 h-2 rounded-full bg-rose-500"></span>
                                {{ visitor.first_name }} {{ visitor.last_name || '' }}
                            </td>
                            <td class="px-4 py-3 text-slate-600 text-xs">{{ visitor.company || '—' }}</td>
                            <td class="px-4 py-3 text-xs text-rose-700 font-mono">{{ visitor.block_reason || 'Security Notice' }}</td>
                            <td class="px-4 py-3 text-right">
                                <button @click="confirmUnblock(visitor)" class="px-2.5 py-1 bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 rounded-lg text-xs font-semibold transition-colors cursor-pointer">
                                    Unblock
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <!-- Confirmation Modal -->
        <div v-if="showConfirmModal" role="dialog" aria-modal="true" aria-labelledby="confirm-dialog-title" tabindex="-1" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4" @keydown.escape="showConfirmModal = false">
            <div class="bg-white rounded-2xl border border-slate-200 max-w-sm w-full p-6 shadow-2xl space-y-4">
                <h3 id="confirm-dialog-title" class="text-base font-bold text-slate-900">Confirm Action</h3>
                <p class="text-sm text-slate-600">{{ confirmMessage }}</p>
                <div class="pt-4 border-t border-slate-100 flex items-center justify-end gap-2">
                    <button @click="showConfirmModal = false" class="px-3.5 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-medium rounded-lg cursor-pointer">Cancel</button>
                    <button @click="executeUnblock" class="px-4 py-2 bg-rose-600 hover:bg-rose-700 text-white text-xs font-semibold rounded-lg shadow-sm cursor-pointer">Confirm</button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue';
import { useVisitorStore } from '../../stores/visitorStore';

const visitorStore = useVisitorStore();
const allVisitors = ref([]);
const showConfirmModal = ref(false);
const confirmMessage = ref('');
const visitorToUnblock = ref(null);

onMounted(async () => {
    allVisitors.value = await visitorStore.fetchVisitors();
});

const blockedVisitors = computed(() => {
    return allVisitors.value.filter(v => v.is_blocked);
});

const confirmUnblock = (visitor) => {
    visitorToUnblock.value = visitor;
    confirmMessage.value = `Remove ${visitor.first_name} ${visitor.last_name || ''} from security watchlist?`;
    showConfirmModal.value = true;
};

const executeUnblock = async () => {
    if (visitorToUnblock.value) {
        await visitorStore.toggleWatchlist(visitorToUnblock.value.id, false, '');
        allVisitors.value = await visitorStore.fetchVisitors();
        showConfirmModal.value = false;
        visitorToUnblock.value = null;
    }
};
</script>
