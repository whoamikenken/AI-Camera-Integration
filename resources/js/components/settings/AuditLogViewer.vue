<template>
  <div class="space-y-4">
    <!-- Top Filter Bar -->
    <div class="flex flex-col sm:flex-row items-center gap-3 bg-white border border-slate-200 p-3 rounded-xl shadow-xs">
      <div class="relative flex-1 w-full">
        <input
          v-model="search"
          @input="debouncedFetch"
          type="text"
          placeholder="Search by actor, IP address, or entity ID..."
          class="w-full bg-white border border-slate-200 rounded-lg pl-9 pr-4 py-2 text-xs text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs"
        />
        <span class="absolute left-3 top-2.5 text-slate-400 text-xs">🔍</span>
      </div>

      <select v-model="actionFilter" @change="fetchLogs(1)" class="w-full sm:w-40 bg-white border border-slate-200 text-xs rounded-lg px-3 py-2 text-slate-700 cursor-pointer shadow-xs">
        <option value="">All Actions</option>
        <option value="create">Created</option>
        <option value="update">Updated</option>
        <option value="delete">Deleted</option>
        <option value="login">Login</option>
        <option value="logout">Logout</option>
      </select>

      <select v-model="entityFilter" @change="fetchLogs(1)" class="w-full sm:w-44 bg-white border border-slate-200 text-xs rounded-lg px-3 py-2 text-slate-700 cursor-pointer shadow-xs">
        <option value="">All Entities</option>
        <option value="Department">Department</option>
        <option value="Designation">Designation</option>
        <option value="Location">Location</option>
        <option value="Device">Device</option>
        <option value="Personnel">Personnel</option>
        <option value="Setting">Setting</option>
        <option value="User">User</option>
      </select>

      <button
        @click="fetchLogs(1)"
        class="px-3 py-2 bg-white hover:bg-slate-50 text-slate-700 text-xs font-semibold border border-slate-200 rounded-lg shadow-xs transition-colors cursor-pointer"
        title="Refresh logs"
      >
        🔄
      </button>
    </div>

    <!-- Data Table -->
    <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-xs">
      <div class="overflow-x-auto">
        <table class="w-full text-left text-xs text-slate-700">
          <thead class="bg-slate-50 text-slate-500 uppercase text-[11px] font-semibold border-b border-slate-200">
            <tr>
              <th class="py-3 px-4">Timestamp (PHT)</th>
              <th class="py-3 px-4">Actor</th>
              <th class="py-3 px-4">Action</th>
              <th class="py-3 px-4">Target Entity</th>
              <th class="py-3 px-4">IP Address</th>
              <th class="py-3 px-4 text-right">Details</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-if="loading">
              <td colspan="6" class="py-12 text-center text-slate-500">Loading audit trail records...</td>
            </tr>
            <tr v-else-if="logs.length === 0">
              <td colspan="6" class="py-12 text-center text-slate-500">No audit records match the selected criteria.</td>
            </tr>
            <tr v-for="log in logs" :key="log.id" class="hover:bg-slate-50 transition-colors">
              <td class="py-3 px-4 font-mono text-slate-600">{{ formatTimestamp(log.created_at) }}</td>
              <td class="py-3 px-4">
                <div class="font-bold text-slate-900">{{ log.user?.name || log.user_name || 'System / Auto' }}</div>
                <div class="text-[10px] text-slate-400 font-mono">{{ log.user?.email || '' }}</div>
              </td>
              <td class="py-3 px-4">
                <span
                  class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider"
                  :class="actionBadgeClass(log.action)"
                >
                  {{ log.action }}
                </span>
              </td>
              <td class="py-3 px-4">
                <div class="font-medium text-slate-800">{{ cleanEntityName(log.auditable_type) }}</div>
                <div class="text-[10px] font-mono text-slate-400">ID: #{{ log.auditable_id || 'N/A' }}</div>
              </td>
              <td class="py-3 px-4 font-mono text-[11px] text-slate-500">{{ log.ip_address || '127.0.0.1' }}</td>
              <td class="py-3 px-4 text-right">
                <button
                  @click="inspectDiff(log)"
                  class="px-2.5 py-1 text-[11px] bg-white hover:bg-slate-50 text-indigo-600 font-semibold border border-slate-200 rounded transition-colors shadow-xs cursor-pointer"
                >
                  Inspect Diff
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div class="p-3 bg-slate-50 border-t border-slate-200 flex items-center justify-between text-xs text-slate-500">
        <div>Showing Page {{ pagination.current_page }} of {{ pagination.last_page }} ({{ pagination.total }} records)</div>
        <div class="flex items-center gap-1">
          <button
            @click="fetchLogs(pagination.current_page - 1)"
            :disabled="pagination.current_page <= 1"
            class="px-2.5 py-1 bg-white border border-slate-200 rounded disabled:opacity-40 cursor-pointer shadow-xs"
          >
            Previous
          </button>
          <button
            @click="fetchLogs(pagination.current_page + 1)"
            :disabled="pagination.current_page >= pagination.last_page"
            class="px-2.5 py-1 bg-white border border-slate-200 rounded disabled:opacity-40 cursor-pointer shadow-xs"
          >
            Next
          </button>
        </div>
      </div>
    </div>

    <!-- DIFF MODAL -->
    <div v-if="selectedLog" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
      <div class="bg-white rounded-2xl border border-slate-200 max-w-2xl w-full p-6 shadow-2xl max-h-[85vh] flex flex-col space-y-4">
        <div class="flex items-start justify-between border-b border-slate-100 pb-3">
          <div>
            <h3 class="text-base font-bold text-slate-900">Audit Log Details &amp; Change Diff</h3>
            <p class="text-xs text-slate-500 font-mono">{{ formatTimestamp(selectedLog.created_at) }} &bull; {{ selectedLog.user?.name || 'System' }}</p>
          </div>
          <button @click="selectedLog = null" class="text-slate-400 hover:text-slate-600 text-lg font-bold cursor-pointer">&times;</button>
        </div>

        <div class="flex-1 overflow-y-auto space-y-4 pr-1 text-xs">
          <!-- Metadata -->
          <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 bg-slate-50 p-3 rounded-xl border border-slate-200/80">
            <div>
              <div class="text-[10px] text-slate-400 uppercase font-bold">Action</div>
              <div class="font-bold text-slate-800 capitalize">{{ selectedLog.action }}</div>
            </div>
            <div>
              <div class="text-[10px] text-slate-400 uppercase font-bold">Entity</div>
              <div class="font-bold text-slate-800">{{ cleanEntityName(selectedLog.auditable_type) }} #{{ selectedLog.auditable_id }}</div>
            </div>
            <div>
              <div class="text-[10px] text-slate-400 uppercase font-bold">IP Address</div>
              <div class="font-mono text-slate-800">{{ selectedLog.ip_address }}</div>
            </div>
            <div>
              <div class="text-[10px] text-slate-400 uppercase font-bold">User Agent</div>
              <div class="truncate text-slate-600" :title="selectedLog.user_agent">{{ selectedLog.user_agent || '--' }}</div>
            </div>
          </div>

          <!-- Diff Values -->
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <h4 class="text-xs font-bold text-rose-700 mb-1.5 flex items-center gap-1">
                <span>◀</span> Previous State (Old Values)
              </h4>
              <pre class="bg-rose-50/50 border border-rose-200 rounded-lg p-3 font-mono text-[11px] text-slate-800 whitespace-pre-wrap max-h-60 overflow-y-auto">{{ formatJson(selectedLog.old_values) }}</pre>
            </div>

            <div>
              <h4 class="text-xs font-bold text-emerald-700 mb-1.5 flex items-center gap-1">
                <span>▶</span> New State (New Values)
              </h4>
              <pre class="bg-emerald-50/50 border border-emerald-200 rounded-lg p-3 font-mono text-[11px] text-slate-800 whitespace-pre-wrap max-h-60 overflow-y-auto">{{ formatJson(selectedLog.new_values) }}</pre>
            </div>
          </div>
        </div>

        <div class="pt-3 border-t border-slate-100 flex justify-end">
          <button @click="selectedLog = null" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold rounded-lg cursor-pointer">
            Close
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import apiClient from '../../api/client';

