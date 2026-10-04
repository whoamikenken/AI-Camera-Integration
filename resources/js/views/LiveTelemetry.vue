<template>
  <div class="space-y-6">
    <!-- Top Stream Control Bar -->
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 bg-white border border-slate-200/80 p-4 rounded-xl shadow-xs">
      <div class="flex items-center gap-3">
        <div class="relative flex h-3.5 w-3.5">
          <span v-if="store.wsConnected" class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
          <span :class="store.wsConnected ? 'bg-emerald-500' : 'bg-rose-500'" class="relative inline-flex rounded-full h-3.5 w-3.5"></span>
        </div>
        <div>
          <h2 class="text-lg font-bold text-slate-900 flex items-center gap-2">
            Live Vision Telemetry Stream
            <span class="text-xs px-2.5 py-0.5 rounded-full font-mono font-medium" :class="store.wsConnected ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-rose-50 text-rose-700 border border-rose-200'">
              {{ store.wsConnected ? 'Reverb Connected' : 'Reconnecting...' }}
            </span>
          </h2>
          <p class="text-xs text-slate-500">Streaming live biometric verifications and edge AI vision telemetry</p>
        </div>
      </div>

      <div class="flex items-center gap-3 w-full sm:w-auto flex-wrap sm:flex-nowrap justify-end">
        <!-- Audio Alert Toggle -->
        <button 
          type="button"
          role="switch"
          :aria-checked="store.soundEnabled"
          aria-label="Toggle audio alerts"
          @click="store.soundEnabled = !store.soundEnabled"
          class="px-3 py-1.5 text-xs font-medium rounded-lg border transition-all flex items-center gap-2 cursor-pointer shadow-xs focus:outline-none focus:ring-2 focus:ring-indigo-500"
          :class="store.soundEnabled ? 'bg-indigo-50 border-indigo-200 text-indigo-700' : 'bg-white border-slate-200 text-slate-600 hover:text-slate-900 hover:bg-slate-50'"
        >
          <span v-if="store.soundEnabled">🔊 Audio Alert: ON</span>
          <span v-else>🔇 Audio Alert: OFF</span>
        </button>

        <!-- Stream Filter -->
        <select 
          v-model="statusFilter" 
          @change="onFilterChange" 
          aria-label="Filter events by verification status"
          class="bg-white border border-slate-200 text-xs rounded-lg px-3 py-1.5 text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs cursor-pointer"
        >
          <option value="all">All Events</option>
          <option value="1">Allowed (Whitelisted)</option>
          <option value="2">Rejected / Denied</option>
          <option value="3">Not Registered</option>
        </select>

        <!-- Refresh Button -->
        <button 
          @click="fetchLogs(currentPage)" 
          aria-label="Refresh telemetry logs"
          class="px-3 py-1.5 bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 rounded-lg text-xs font-medium flex items-center gap-1 transition-colors shadow-xs cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
          title="Refresh Logs"
        >
          <span aria-hidden="true">🔄</span>
        </button>
      </div>
    </div>

    <!-- Main Live Access Telemetry Feed -->
    <div class="space-y-4">
      <div class="flex items-center justify-between">
        <h3 class="text-sm font-semibold uppercase tracking-wider text-slate-500 flex items-center gap-2">
          Verification Feed ({{ totalLogs }})
        </h3>
        <div class="flex items-center gap-3 text-xs text-slate-500">
          <span v-if="currentPage === 1" class="flex items-center gap-1 text-emerald-600 font-medium">
            <span class="inline-block w-1.5 h-1.5 rounded-full bg-emerald-500 animate-ping"></span>
            Live Auto-Updating
          </span>
          <span v-else class="text-slate-500 font-mono">Page {{ currentPage }} of {{ lastPage }}</span>
        </div>
      </div>

      <div 
        v-if="loading" 
        class="grid grid-cols-1 md:grid-cols-2 gap-3" 
        aria-busy="true" 
        aria-label="Loading telemetry logs"
      >
        <div 
          v-for="i in 6" 
          :key="i"
          class="bg-white border border-slate-200 rounded-xl p-4 flex items-center justify-between gap-4 animate-pulse shadow-xs"
        >
          <div class="flex items-center gap-4 min-w-0 flex-1">
            <div class="w-16 h-16 rounded-lg bg-slate-200 shrink-0"></div>
            <div class="space-y-2 flex-1">
              <div class="h-4 bg-slate-200 rounded w-1/2"></div>
              <div class="h-3 bg-slate-100 rounded w-3/4"></div>
              <div class="h-2 bg-slate-100 rounded w-1/3"></div>
            </div>
          </div>
          <div class="h-6 w-20 bg-slate-200 rounded-full"></div>
        </div>
      </div>

      <div v-else-if="sortedLogs.length === 0" class="bg-white border border-dashed border-slate-300 rounded-xl p-12 text-center text-slate-500 shadow-xs">
        <div class="text-3xl mb-2">📹</div>
        <div class="font-medium text-slate-800">Awaiting Telemetry Events</div>
        <div class="text-xs mt-1 text-slate-500">Events published to MQTT topic <code class="text-indigo-600 font-mono font-semibold">mqtt/face/+/Rec</code> will appear here instantly.</div>
      </div>

      <div v-else class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div 
          v-for="log in sortedLogs" 
          :key="log.id || log.captured_at"
          class="bg-white border rounded-xl p-4 transition-all hover:shadow-md flex items-center justify-between gap-4 relative group"
          :class="getCardBorderClass(log.verify_status)"
        >
          <div class="flex items-center gap-4 min-w-0">
            <!-- Face Thumbnail -->
            <button 
              type="button"
              @click="openImageModal(log.snap_pic_url, log.scene_pic_url, log.person_name)"
              :aria-label="`Inspect snapshot for ${log.person_name || 'Unregistered Person'}`"
              class="relative w-16 h-16 rounded-lg bg-slate-100 border border-slate-200 overflow-hidden shrink-0 cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
            >
              <img v-if="log.snap_pic_url" :src="log.snap_pic_url" :alt="`Snapshot of ${log.person_name || 'person'}`" class="w-full h-full object-cover hover:scale-105 transition-transform" />
              <div v-else class="w-full h-full flex items-center justify-center text-xs text-slate-400 font-mono">NO PIC</div>
              <div v-if="log.is_no_mask === 1" class="absolute bottom-0 right-0 bg-amber-500 text-black text-[9px] px-1 font-bold rounded-tl">NO MASK</div>
            </button>

            <!-- Details -->
            <div class="min-w-0">
              <div class="flex items-center gap-2">
                <span class="font-semibold text-slate-900 text-base truncate">{{ log.person_name || 'Unregistered Person' }}</span>
                <span v-if="log.customize_id" class="text-xs px-1.5 py-0.5 bg-slate-100 text-slate-600 rounded font-mono shrink-0 border border-slate-200">ID: {{ log.customize_id }}</span>
              </div>
              <div class="text-xs text-slate-500 mt-1 flex flex-wrap items-center gap-x-3 gap-y-1">
                <span>Camera: <strong class="text-slate-700 font-mono">{{ log.device_id }}</strong></span>
                <span>Captured: <span class="text-slate-700 font-mono">{{ formatDateTime(log.captured_at) }}</span></span>
              </div>

              <!-- Similarity Bar -->
              <div v-if="log.similarity" class="mt-2 flex items-center gap-2">
                <div class="w-28 bg-slate-100 rounded-full h-1.5 overflow-hidden border border-slate-200">
                  <div 
                    class="h-full rounded-full transition-all"
                    :class="log.similarity >= 80 ? 'bg-emerald-500' : 'bg-amber-500'"
                    :style="{ width: `${log.similarity}%` }"
                  ></div>
                </div>
                <span class="text-[11px] font-mono font-semibold" :class="log.similarity >= 80 ? 'text-emerald-700' : 'text-amber-700'">
                  {{ log.similarity }}% match
                </span>
              </div>
            </div>
          </div>

          <!-- Status Badge -->
          <div class="shrink-0 flex flex-col items-end gap-2">
            <span 
              class="px-3 py-1 text-xs font-bold rounded-full uppercase tracking-wider shadow-xs"
              :class="getStatusBadgeClass(log.verify_status)"
            >
              {{ getStatusText(log.verify_status) }}
            </span>
            <button 
              v-if="log.scene_pic_url"
              @click="openImageModal(log.snap_pic_url, log.scene_pic_url, log.person_name)"
              :aria-label="`View full scene context for ${log.person_name || 'person'}`"
              class="text-xs text-slate-600 hover:text-slate-900 bg-slate-50 hover:bg-slate-100 px-2.5 py-1 rounded border border-slate-200 transition-colors shadow-xs cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
            >
              Scene
            </button>
          </div>
        </div>
      </div>

      <!-- Pagination Control Bar -->
      <div v-if="totalLogs > 0" class="flex flex-col sm:flex-row items-center justify-between gap-4 bg-white border border-slate-200/80 p-4 rounded-xl text-xs text-slate-600 shadow-xs mt-4">
        <div class="flex items-center gap-3">
          <span>Showing <strong class="text-slate-900 font-mono">{{ fromCount }}</strong> to <strong class="text-slate-900 font-mono">{{ toCount }}</strong> of <strong class="text-slate-900 font-mono">{{ totalLogs }}</strong> telemetry logs</span>
          <div class="flex items-center gap-1.5 text-slate-500">
            <span>Show:</span>
            <select v-model.number="perPage" @change="onPerPageChange" aria-label="Items per page" class="bg-white border border-slate-200 text-xs rounded px-2 py-1 text-slate-700 focus:outline-none focus:ring-1 focus:ring-indigo-500 cursor-pointer">
              <option :value="10">10</option>
              <option :value="20">20</option>
              <option :value="50">50</option>
            </select>
          </div>
        </div>

        <div class="flex items-center gap-2">
          <button 
            :disabled="currentPage === 1 || loading" 
            @click="goToPage(currentPage - 1)"
            class="px-3.5 py-1.5 bg-white hover:bg-slate-50 disabled:opacity-40 disabled:hover:bg-white text-slate-700 rounded-lg border border-slate-200 transition-colors flex items-center gap-1.5 font-medium shadow-xs cursor-pointer"
          >
            <span>&larr;</span> Previous
          </button>
          
          <span class="px-3 py-1.5 bg-slate-50 rounded-lg border border-slate-200 font-mono text-slate-700 font-medium">
            {{ currentPage }} / {{ lastPage }}
          </span>

          <button 
            :disabled="currentPage === lastPage || loading" 
            @click="goToPage(currentPage + 1)"
            class="px-3.5 py-1.5 bg-white hover:bg-slate-50 disabled:opacity-40 disabled:hover:bg-white text-slate-700 rounded-lg border border-slate-200 transition-colors flex items-center gap-1.5 font-medium shadow-xs cursor-pointer"
          >
            Next <span>&rarr;</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Image Inspection Modal -->
    <div 
      v-if="modal.show" 
      role="dialog"
      aria-modal="true"
      aria-labelledby="image-modal-title"
      @keydown.escape="modal.show = false"
      class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm" 
      @click.self="modal.show = false"
    >
      <div class="bg-white border border-slate-200 rounded-2xl max-w-3xl w-full p-6 space-y-4 max-h-[90vh] overflow-y-auto shadow-2xl animate-in fade-in zoom-in-95 duration-150">
        <div class="flex items-center justify-between border-b border-slate-200 pb-3">
          <h3 id="image-modal-title" class="text-base font-bold text-slate-900">{{ modal.title }} - High Resolution Snapshot</h3>
          <button 
            @click="modal.show = false" 
            aria-label="Close image inspection dialog"
            class="text-slate-400 hover:text-slate-700 text-lg font-bold cursor-pointer p-1 rounded focus:outline-none focus:ring-2 focus:ring-indigo-500"
          >&times;</button>
        </div>
        <div class="grid grid-cols-1 gap-4">
          <div v-if="modal.snapUrl" class="space-y-2">
            <div class="text-xs font-semibold text-slate-500">Face Snapshot (Crop)</div>
            <img :src="modal.snapUrl" alt="High resolution facial crop" class="rounded-xl border border-slate-200 w-full max-h-72 object-contain bg-slate-950" />
          </div>
          <div v-if="modal.sceneUrl" class="space-y-2">
            <div class="text-xs font-semibold text-slate-500">Context Scene View</div>
            <img :src="modal.sceneUrl" alt="Wide-angle scene context" class="rounded-xl border border-slate-200 w-full max-h-72 object-contain bg-slate-950" />
          </div>
        </div>
        <div class="flex justify-end pt-2">
          <button @click="modal.show = false" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold rounded-lg border border-slate-300 cursor-pointer transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500">Close</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { useCameraStore } from '../stores/cameraStore';
