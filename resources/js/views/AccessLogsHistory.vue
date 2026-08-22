<template>
  <div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 bg-slate-900/80 border border-slate-800 p-4 rounded-xl backdrop-blur-md">
      <div>
        <h2 class="text-lg font-semibold text-slate-100">Access Telemetry & Audit Logs</h2>
        <p class="text-xs text-slate-400">Search and filter historical facial recognition verification events, admission statuses, and match similarities</p>
      </div>
      <button @click="fetchLogs" class="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 rounded-lg text-xs font-medium">
        🔄 Refresh Logs
      </button>
    </div>

    <!-- Filters -->
    <div class="grid grid-cols-1 sm:grid-cols-4 gap-3 bg-slate-900/60 border border-slate-800 p-3 rounded-xl">
      <input 
        v-model="filters.search" 
        @input="debouncedFetch"
        type="text" 
        placeholder="Search person name or custom ID..."
        class="bg-slate-800/80 border border-slate-700 rounded-lg px-3 py-2 text-xs text-slate-200 placeholder-slate-500"
      />

      <select v-model="filters.status" @change="fetchLogs" class="bg-slate-800/80 border border-slate-700 text-xs rounded-lg px-3 py-2 text-slate-200">
        <option value="">All Verification Statuses</option>
        <option value="1">Allowed (Whitelisted)</option>
        <option value="2">Rejected / Denied</option>
        <option value="3">Not Registered</option>
      </select>

      <select v-model="filters.deviceId" @change="fetchLogs" class="bg-slate-800/80 border border-slate-700 text-xs rounded-lg px-3 py-2 text-slate-200">
        <option value="">All Cameras</option>
        <option v-for="d in store.devices" :key="d.device_id" :value="d.device_id">{{ d.name }} ({{ d.device_id }})</option>
      </select>

      <input 
        v-model.number="filters.minSimilarity" 
        @change="fetchLogs"
        type="number" 
        min="0" 
        max="100" 
        placeholder="Min Match % (e.g. 80)" 
        class="bg-slate-800/80 border border-slate-700 rounded-lg px-3 py-2 text-xs text-slate-200"
      />
    </div>

    <!-- Table -->
    <div class="bg-slate-900/90 border border-slate-800 rounded-xl overflow-hidden shadow-xl">
      <div class="overflow-x-auto">
        <table class="w-full text-left text-xs text-slate-300">
          <thead class="bg-slate-800/60 text-slate-400 uppercase text-[11px] font-semibold border-b border-slate-800">
            <tr>
              <th class="py-3 px-4">Face</th>
              <th class="py-3 px-4">Timestamp</th>
              <th class="py-3 px-4">Camera</th>
              <th class="py-3 px-4">Person Details</th>
              <th class="py-3 px-4">Match %</th>
              <th class="py-3 px-4">Status</th>
              <th class="py-3 px-4 text-right">Scene</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-800/60">
            <tr v-if="loading">
              <td colspan="7" class="py-12 text-center text-slate-500">Loading access logs...</td>
            </tr>
            <tr v-else-if="logs.length === 0">
              <td colspan="7" class="py-12 text-center text-slate-500">No access logs matching filter criteria.</td>
            </tr>
            <tr v-for="log in logs" :key="log.id" class="hover:bg-slate-800/30 transition-colors">
              <td class="py-3 px-4">
                <div class="w-10 h-10 rounded-lg bg-slate-800 border border-slate-700 overflow-hidden shrink-0 cursor-pointer" @click="openImage(log.snap_pic_url, log.scene_pic_url, log.person_name)">
                  <img v-if="log.snap_pic_url" :src="log.snap_pic_url" class="w-full h-full object-cover" />
                  <span v-else class="w-full h-full flex items-center justify-center text-[10px] text-slate-500">No Pic</span>
                </div>
              </td>
              <td class="py-3 px-4 font-mono text-slate-300">{{ formatDateTime(log.captured_at) }}</td>
              <td class="py-3 px-4 font-mono text-slate-300">{{ log.device?.name || log.device_id }}</td>
              <td class="py-3 px-4">
                <div class="font-semibold text-slate-100">{{ log.person_name || 'Unregistered' }}</div>
                <div class="text-[11px] text-slate-500 font-mono">ID: {{ log.customize_id || '--' }}</div>
              </td>
              <td class="py-3 px-4 font-mono">
                <span v-if="log.similarity" :class="log.similarity >= 80 ? 'text-emerald-400 font-bold' : 'text-amber-400'">
                  {{ log.similarity }}%
                </span>
                <span v-else class="text-slate-600">--</span>
              </td>
              <td class="py-3 px-4">
                <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase tracking-wider" :class="getStatusBadgeClass(log.verify_status)">
                  {{ getStatusText(log.verify_status) }}
                </span>
              </td>
              <td class="py-3 px-4 text-right">
                <button v-if="log.scene_pic_url" @click="openImage(log.snap_pic_url, log.scene_pic_url, log.person_name)" class="text-xs text-indigo-400 hover:text-indigo-300">
                  Inspect
                </button>
                <span v-else class="text-slate-600 text-xs">None</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div v-if="pagination.total > pagination.per_page" class="p-3 border-t border-slate-800 flex items-center justify-between text-xs text-slate-400">
        <div>Showing {{ pagination.from }} to {{ pagination.to }} of {{ pagination.total }} entries</div>
        <div class="flex items-center gap-1">
          <button @click="changePage(pagination.current_page - 1)" :disabled="pagination.current_page === 1" class="px-3 py-1 bg-slate-800 rounded border border-slate-700 disabled:opacity-50">Prev</button>
          <span class="px-2">{{ pagination.current_page }} / {{ pagination.last_page }}</span>
          <button @click="changePage(pagination.current_page + 1)" :disabled="pagination.current_page === pagination.last_page" class="px-3 py-1 bg-slate-800 rounded border border-slate-700 disabled:opacity-50">Next</button>
        </div>
      </div>
    </div>

    <!-- Modal -->
    <div v-if="modal.show" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm" @click.self="modal.show = false">
      <div class="bg-slate-900 border border-slate-700 rounded-2xl max-w-3xl w-full p-6 space-y-4">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
          <h3 class="text-base font-semibold text-slate-100">{{ modal.title }} - Snapshot Inspection</h3>
          <button @click="modal.show = false" class="text-slate-400 hover:text-white text-lg font-bold">&times;</button>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div v-if="modal.snapUrl">
            <div class="text-xs font-semibold text-slate-400 mb-1">Face Crop</div>
            <img :src="modal.snapUrl" class="rounded-xl border border-slate-700 w-full max-h-72 object-contain bg-black" />
          </div>
          <div v-if="modal.sceneUrl">
            <div class="text-xs font-semibold text-slate-400 mb-1">Scene View</div>
            <img :src="modal.sceneUrl" class="rounded-xl border border-slate-700 w-full max-h-72 object-contain bg-black" />
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useCameraStore } from '../stores/cameraStore';
import axios from 'axios';

