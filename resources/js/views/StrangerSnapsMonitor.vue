<template>
  <div class="space-y-6">
    <!-- Top Control Bar -->
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 bg-slate-900/80 border border-slate-800 p-4 rounded-xl backdrop-blur-md">
      <div class="flex items-center gap-3">
        <div class="relative flex h-3.5 w-3.5">
          <span v-if="store.wsConnected" class="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span>
          <span :class="store.wsConnected ? 'bg-amber-500' : 'bg-rose-500'" class="relative inline-flex rounded-full h-3.5 w-3.5"></span>
        </div>
        <div>
          <h2 class="text-lg font-semibold text-slate-100 flex items-center gap-2">
            Stranger &amp; AI Detection Alerts
            <span class="text-xs px-2 py-0.5 rounded-full font-mono font-normal" :class="store.wsConnected ? 'bg-amber-500/10 text-amber-400 border border-amber-500/20' : 'bg-rose-500/10 text-rose-400 border border-rose-500/20'">
              {{ store.wsConnected ? 'Real-Time Monitoring' : 'Reconnecting...' }}
            </span>
          </h2>
          <p class="text-xs text-slate-400">Live edge stranger snapshot captures, unidentified face detections, and alarm triggers</p>
        </div>
      </div>

      <div class="flex items-center gap-2 flex-wrap w-full sm:w-auto justify-end">
        <!-- View Mode Switcher -->
        <div class="bg-slate-800/80 p-0.5 rounded-lg border border-slate-700 flex items-center">
          <button 
            @click="viewMode = 'grid'" 
            class="px-2.5 py-1 text-xs font-medium rounded-md transition-colors"
            :class="viewMode === 'grid' ? 'bg-indigo-600 text-white shadow' : 'text-slate-400 hover:text-slate-200'"
            title="Grid Gallery View"
          >
            🖼️ Cards
          </button>
          <button 
            @click="viewMode = 'table'" 
            class="px-2.5 py-1 text-xs font-medium rounded-md transition-colors"
            :class="viewMode === 'table' ? 'bg-indigo-600 text-white shadow' : 'text-slate-400 hover:text-slate-200'"
            title="Table List View"
          >
            📋 Table
          </button>
        </div>

        <button 
          @click="fetchSnaps" 
          class="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 rounded-lg text-xs font-medium flex items-center gap-1.5 transition-colors"
        >
          <span>🔄</span> Refresh
        </button>
      </div>
    </div>

    <!-- Filters & Stats Banner -->
    <div class="bg-slate-900/60 border border-slate-800 p-4 rounded-xl space-y-3">
      <div class="grid grid-cols-1 sm:grid-cols-3 lg:grid-cols-4 gap-3">
        <!-- Camera Filter -->
        <div>
          <label class="block text-[11px] font-medium text-slate-400 mb-1">Camera Device</label>
          <select 
            v-model="filters.deviceId" 
            @change="applyFilters" 
            class="w-full bg-slate-800/80 border border-slate-700 text-xs rounded-lg px-3 py-2 text-slate-200 focus:outline-none focus:ring-1 focus:ring-amber-500"
          >
            <option value="">All Cameras</option>
            <option v-for="d in store.devices" :key="d.device_id" :value="d.device_id">
              {{ d.name }} ({{ d.device_id }})
            </option>
          </select>
        </div>

        <!-- Date From -->
        <div>
          <label class="block text-[11px] font-medium text-slate-400 mb-1">From Date</label>
          <input 
            type="date" 
            v-model="filters.from" 
            @change="applyFilters"
            class="w-full bg-slate-800/80 border border-slate-700 text-xs rounded-lg px-3 py-2 text-slate-200 focus:outline-none focus:ring-1 focus:ring-amber-500"
          />
        </div>

        <!-- Date To -->
        <div>
          <label class="block text-[11px] font-medium text-slate-400 mb-1">To Date</label>
          <input 
            type="date" 
            v-model="filters.to" 
            @change="applyFilters"
            class="w-full bg-slate-800/80 border border-slate-700 text-xs rounded-lg px-3 py-2 text-slate-200 focus:outline-none focus:ring-1 focus:ring-amber-500"
          />
        </div>

        <!-- Reset Button -->
        <div class="flex items-end">
          <button 
            @click="resetFilters" 
            class="w-full py-2 px-3 bg-slate-800/80 hover:bg-slate-700/80 text-slate-300 text-xs rounded-lg border border-slate-700 transition-colors"
          >
            Clear Filters
          </button>
        </div>
      </div>
    </div>

    <!-- Live Snaps Stream Banner if active -->
    <div v-if="store.strangerSnaps.length > 0 && !isFiltered" class="bg-amber-500/10 border border-amber-500/30 rounded-xl p-4">
      <div class="flex items-center justify-between mb-3">
        <div class="flex items-center gap-2">
          <span class="inline-block w-2 h-2 rounded-full bg-amber-400 animate-ping"></span>
          <h3 class="text-xs font-bold uppercase tracking-wider text-amber-400">
            Live Incoming Stream ({{ store.strangerSnaps.length }} captures this session)
          </h3>
        </div>
        <span class="text-[11px] text-amber-300/80">Real-time MQTT <code class="font-mono">mqtt/face/+/Snap</code></span>
      </div>

      <div class="flex items-center gap-3 overflow-x-auto pb-2 scrollbar-thin">
        <div 
          v-for="snap in store.strangerSnaps.slice(0, 10)" 
          :key="'live-' + (snap.id || snap.captured_at)"
          class="shrink-0 w-36 bg-slate-900/90 border border-amber-500/40 rounded-xl p-2 cursor-pointer hover:border-amber-400 transition-all group"
          @click="openImageModal(snap.snap_pic_url, snap.scene_pic_url, snap.device_id, snap.captured_at, snap.alarm_action)"
        >
          <div class="w-full h-24 rounded-lg bg-slate-800 overflow-hidden relative border border-slate-700">
            <img v-if="snap.snap_pic_url" :src="snap.snap_pic_url" alt="Stranger Crop" class="w-full h-full object-cover group-hover:scale-105 transition-transform" />
            <div v-else class="w-full h-full flex items-center justify-center text-[10px] text-slate-500 font-mono">NO PIC</div>
            <div v-if="snap.is_no_mask === 1" class="absolute bottom-0 right-0 bg-amber-500 text-black text-[8px] px-1 font-bold rounded-tl">NO MASK</div>
          </div>
          <div class="mt-1.5">
            <div class="text-[11px] font-semibold text-amber-300 truncate">Cam: {{ snap.device_id }}</div>
            <div class="text-[10px] text-slate-400 font-mono">{{ formatTime(snap.captured_at) }}</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Main Content: Grid Mode -->
    <div v-if="viewMode === 'grid'">
      <div class="flex items-center justify-between mb-4">
        <h3 class="text-sm font-semibold uppercase tracking-wider text-slate-400 flex items-center gap-2">
          Stranger Snapshot Archive ({{ pagination.total }})
        </h3>
        <span class="text-xs text-slate-500">Page {{ pagination.current_page }} of {{ pagination.last_page || 1 }}</span>
      </div>

      <div v-if="loading" class="bg-slate-900/40 border border-slate-800 rounded-xl p-12 text-center text-slate-500">
        Loading stranger snapshots...
      </div>

      <div v-else-if="snaps.length === 0" class="bg-slate-900/40 border border-dashed border-slate-800 rounded-xl p-12 text-center text-slate-500">
        <div class="text-4xl mb-2">🎭</div>
        <div class="font-medium text-slate-300">No Stranger Captures Found</div>
        <div class="text-xs mt-1 text-slate-500">Stranger face events captured by cameras will be listed here.</div>
      </div>

      <div v-else class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
        <div 
          v-for="snap in snaps" 
          :key="snap.id"
          class="bg-slate-900/90 border border-slate-800 rounded-xl overflow-hidden hover:border-amber-500/50 transition-all flex flex-col justify-between shadow-lg"
        >
          <!-- Image Section -->
          <div class="relative bg-slate-950 h-48 overflow-hidden cursor-pointer group" @click="openImageModal(snap.snap_pic_url, snap.scene_pic_url, snap.device?.name || snap.device_id, snap.captured_at, snap.alarm_action)">
            <img 
              v-if="snap.snap_pic_url" 
              :src="snap.snap_pic_url" 
              alt="Stranger Capture" 
              class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300" 
            />
            <div v-else class="w-full h-full flex items-center justify-center text-xs text-slate-500 font-mono">
              No Snapshot Image
            </div>

            <!-- Overlay Badges -->
            <div class="absolute top-2 left-2 flex items-center gap-1.5">
              <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-amber-500/90 text-slate-950 shadow">
                Stranger
              </span>
              <span v-if="snap.is_no_mask === 1" class="px-1.5 py-0.5 rounded text-[9px] font-bold bg-rose-600 text-white shadow">
                NO MASK
              </span>
            </div>

            <div class="absolute bottom-2 right-2">
              <span v-if="snap.scene_pic_url" class="px-2 py-0.5 rounded-md text-[10px] font-medium bg-slate-900/80 text-slate-200 border border-slate-700 backdrop-blur-sm">
                🔍 Full Scene
              </span>
            </div>
          </div>

          <!-- Info Details -->
          <div class="p-3.5 space-y-2">
            <div class="flex items-center justify-between">
              <div class="text-xs font-semibold text-slate-200 truncate">
                {{ snap.device?.name || ('Camera ' + snap.device_id) }}
              </div>
              <span class="text-[10px] text-slate-400 font-mono">
                ID: {{ snap.device_id }}
              </span>
            </div>

            <div class="text-[11px] text-slate-400 flex items-center justify-between font-mono">
              <span>Time:</span>
              <span class="text-slate-300">{{ formatDateTime(snap.captured_at) }}</span>
            </div>

            <div v-if="snap.alarm_action" class="text-[10px] text-rose-400 bg-rose-500/10 border border-rose-500/20 px-2 py-0.5 rounded truncate">
              ⚠️ {{ snap.alarm_action }}
            </div>

            <div class="pt-2 border-t border-slate-800 flex items-center justify-between gap-2">
              <button 
                @click="openImageModal(snap.snap_pic_url, snap.scene_pic_url, snap.device?.name || snap.device_id, snap.captured_at, snap.alarm_action)"
                class="flex-1 py-1.5 px-2 bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-medium rounded-lg border border-slate-700 transition-colors text-center"
              >
                Inspect
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Main Content: Table Mode -->
    <div v-else class="bg-slate-900/90 border border-slate-800 rounded-xl overflow-hidden shadow-xl">
      <div class="overflow-x-auto">
        <table class="w-full text-left text-xs text-slate-300">
          <thead class="bg-slate-800/60 text-slate-400 uppercase text-[11px] font-semibold border-b border-slate-800">
            <tr>
              <th class="py-3 px-4">Face Crop</th>
              <th class="py-3 px-4">Timestamp</th>
              <th class="py-3 px-4">Camera</th>
              <th class="py-3 px-4">Alarm / Flags</th>
              <th class="py-3 px-4 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-800/60">
            <tr v-if="loading">
              <td colspan="5" class="py-12 text-center text-slate-500">Loading stranger captures...</td>
            </tr>
            <tr v-else-if="snaps.length === 0">
              <td colspan="5" class="py-12 text-center text-slate-500">No stranger snapshots recorded.</td>
            </tr>
            <tr v-for="snap in snaps" :key="snap.id" class="hover:bg-slate-800/30 transition-colors">
              <td class="py-3 px-4">
                <div 
                  class="w-12 h-12 rounded-lg bg-slate-800 border border-slate-700 overflow-hidden shrink-0 cursor-pointer" 
                  @click="openImageModal(snap.snap_pic_url, snap.scene_pic_url, snap.device?.name || snap.device_id, snap.captured_at, snap.alarm_action)"
                >
                  <img v-if="snap.snap_pic_url" :src="snap.snap_pic_url" class="w-full h-full object-cover" />
                  <span v-else class="w-full h-full flex items-center justify-center text-[10px] text-slate-500">No Pic</span>
                </div>
              </td>
              <td class="py-3 px-4 font-mono text-slate-300">{{ formatDateTime(snap.captured_at) }}</td>
              <td class="py-3 px-4">
                <div class="font-semibold text-slate-100">{{ snap.device?.name || 'Camera' }}</div>
                <div class="text-[11px] text-slate-500 font-mono">{{ snap.device_id }}</div>
              </td>
              <td class="py-3 px-4">
                <div class="flex items-center gap-1.5 flex-wrap">
                  <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase tracking-wider bg-amber-500/10 text-amber-400 border border-amber-500/30">
                    Stranger
                  </span>
                  <span v-if="snap.is_no_mask === 1" class="px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase tracking-wider bg-rose-500/10 text-rose-400 border border-rose-500/30">
                    No Mask
                  </span>
                  <span v-if="snap.alarm_action" class="text-[11px] text-slate-400">
                    {{ snap.alarm_action }}
                  </span>
                </div>
              </td>
              <td class="py-3 px-4 text-right">
                <button 
                  @click="openImageModal(snap.snap_pic_url, snap.scene_pic_url, snap.device?.name || snap.device_id, snap.captured_at, snap.alarm_action)" 
                  class="text-xs text-indigo-400 hover:text-indigo-300 px-2 py-1 rounded bg-slate-800/60 border border-slate-700"
                >
                  Inspect
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Pagination Controls -->
    <div v-if="pagination.total > pagination.per_page" class="flex items-center justify-between bg-slate-900/80 border border-slate-800 px-4 py-3 rounded-xl text-xs text-slate-400">
      <div>
        Showing {{ (pagination.current_page - 1) * pagination.per_page + 1 }} to 
        {{ Math.min(pagination.current_page * pagination.per_page, pagination.total) }} of {{ pagination.total }} captures
      </div>
      <div class="flex items-center gap-2">
        <button 
          :disabled="pagination.current_page === 1" 
          @click="goToPage(pagination.current_page - 1)" 
          class="px-3 py-1 bg-slate-800 hover:bg-slate-700 disabled:opacity-40 disabled:hover:bg-slate-800 text-slate-200 rounded-lg border border-slate-700 transition-colors"
        >
          Previous
        </button>
        <span class="px-2 font-mono text-slate-300">{{ pagination.current_page }} / {{ pagination.last_page }}</span>
        <button 
          :disabled="pagination.current_page === pagination.last_page" 
          @click="goToPage(pagination.current_page + 1)" 
          class="px-3 py-1 bg-slate-800 hover:bg-slate-700 disabled:opacity-40 disabled:hover:bg-slate-800 text-slate-200 rounded-lg border border-slate-700 transition-colors"
        >
          Next
        </button>
      </div>
    </div>

    <!-- Image Inspection Modal -->
    <div v-if="modal.show" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm" @click.self="modal.show = false">
      <div class="bg-slate-900 border border-slate-700 rounded-2xl max-w-3xl w-full p-6 space-y-4 max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
          <div>
            <h3 class="text-base font-semibold text-slate-100 flex items-center gap-2">
              <span>🎭 Stranger Detection Snapshot</span>
              <span class="text-xs px-2 py-0.5 rounded bg-amber-500/10 text-amber-400 border border-amber-500/30">Unregistered Face</span>
            </h3>
            <p class="text-xs text-slate-400 mt-0.5">Camera: <strong class="text-slate-200">{{ modal.cameraName }}</strong> &bull; Time: <span class="font-mono text-slate-300">{{ formatDateTime(modal.capturedAt) }}</span></p>
          </div>
          <button @click="modal.show = false" class="text-slate-400 hover:text-white text-xl font-bold">&times;</button>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div v-if="modal.snapUrl" class="space-y-2">
            <div class="text-xs font-semibold text-slate-400 flex items-center justify-between">
              <span>Biometric Face Crop</span>
              <a :href="modal.snapUrl" target="_blank" download class="text-[11px] text-indigo-400 hover:underline">Download</a>
            </div>
            <img :src="modal.snapUrl" class="rounded-xl border border-slate-700 w-full max-h-80 object-contain bg-black" />
          </div>
          <div v-if="modal.sceneUrl" class="space-y-2">
            <div class="text-xs font-semibold text-slate-400 flex items-center justify-between">
              <span>Context Scene View</span>
              <a :href="modal.sceneUrl" target="_blank" download class="text-[11px] text-indigo-400 hover:underline">Download</a>
            </div>
            <img :src="modal.sceneUrl" class="rounded-xl border border-slate-700 w-full max-h-80 object-contain bg-black" />
          </div>
        </div>

        <div v-if="modal.alarmAction" class="bg-rose-500/10 border border-rose-500/20 p-3 rounded-xl text-xs text-rose-300">
          <strong>Alarm Action:</strong> {{ modal.alarmAction }}
        </div>

        <div class="flex justify-end pt-2">
          <button @click="modal.show = false" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-medium rounded-lg">
            Close
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useCameraStore } from '../stores/cameraStore';
import { formatTime, formatDateTime } from '../utils/date';
import axios from 'axios';

