<template>
    <div v-if="isOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm" @click.self="close" @keydown.escape="close" tabindex="-1">
        <div class="bg-white border border-slate-200 rounded-2xl max-w-sm w-full p-6 shadow-2xl space-y-5" role="dialog" aria-modal="true" aria-labelledby="visitor-badge-modal-title">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                <h3 id="visitor-badge-modal-title" class="font-bold text-slate-900 text-base">Visitor Badge Pass</h3>
                <button @click="close" aria-label="Close badge modal" class="text-slate-400 hover:text-slate-700 font-bold p-1 cursor-pointer">✕</button>
            </div>

            <!-- Printable Badge Card -->
            <div id="printable-badge" class="bg-white text-slate-900 rounded-2xl p-6 shadow-md border border-slate-200 text-center space-y-4">
                <div class="text-xs uppercase tracking-widest font-black text-indigo-600">VISITOR PASS</div>
                <div class="w-24 h-24 mx-auto rounded-full bg-indigo-50 border-2 border-indigo-500 flex items-center justify-center overflow-hidden">
                    <span class="text-2xl font-bold text-indigo-600">{{ (visit?.visitor?.first_name || 'V')[0] }}</span>
                </div>
                <div>
                    <h2 class="text-xl font-black text-slate-900">{{ visit?.visitor?.first_name }} {{ visit?.visitor?.last_name || '' }}</h2>
                    <p class="text-xs text-slate-500">{{ visit?.visitor?.company || 'Visitor' }}</p>
                </div>

                <div class="bg-slate-50 p-3.5 rounded-xl text-left text-xs space-y-1.5 border border-slate-200/80">
                    <div class="flex justify-between"><span class="text-slate-500">Host:</span> <span class="font-semibold text-slate-800">{{ visit?.host?.first_name || 'Front Desk' }}</span></div>
                    <div class="flex justify-between"><span class="text-slate-500">Date:</span> <span class="font-semibold text-slate-800">{{ new Date().toLocaleDateString() }}</span></div>
                    <div class="flex justify-between"><span class="text-slate-500">Badge #:</span> <span class="font-bold text-indigo-600 font-mono">{{ visit?.badge_number || 'V-001' }}</span></div>
                </div>

                <div class="text-[10px] text-slate-400 leading-relaxed">
                    Please wear this badge at all times on company premises.
                </div>
            </div>

            <div class="flex justify-end space-x-2 pt-2 border-t border-slate-100">
                <button @click="close" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 rounded-xl text-xs font-semibold transition-colors cursor-pointer">Close</button>
                <button @click="printBadge" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-semibold shadow-xs flex items-center gap-1.5 cursor-pointer transition-colors">
                    <span>🖨️</span> Print Badge
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
const props = defineProps({
    isOpen: Boolean,
    visit: Object,
});
const emit = defineEmits(['close']);

const close = () => emit('close');

const printBadge = () => {
    window.print();
};
</script>
