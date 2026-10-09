<template>
  <div 
    v-if="show"
    role="dialog"
    aria-modal="true"
    aria-labelledby="campaign-modal-title"
    tabindex="-1"
    class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs"
    @keydown.escape="handleClose"
  >
    <div class="bg-white border border-slate-200 rounded-2xl max-w-md w-full p-6 space-y-5 shadow-2xl animate-in fade-in zoom-in-95 duration-150">
      <!-- Modal Header -->
      <div class="flex items-center justify-between pb-3 border-b border-slate-100">
        <div class="flex items-center gap-2">
          <span class="text-xl" aria-hidden="true">
            {{ isCompleted ? '✅' : isFailed ? '⚠️' : '🚀' }}
          </span>
          <h3 id="campaign-modal-title" class="text-base font-bold text-slate-900">
            {{ title || 'Campaign Progress' }}
          </h3>
        </div>
        <button 
          @click="handleClose"
          class="text-slate-400 hover:text-slate-600 text-lg p-1 rounded cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
          aria-label="Close campaign progress dialog"
        >
          ✕
        </button>
      </div>

      <!-- Campaign Status Badge -->
      <div class="flex items-center justify-between">
        <span class="text-xs font-semibold text-slate-500">Status</span>
        <span 
          class="px-2.5 py-0.5 rounded-full text-xs font-bold font-mono uppercase"
          :class="{
            'bg-amber-50 text-amber-700 border border-amber-200': campaign?.status === 'pending',
            'bg-indigo-50 text-indigo-700 border border-indigo-200 animate-pulse': campaign?.status === 'processing',
            'bg-emerald-50 text-emerald-700 border border-emerald-200': campaign?.status === 'completed',
            'bg-rose-50 text-rose-700 border border-rose-200': campaign?.status === 'failed',
          }"
        >
          {{ campaign?.status || 'INITIALIZING' }}
        </span>
      </div>

      <!-- Accessible Progress Bar -->
      <div class="space-y-1.5" aria-live="polite">
        <div class="flex items-center justify-between text-xs">
          <span class="text-slate-600 font-medium">Completion Rate</span>
          <span class="font-mono font-bold text-slate-900">{{ percent }}%</span>
        </div>
        <div 
          class="w-full bg-slate-100 rounded-full h-3 overflow-hidden border border-slate-200"
          role="progressbar"
          :aria-valuenow="percent"
          aria-valuemin="0"
          aria-valuemax="100"
          :aria-label="`Campaign progress: ${percent} percent completed`"
        >
          <div 
            class="h-full rounded-full transition-all duration-300"
            :class="{
              'bg-indigo-600': campaign?.status === 'processing' || campaign?.status === 'pending',
              'bg-emerald-500': campaign?.status === 'completed' && (!campaign?.failed_items || campaign?.failed_items === 0),
              'bg-amber-500': campaign?.status === 'completed' && campaign?.failed_items > 0,
              'bg-rose-500': campaign?.status === 'failed'
            }"
            :style="{ width: `${percent}%` }"
          ></div>
        </div>
      </div>

      <!-- Execution Metric Counters -->
      <div class="grid grid-cols-3 gap-2 text-center text-xs">
        <div class="bg-slate-50 border border-slate-200 rounded-xl p-2.5">
          <div class="text-slate-500 text-[11px] font-medium">Total</div>
          <div class="font-bold font-mono text-slate-900 text-sm mt-0.5">{{ campaign?.total_items || 0 }}</div>
        </div>
        <div class="bg-emerald-50/50 border border-emerald-200 rounded-xl p-2.5">
          <div class="text-emerald-700 text-[11px] font-medium">Processed</div>
          <div class="font-bold font-mono text-emerald-800 text-sm mt-0.5">{{ campaign?.processed_items || 0 }}</div>
        </div>
        <div 
          class="border rounded-xl p-2.5"
          :class="campaign?.failed_items > 0 ? 'bg-rose-50 border-rose-200' : 'bg-slate-50 border-slate-200'"
        >
          <div :class="campaign?.failed_items > 0 ? 'text-rose-700' : 'text-slate-500'" class="text-[11px] font-medium">Failed</div>
          <div :class="campaign?.failed_items > 0 ? 'text-rose-800' : 'text-slate-900'" class="font-bold font-mono text-sm mt-0.5">{{ campaign?.failed_items || 0 }}</div>
        </div>
      </div>

      <!-- Completion Summary or Action -->
      <div class="pt-2 border-t border-slate-100 flex items-center justify-end gap-2">
        <button 
          @click="handleClose"
          class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold rounded-lg transition-colors cursor-pointer"
        >
          {{ isFinished ? 'Done' : 'Dismiss (Continue in background)' }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  show: { type: Boolean, default: false },
  title: { type: String, default: '' },
  campaign: { type: Object, default: () => null },
});

const emit = defineEmits(['close']);

const percent = computed(() => {
  if (!props.campaign || !props.campaign.total_items) return 0;
  const total = Number(props.campaign.total_items);
  const processed = Number(props.campaign.processed_items || 0);
  return Math.min(100, Math.max(0, Math.round((processed / total) * 100)));
});

const isCompleted = computed(() => props.campaign?.status === 'completed');
const isFailed = computed(() => props.campaign?.status === 'failed');
const isFinished = computed(() => isCompleted.value || isFailed.value);

function handleClose() {
  emit('close');
}
</script>