import { formatTime, formatDateTime } from '../utils/date';
import apiClient from '../api/client';

const store = useCameraStore();
const statusFilter = ref('all');
const perPage = ref(10);
const currentPage = ref(1);
const totalLogs = ref(0);
const lastPage = ref(1);
const fromCount = ref(0);
const toCount = ref(0);
const loading = ref(false);
let pollTimer = null;

const logs = ref([]);

const modal = ref({
  show: false,
  snapUrl: '',
  sceneUrl: '',
  title: ''
});

// Always order logs with recent logs on top (captured_at descending)
const sortedLogs = computed(() => {
  return [...logs.value].sort((a, b) => {
    const timeA = new Date(a.captured_at || a.created_at || 0).getTime();
    const timeB = new Date(b.captured_at || b.created_at || 0).getTime();
    if (timeB !== timeA) {
      return timeB - timeA;
    }
    return (Number(b.id) || 0) - (Number(a.id) || 0);
  });
});

async function fetchLogs(page = 1, silent = false) {
  if (!silent) {
    loading.value = true;
  }
  try {
    const params = {
      page,
      per_page: perPage.value,
    };
    if (statusFilter.value !== 'all') {
      params.verify_status = statusFilter.value;
    }

    const res = await apiClient.get('/api/access-logs', { params });
    const fetchedLogs = res.data.data || [];

    if (page === 1) {
      const mergedMap = new Map();
      fetchedLogs.forEach(l => {
        if (l && (l.id || l.captured_at)) {
          const key = l.id ? String(l.id) : `${l.captured_at}_${l.device_id}`;
          mergedMap.set(key, l);
        }
      });
      store.liveLogs.forEach(l => {
        if (l && (l.id || l.captured_at) && (statusFilter.value === 'all' || String(l.verify_status) === String(statusFilter.value))) {
          const key = l.id ? String(l.id) : `${l.captured_at}_${l.device_id}`;
          const existing = mergedMap.get(key);
          mergedMap.set(key, existing ? { ...existing, ...l } : l);
        }
      });
      const merged = Array.from(mergedMap.values()).sort((a, b) => {
        const timeA = new Date(a.captured_at || a.created_at || 0).getTime();
        const timeB = new Date(b.captured_at || b.created_at || 0).getTime();
        if (timeB !== timeA) return timeB - timeA;
        return (Number(b.id) || 0) - (Number(a.id) || 0);
      });
      logs.value = merged.slice(0, perPage.value);
    } else {
      logs.value = fetchedLogs;
    }

    currentPage.value = res.data.current_page || 1;
    lastPage.value = res.data.last_page || 1;
    totalLogs.value = res.data.total || 0;
    fromCount.value = res.data.from || 0;
    toCount.value = res.data.to || 0;
  } catch (err) {
    console.error('Failed to fetch telemetry logs:', err);
  } finally {
    if (!silent) {
      loading.value = false;
    }
  }
}