const search = ref('');
const actionFilter = ref('');
const entityFilter = ref('');
const loading = ref(false);
const logs = ref([]);
const selectedLog = ref(null);

const pagination = ref({
  current_page: 1,
  last_page: 1,
  total: 0,
});

let debounceTimer = null;
const debouncedFetch = () => {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(() => fetchLogs(1), 300);
};

const fetchLogs = async (page = 1) => {
  loading.value = true;
  try {
    const res = await apiClient.get('/audit-logs', {
      params: {
        page,
        per_page: 20,
        search: search.value || undefined,
        action: actionFilter.value || undefined,
        auditable_type: entityFilter.value || undefined,
      }
    });

    const data = res.data;
    logs.value = data.data || [];
    pagination.value = {
      current_page: data.current_page || 1,
      last_page: data.last_page || 1,
      total: data.total || 0,
    };
  } catch (err) {
    console.error('Audit logs error', err);
  } finally {
    loading.value = false;
  }
};

const inspectDiff = (log) => {
  selectedLog.value = log;
};

const formatTimestamp = (ts) => {
  if (!ts) return '--';
  return new Date(ts).toLocaleString('en-PH', { timeZone: 'Asia/Manila' });
};

const cleanEntityName = (type) => {
  if (!type) return 'System';
  return type.replace(/^App\\Models\\/, '');
};

const formatJson = (val) => {
  if (!val) return 'None';
  if (typeof val === 'string') {
    try {
      return JSON.stringify(JSON.parse(val), null, 2);
    } catch {
      return val;
    }
  }
  return JSON.stringify(val, null, 2);
};

const actionBadgeClass = (action) => {
  switch (action) {
    case 'create':
    case 'created':
    case 'approved':
      return 'bg-emerald-50 text-emerald-700 border border-emerald-200';
    case 'update':
    case 'updated':
      return 'bg-indigo-50 text-indigo-700 border border-indigo-200';
    case 'delete':
    case 'deleted':
    case 'rejected':
      return 'bg-rose-50 text-rose-700 border border-rose-200';
    case 'login':
    case 'logout':
      return 'bg-amber-50 text-amber-700 border border-amber-200';
    default:
      return 'bg-slate-100 text-slate-700 border border-slate-200';
  }
};

onMounted(() => {
  fetchLogs();
});
</script>