const store = useCameraStore();
const logs = ref([]);
const loading = ref(false);

const filters = ref({
  search: '',
  status: '',
  deviceId: '',
  minSimilarity: '',
});

const pagination = ref({
  current_page: 1,
  last_page: 1,
  total: 0,
  per_page: 20,
  from: 0,
  to: 0,
});

const modal = ref({ show: false, snapUrl: '', sceneUrl: '', title: '' });

async function fetchLogs(page = 1) {
  loading.value = true;
  try {
    const params = {
      page,
      search: filters.value.search,
      verify_status: filters.value.status,
      device_id: filters.value.deviceId,
      min_similarity: filters.value.minSimilarity,
    };
    const res = await axios.get('/api/access-logs', { params });
    logs.value = res.data.data;
    pagination.value = {
      current_page: res.data.current_page,
      last_page: res.data.last_page,
      total: res.data.total,
      per_page: res.data.per_page,
      from: res.data.from,
      to: res.data.to,
    };
  } catch (err) {
    console.error('Failed to load logs:', err);
  } finally {
    loading.value = false;
  }
}

let debounceTimer = null;
function debouncedFetch() {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(() => fetchLogs(1), 300);
}

function changePage(page) {
  if (page >= 1 && page <= pagination.value.last_page) {
    fetchLogs(page);
  }
}

function formatDateTime(str) {
  if (!str) return '--';
  return new Date(str).toLocaleString();
}

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
    case 1: return 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20';
    case 2: return 'bg-rose-500/10 text-rose-400 border border-rose-500/20';
    case 3: return 'bg-amber-500/10 text-amber-400 border border-amber-500/20';
    default: return 'bg-slate-800 text-slate-400 border border-slate-700';
  }
}

function openImage(snapUrl, sceneUrl, title) {
  modal.value = { show: true, snapUrl, sceneUrl, title: title || 'Biometric Capture' };
}

onMounted(() => {
  fetchLogs();
  store.fetchDevices();
});
</script>