function goToPage(page) {
  if (page >= 1 && page <= lastPage.value) {
    fetchLogs(page);
  }
}

function onFilterChange() {
  currentPage.value = 1;
  fetchLogs(1);
}

function onPerPageChange() {
  currentPage.value = 1;
  fetchLogs(1);
}

// Watch for real-time incoming websocket logs in cameraStore
watch(
  () => store.liveLogs,
  (newLiveLogs) => {
    if (!newLiveLogs || newLiveLogs.length === 0) return;
    if (currentPage.value !== 1) return;

    const latestLog = newLiveLogs[0];
    if (!latestLog) return;

    if (statusFilter.value === 'all' || String(latestLog.verify_status) === String(statusFilter.value)) {
      const index = logs.value.findIndex(
        l => (l.id && String(l.id) === String(latestLog.id)) || 
             (l.captured_at && latestLog.captured_at && new Date(l.captured_at).getTime() === new Date(latestLog.captured_at).getTime() && String(l.device_id) === String(latestLog.device_id))
      );
      if (index !== -1) {
        logs.value[index] = { ...logs.value[index], ...latestLog };
      } else {
        logs.value = [latestLog, ...logs.value];
        totalLogs.value++;
        if (logs.value.length > perPage.value) {
          logs.value.pop();
        }
      }
    }
  },
  { deep: true, immediate: true }
);

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
    case 1: return 'bg-emerald-50 text-emerald-700 border border-emerald-200';
    case 2: return 'bg-rose-50 text-rose-700 border border-rose-200';
    case 3: return 'bg-amber-50 text-amber-700 border border-amber-200';
    default: return 'bg-slate-100 text-slate-700 border border-slate-200';
  }
}

function getCardBorderClass(status) {
  switch (Number(status)) {
    case 1: return 'border-emerald-200/80 hover:border-emerald-300';
    case 2: return 'border-rose-200 bg-rose-50/20 hover:border-rose-300';
    case 3: return 'border-amber-200/80 hover:border-amber-300';
    default: return 'border-slate-200/80 hover:border-slate-300';
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

const handleKeydown = (e) => {
  if (e.key === 'Escape' && modal.value.show) {
    modal.value.show = false;
  }
};

onMounted(() => {
  window.addEventListener('keydown', handleKeydown);
  fetchLogs(1);
  if (pollTimer) clearInterval(pollTimer);
  pollTimer = setInterval(() => {
    if (store.wsConnected) return;
    if (currentPage.value === 1) {
      fetchLogs(1, true);
    }
  }, 4000);
});

onUnmounted(() => {
  window.removeEventListener('keydown', handleKeydown);
  if (pollTimer) clearInterval(pollTimer);
});
</script>