const store = useCameraStore();
const viewMode = ref('grid');
const loading = ref(false);
const snaps = ref([]);

const filters = ref({
  deviceId: '',
  from: '',
  to: '',
});

const pagination = ref({
  current_page: 1,
  last_page: 1,
  per_page: 20,
  total: 0,
});

const modal = ref({
  show: false,
  snapUrl: '',
  sceneUrl: '',
  cameraName: '',
  capturedAt: null,
  alarmAction: '',
});

const isFiltered = computed(() => {
  return !!(filters.value.deviceId || filters.value.from || filters.value.to);
});

async function fetchSnaps(page = 1) {
  loading.value = true;
  try {
    const params = {
      page,
      per_page: 20,
    };
    if (filters.value.deviceId) params.device_id = filters.value.deviceId;
    if (filters.value.from) params.from = filters.value.from;
    if (filters.value.to) params.to = filters.value.to;

    const res = await axios.get('/api/stranger-snaps', { params });
    snaps.value = res.data.data || [];
    pagination.value = {
      current_page: res.data.current_page || 1,
      last_page: res.data.last_page || 1,
      per_page: res.data.per_page || 20,
      total: res.data.total || 0,
    };
  } catch (err) {
    console.error('Failed to fetch stranger snaps:', err);
  } finally {
    loading.value = false;
  }
}

function applyFilters() {
  fetchSnaps(1);
}

function resetFilters() {
  filters.value = {
    deviceId: '',
    from: '',
    to: '',
  };
  fetchSnaps(1);
}

function goToPage(page) {
  if (page >= 1 && page <= pagination.value.last_page) {
    fetchSnaps(page);
  }
}

function openImageModal(snapUrl, sceneUrl, cameraName, capturedAt, alarmAction) {
  modal.value = {
    show: true,
    snapUrl,
    sceneUrl,
    cameraName: cameraName || 'Camera Device',
    capturedAt,
    alarmAction: alarmAction || '',
  };
}

onMounted(() => {
  fetchSnaps(1);
});
</script>
