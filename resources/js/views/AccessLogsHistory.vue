<template>
  <div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 bg-white border border-slate-200/80 p-4 rounded-xl shadow-xs">
      <div>
        <h2 class="text-lg font-bold text-slate-900">Access Telemetry & Audit Logs</h2>
        <p class="text-xs text-slate-500">Search and filter historical facial recognition verification events, admission statuses, and match similarities</p>
      </div>
      <div class="flex items-center gap-2">
        <button @click="showBackfillModal = true" class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white border border-indigo-600 rounded-lg text-xs font-semibold shadow-xs flex items-center gap-1.5 cursor-pointer transition-colors">
          <span>📥</span> Historical Backfill
        </button>
        <button @click="fetchLogs" class="px-3 py-1.5 bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 rounded-lg text-xs font-semibold shadow-xs cursor-pointer">
          🔄 Refresh Logs
        </button>
      </div>
    </div>

    <!-- Filters -->
    <div class="grid grid-cols-1 sm:grid-cols-4 gap-3 bg-white border border-slate-200 p-3 rounded-xl shadow-xs">
      <input 
        aria-label="Search by person name or custom ID"
        v-model="filters.search" 
        @input="debouncedFetch"
        type="text" 
        placeholder="Search person name or custom ID..."
        class="bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs"
      />

      <select aria-label="Filter by verification status" v-model="filters.status" @change="fetchLogs" class="bg-white border border-slate-200 text-xs rounded-lg px-3 py-2 text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 cursor-pointer shadow-xs">
        <option value="">All Verification Statuses</option>
        <option value="1">Allowed (Whitelisted)</option>
        <option value="2">Rejected / Denied</option>
        <option value="3">Not Registered</option>
      </select>

      <select aria-label="Filter by camera device" v-model="filters.deviceId" @change="fetchLogs" class="bg-white border border-slate-200 text-xs rounded-lg px-3 py-2 text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 cursor-pointer shadow-xs">
        <option value="">All Cameras</option>
        <option v-for="d in store.devices" :key="d.device_id" :value="d.device_id">{{ d.name }} ({{ d.device_id }})</option>
      </select>

      <input 
        aria-label="Minimum match percentage"
        v-model.number="filters.minSimilarity" 
        @change="fetchLogs"
        type="number" 
        min="0" 
        max="100" 
        placeholder="Min Match % (e.g. 80)" 
        class="bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs"
      />
    </div>

    <!-- Table -->
    <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-xs">
      <div class="overflow-x-auto">
        <table class="w-full text-left text-xs text-slate-700">
          <thead class="bg-slate-50 text-slate-500 uppercase text-[11px] font-semibold border-b border-slate-200">
            <tr>
              <th scope="col" class="py-3 px-4">Face</th>
              <th scope="col" class="py-3 px-4">Timestamp</th>
              <th scope="col" class="py-3 px-4">Camera</th>
              <th scope="col" class="py-3 px-4">Person Details</th>
              <th scope="col" class="py-3 px-4">Match %</th>
              <th scope="col" class="py-3 px-4">Status</th>
              <th scope="col" class="py-3 px-4 text-right">Scene</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-if="loading" v-for="i in 6" :key="'skel-row-' + i" class="animate-pulse">
              <td class="py-3 px-4"><div class="w-10 h-10 bg-slate-200 rounded-lg"></div></td>
              <td class="py-3 px-4"><div class="h-4 bg-slate-200 rounded w-28"></div></td>
              <td class="py-3 px-4"><div class="h-4 bg-slate-200 rounded w-24"></div></td>
              <td class="py-3 px-4"><div class="h-4 bg-slate-200 rounded w-32 mb-1"></div><div class="h-3 bg-slate-200 rounded w-16"></div></td>
              <td class="py-3 px-4"><div class="h-4 bg-slate-200 rounded w-10"></div></td>
              <td class="py-3 px-4"><div class="h-4 bg-slate-200 rounded-full w-20"></div></td>
              <td class="py-3 px-4 text-right"><div class="h-4 bg-slate-200 rounded w-12 ml-auto"></div></td>
            </tr>
            <tr v-else-if="logs.length === 0">
              <td colspan="7" class="py-12 text-center text-slate-500">No access logs matching filter criteria.</td>
            </tr>
            <tr v-for="log in logs" :key="log.id" class="hover:bg-slate-50 transition-colors">
              <td class="py-3 px-4">
                <div role="button" tabindex="0" :aria-label="'Inspect face snapshot for ' + (log.person_name || 'Unregistered')" class="w-10 h-10 rounded-lg bg-slate-100 border border-slate-200 overflow-hidden shrink-0 cursor-pointer" @click="openImage(log.snap_pic_url, log.scene_pic_url, log.person_name)" @keydown.enter="openImage(log.snap_pic_url, log.scene_pic_url, log.person_name)">
                  <img v-if="log.snap_pic_url" :src="formatMediaUrl(log.snap_pic_url)" class="w-full h-full object-cover" />
                  <span v-else class="w-full h-full flex items-center justify-center text-[10px] text-slate-400">No Pic</span>
                </div>
              </td>
              <td class="py-3 px-4 font-mono text-slate-700 font-medium">{{ formatDateTime(log.captured_at) }}</td>
              <td class="py-3 px-4 font-mono text-slate-700 font-medium">{{ log.device?.name || log.device_id }}</td>
              <td class="py-3 px-4">
                <div class="font-bold text-slate-900">{{ log.person_name || 'Unregistered' }}</div>
                <div class="text-[11px] text-slate-500 font-mono">ID: {{ log.customize_id || '--' }}</div>
              </td>
              <td class="py-3 px-4 font-mono">
                <span v-if="log.similarity" :class="log.similarity >= 80 ? 'text-emerald-700 font-bold' : 'text-amber-700 font-bold'">
                  {{ log.similarity }}%
                </span>
                <span v-else class="text-slate-400">--</span>
              </td>
              <td class="py-3 px-4">
                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider" :class="getStatusBadgeClass(log.verify_status)">
                  {{ getStatusText(log.verify_status) }}
                </span>
              </td>
              <td class="py-3 px-4 text-right">
                <button v-if="log.scene_pic_url" @click="openImage(log.snap_pic_url, log.scene_pic_url, log.person_name)" class="text-xs text-indigo-600 hover:text-indigo-800 font-semibold cursor-pointer">
                  Inspect
                </button>
                <span v-else class="text-slate-400 text-xs">None</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div v-if="pagination.total > pagination.per_page" class="p-3 border-t border-slate-200 flex items-center justify-between text-xs text-slate-600">
        <div>Showing {{ pagination.from }} to {{ pagination.to }} of {{ pagination.total }} entries</div>
        <div class="flex items-center gap-1">
          <button @click="changePage(pagination.current_page - 1)" :disabled="pagination.current_page === 1" class="px-3 py-1 bg-white hover:bg-slate-50 rounded border border-slate-200 disabled:opacity-50 text-slate-700 font-medium shadow-xs cursor-pointer">Prev</button>
          <span class="px-2 font-mono text-slate-700 font-medium">{{ pagination.current_page }} / {{ pagination.last_page }}</span>
          <button @click="changePage(pagination.current_page + 1)" :disabled="pagination.current_page === pagination.last_page" class="px-3 py-1 bg-white hover:bg-slate-50 rounded border border-slate-200 disabled:opacity-50 text-slate-700 font-medium shadow-xs cursor-pointer">Next</button>
        </div>
      </div>
    </div>

    <!-- Modal -->
    <div v-if="modal.show" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm" @click.self="modal.show = false" @keydown.escape="modal.show = false">
      <div role="dialog" aria-modal="true" aria-labelledby="access-log-modal-title" class="bg-white border border-slate-200 rounded-2xl max-w-3xl w-full p-6 space-y-4 shadow-2xl">
        <div class="flex items-center justify-between border-b border-slate-200 pb-3">
          <h3 id="access-log-modal-title" class="text-base font-bold text-slate-900">{{ modal.title }} - Snapshot Inspection</h3>
          <button @click="modal.show = false" aria-label="Close dialog" class="text-slate-400 hover:text-slate-700 text-lg font-bold cursor-pointer">&times;</button>
        </div>
        <div class="grid grid-cols-1">
          <div v-if="modal.snapUrl">
            <div class="text-xs font-semibold text-slate-500 mb-1">Face Crop</div>
            <img :src="formatMediaUrl(modal.snapUrl)" class="rounded-xl border border-slate-200 w-full max-h-72 object-contain bg-slate-950" />
          </div>
          <div v-if="modal.sceneUrl">
            <div class="text-xs font-semibold text-slate-500 mb-1">Scene View</div>
            <img :src="formatMediaUrl(modal.sceneUrl)" class="rounded-xl border border-slate-200 w-full max-h-72 object-contain bg-slate-950" />
          </div>
        </div>
      </div>
    </div>

    <!-- Historical Backfill Modal -->
    <HistoricalBackfillModal
      :is-open="showBackfillModal"
      @close="showBackfillModal = false"
      @success="fetchLogs"
    />
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useCameraStore } from '../stores/cameraStore';
import { formatDateTime } from '../utils/date';
import formatMediaUrl from '../utils/media';
import HistoricalBackfillModal from '../components/HistoricalBackfillModal.vue';
import apiClient from '../api/client';

const store = useCameraStore();
const logs = ref([]);
const loading = ref(false);
const showBackfillModal = ref(false);

const filters = ref({
  search: '',
  status: '',
  deviceId: '',
  minSimilarity: ''
});

const pagination = ref({
  current_page: 1,
  last_page: 1,
  total: 0,
  per_page: 15,
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
    const res = await apiClient.get('/api/access-logs', { params });
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

function openImage(snapUrl, sceneUrl, title) {
  modal.value = { show: true, snapUrl, sceneUrl, title: title || 'Biometric Capture' };
}

onMounted(() => {
  fetchLogs();
  store.fetchDevices();
});
</script>
