<template>
  <div class="space-y-6">
    <!-- Top Stream Control Bar -->
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 bg-slate-900/80 border border-slate-800 p-4 rounded-xl backdrop-blur-md">
      <div class="flex items-center gap-3">
        <div class="relative flex h-3.5 w-3.5">
          <span v-if="store.wsConnected" class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
          <span :class="store.wsConnected ? 'bg-emerald-500' : 'bg-rose-500'" class="relative inline-flex rounded-full h-3.5 w-3.5"></span>
        </div>
        <div>
          <h2 class="text-lg font-semibold text-slate-100 flex items-center gap-2">
            Live Vision Telemetry Stream
            <span class="text-xs px-2 py-0.5 rounded-full font-mono font-normal" :class="store.wsConnected ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-rose-500/10 text-rose-400 border border-rose-500/20'">
              {{ store.wsConnected ? 'Reverb Connected' : 'Reconnecting...' }}
            </span>
          </h2>
          <p class="text-xs text-slate-400">Streaming live biometric verifications and edge AI vision telemetry</p>
        </div>
      </div>

      <div class="flex items-center gap-3 w-full sm:w-auto">
        <!-- Audio Alert Toggle -->
        <button 
          @click="store.soundEnabled = !store.soundEnabled"
          class="px-3 py-1.5 text-xs font-medium rounded-lg border transition-all flex items-center gap-2"
          :class="store.soundEnabled ? 'bg-indigo-600/20 border-indigo-500/30 text-indigo-300' : 'bg-slate-800/60 border-slate-700 text-slate-400 hover:text-slate-200'"
        >
          <span v-if="store.soundEnabled">🔊 Audio Alert: ON</span>
          <span v-else>🔇 Audio Alert: OFF</span>
        </button>

        <!-- Stream Filter -->
        <select v-model="statusFilter" class="bg-slate-800/80 border border-slate-700 text-xs rounded-lg px-3 py-1.5 text-slate-200 focus:outline-none focus:ring-1 focus:ring-indigo-500">
          <option value="all">All Events</option>
          <option value="1">Allowed (Whitelisted)</option>
          <option value="2">Rejected / Denied</option>
          <option value="3">Not Registered</option>
        </select>
      </div>
    </div>

    <!-- Main Live Access Telemetry Feed -->
    <div class="space-y-4">
      <div class="flex items-center justify-between">
        <h3 class="text-sm font-semibold uppercase tracking-wider text-slate-400 flex items-center gap-2">
          Verification Feed ({{ filteredLogs.length }})
        </h3>
        <span class="text-xs text-slate-500">Auto-updating in real-time</span>
      </div>

      <div v-if="filteredLogs.length === 0" class="bg-slate-900/40 border border-dashed border-slate-800 rounded-xl p-12 text-center text-slate-500">
        <div class="text-3xl mb-2">📹</div>
        <div class="font-medium text-slate-300">Awaiting Telemetry Events</div>
        <div class="text-xs mt-1 text-slate-500">Events published to MQTT topic <code class="text-indigo-400">mqtt/face/+/Rec</code> will appear here instantly.</div>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div 
          v-for="log in filteredLogs" 
          :key="log.id || log.captured_at"
          class="bg-slate-900/90 border rounded-xl p-4 transition-all hover:border-slate-700 flex items-center justify-between gap-4"
          :class="getCardBorderClass(log.verify_status)"
        >
          <div class="flex items-center gap-4 min-w-0">
            <!-- Face Thumbnail -->
            <div class="relative w-16 h-16 rounded-lg bg-slate-800 border border-slate-700 overflow-hidden shrink-0 cursor-pointer" @click="openImageModal(log.snap_pic_url, log.scene_pic_url, log.person_name)">
              <img v-if="log.snap_pic_url" :src="log.snap_pic_url" alt="Face Snapshot" class="w-full h-full object-cover hover:scale-105 transition-transform" />
              <div v-else class="w-full h-full flex items-center justify-center text-xs text-slate-500 font-mono">NO PIC</div>
              <div v-if="log.is_no_mask === 1" class="absolute bottom-0 right-0 bg-amber-500 text-black text-[9px] px-1 font-bold rounded-tl">NO MASK</div>
            </div>

            <!-- Details -->
            <div class="min-w-0">
              <div class="flex items-center gap-2">
                <span class="font-semibold text-slate-100 text-base truncate">{{ log.person_name || 'Unregistered Person' }}</span>
                <span v-if="log.customize_id" class="text-xs px-1.5 py-0.5 bg-slate-800 text-slate-400 rounded font-mono shrink-0">ID: {{ log.customize_id }}</span>
              </div>
              <div class="text-xs text-slate-400 mt-1 flex flex-wrap items-center gap-x-3 gap-y-1">
                <span>Camera: <strong class="text-slate-300">{{ log.device_id }}</strong></span>
                <span>Time: <span class="text-slate-300">{{ formatTime(log.captured_at) }}</span></span>
              </div>

              <!-- Similarity Bar -->
              <div v-if="log.similarity" class="mt-2 flex items-center gap-2">
                <div class="w-28 bg-slate-800 rounded-full h-1.5 overflow-hidden">
                  <div 
                    class="h-full rounded-full transition-all"
                    :class="log.similarity >= 80 ? 'bg-emerald-500' : 'bg-amber-500'"
                    :style="{ width: `${log.similarity}%` }"
                  ></div>
                </div>
                <span class="text-[11px] font-mono font-medium" :class="log.similarity >= 80 ? 'text-emerald-400' : 'text-amber-400'">
                  {{ log.similarity }}% match
                </span>
              </div>
            </div>
          </div>

          <!-- Status Badge -->
          <div class="shrink-0 flex flex-col items-end gap-2">
            <span 
              class="px-3 py-1 text-xs font-semibold rounded-full uppercase tracking-wider"
              :class="getStatusBadgeClass(log.verify_status)"
            >
              {{ getStatusText(log.verify_status) }}
            </span>
            <button 
              v-if="log.scene_pic_url"
              @click="openImageModal(log.snap_pic_url, log.scene_pic_url, log.person_name)"
              class="text-xs text-slate-400 hover:text-slate-200 bg-slate-800/80 px-2.5 py-1 rounded border border-slate-700"
            >
              Scene
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Image Inspection Modal -->
    <div v-if="modal.show" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm" @click.self="modal.show = false">
      <div class="bg-slate-900 border border-slate-700 rounded-2xl max-w-3xl w-full p-6 space-y-4 max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
          <h3 class="text-base font-semibold text-slate-100">{{ modal.title }} - High Resolution Snapshot</h3>
          <button @click="modal.show = false" class="text-slate-400 hover:text-white text-lg font-bold">&times;</button>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div v-if="modal.snapUrl" class="space-y-2">
            <div class="text-xs font-semibold text-slate-400">Face Snapshot (Crop)</div>
            <img :src="modal.snapUrl" class="rounded-xl border border-slate-700 w-full max-h-72 object-contain bg-black" />
          </div>
          <div v-if="modal.sceneUrl" class="space-y-2">
            <div class="text-xs font-semibold text-slate-400">Context Scene View</div>
            <img :src="modal.sceneUrl" class="rounded-xl border border-slate-700 w-full max-h-72 object-contain bg-black" />
          </div>
        </div>
        <div class="flex justify-end pt-2">
          <button @click="modal.show = false" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-medium rounded-lg">Close</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useCameraStore } from '../stores/cameraStore';
import { formatTime } from '../utils/date';

const store = useCameraStore();
const statusFilter = ref('all');

const modal = ref({
  show: false,
  snapUrl: '',
  sceneUrl: '',
  title: ''
});

const filteredLogs = computed(() => {
  if (statusFilter.value === 'all') {
    return store.liveLogs;
  }
  return store.liveLogs.filter(log => String(log.verify_status) === statusFilter.value);
});

function getStatusText(status) {
  switch (Number(status)) {
    case 1: return 'Allowed';
    case 2: return 'Rejected';
    case 3: return 'Unregistered';
    default: return 'Captured';
  }
}

function getStatusBadgeClass(status) {
  switch (Number(status)) {
    case 1: return 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/30';
    case 2: return 'bg-rose-500/10 text-rose-400 border border-rose-500/30';
    case 3: return 'bg-amber-500/10 text-amber-400 border border-amber-500/30';
    default: return 'bg-slate-800 text-slate-300 border border-slate-700';
  }
}

function getCardBorderClass(status) {
  switch (Number(status)) {
    case 1: return 'border-emerald-500/20';
    case 2: return 'border-rose-500/30 bg-rose-950/10';
    case 3: return 'border-amber-500/20';
    default: return 'border-slate-800';
  }
}

function openImageModal(snapUrl, sceneUrl, title) {
  modal.value = {
    show: true,
    snapUrl,
    sceneUrl,
    title: title || 'Biometric Capture'
  };
}
</script>
