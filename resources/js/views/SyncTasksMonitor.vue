<template>
  <div class="space-y-6">
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 bg-white border border-slate-200 p-4 rounded-xl shadow-sm">
      <div>
        <h2 class="text-lg font-semibold text-slate-900">Edge Device Sync Outbox Queue</h2>
        <p class="text-xs text-slate-500">Monitor background Redis job queue state for personnel biometric provisioning to LAN edge cameras</p>
      </div>
      <button @click="fetchTasks" class="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 rounded-lg text-xs font-medium transition-colors">
        🔄 Refresh Tasks
      </button>
    </div>

    <!-- Filter Bar -->
    <div class="flex items-center gap-3 bg-white border border-slate-200 p-3 rounded-xl shadow-sm">
      <select v-model="statusFilter" @change="fetchTasks" class="bg-slate-50 border border-slate-200 text-xs rounded-lg px-3 py-2 text-slate-700">
        <option value="">All Task Statuses</option>
        <option value="PENDING">PENDING</option>
        <option value="PROCESSING">PROCESSING</option>
        <option value="COMPLETED">COMPLETED</option>
        <option value="FAILED">FAILED</option>
      </select>
    </div>

    <!-- Tasks Table -->
    <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
      <div class="overflow-x-auto">
        <table class="w-full text-left text-xs text-slate-700">
          <thead class="bg-slate-50 text-slate-500 uppercase text-[11px] font-semibold border-b border-slate-200">
            <tr>
              <th class="py-3 px-4">Task ID</th>
              <th class="py-3 px-4">Target Camera</th>
              <th class="py-3 px-4">Personnel</th>
              <th class="py-3 px-4">Action</th>
              <th class="py-3 px-4">Status</th>
              <th class="py-3 px-4">Attempts</th>
              <th class="py-3 px-4">Last Updated</th>
              <th class="py-3 px-4 text-right">Action</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-if="loading">
              <td colspan="8" class="py-12 text-center text-slate-400">Loading sync tasks...</td>
            </tr>
            <tr v-else-if="tasks.length === 0">
              <td colspan="8" class="py-12 text-center text-slate-400">No sync tasks recorded.</td>
            </tr>
            <tr v-for="task in tasks" :key="task.id" class="hover:bg-slate-50/80 transition-colors">
              <td class="py-3 px-4 font-mono text-slate-500">#{{ task.id }}</td>
              <td class="py-3 px-4 font-mono text-indigo-600 font-semibold">{{ task.device?.name || task.device_id }}</td>
              <td class="py-3 px-4">
                <div class="font-semibold text-slate-900">{{ task.personnel?.name || `Person #${task.personnel_id}` }}</div>
                <div class="text-[11px] text-slate-400 font-mono">CustomID: {{ task.personnel?.customize_id || '--' }}</div>
              </td>
              <td class="py-3 px-4 font-mono font-bold" :class="getActionColor(task.action)">{{ task.action }}</td>
              <td class="py-3 px-4">
                <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase tracking-wider" :class="getStatusBadgeClass(task.status)">
                  {{ task.status }}
                </span>
                <div v-if="task.error_message" class="text-[10px] text-rose-600 mt-1 max-w-xs truncate" :title="task.error_message">
                  {{ task.error_message }}
                </div>
              </td>
              <td class="py-3 px-4 font-mono">{{ task.attempts }}</td>
              <td class="py-3 px-4 font-mono text-slate-500">{{ formatDateTime(task.updated_at) }}</td>
              <td class="py-3 px-4 text-right">
                <button 
                  v-if="task.status === 'FAILED'" 
                  @click="retryTask(task)"
                  class="px-2.5 py-1 text-[11px] bg-indigo-50 hover:bg-indigo-100 text-indigo-700 border border-indigo-200 rounded transition-colors"
                >
                  🔁 Retry
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { formatDateTime } from '../utils/date';
import notify from '../utils/notify';
import apiClient from '../api/client';
import echo from '../echo';

const tasks = ref([]);
const loading = ref(false);
const statusFilter = ref('');

async function fetchTasks() {
  loading.value = true;
  try {
    const res = await apiClient.get('/api/sync-tasks', {
      params: { status: statusFilter.value }
    });
    tasks.value = res.data.data;
  } catch (err) {
    console.error('Failed to load sync tasks:', err);
  } finally {
    loading.value = false;
  }
}

function handleLiveSyncTaskUpdated(e) {
  if (!e || !e.id) return;
  const index = tasks.value.findIndex(t => String(t.id) === String(e.id));
  if (index !== -1) {
    tasks.value[index] = { ...tasks.value[index], ...e };
  } else if (!statusFilter.value || statusFilter.value === e.status) {
    tasks.value.unshift(e);
  }
}

async function retryTask(task) {
  try {
    await apiClient.post(`/api/sync-tasks/${task.id}/retry`);
    notify.success('Task Re-queued', `Sync task #${task.id} has been re-dispatched to the camera-sync queue.`);
    fetchTasks();
  } catch (err) {
    notify.error('Retry Failed', 'Failed to re-dispatch sync task');
  }
}

function getActionColor(action) {
  switch (action) {
    case 'ADD': return 'text-emerald-600';
    case 'EDIT': return 'text-amber-600';
    case 'DELETE': return 'text-rose-600';
    default: return 'text-slate-600';
  }
}

function getStatusBadgeClass(status) {
  switch (status) {
    case 'COMPLETED': return 'bg-emerald-50 text-emerald-700 border border-emerald-200';
    case 'FAILED': return 'bg-rose-50 text-rose-700 border border-rose-200';
    case 'PROCESSING': return 'bg-sky-50 text-sky-700 border border-sky-200';
    default: return 'bg-slate-100 text-slate-600 border border-slate-200';
  }
}

onMounted(() => {
  fetchTasks();
  echo.channel('sync-tasks')
    .listen('.SyncTaskUpdated', handleLiveSyncTaskUpdated)
    .listen('SyncTaskUpdated', handleLiveSyncTaskUpdated);
});

onUnmounted(() => {
  echo.channel('sync-tasks')
    .stopListening('.SyncTaskUpdated')
    .stopListening('SyncTaskUpdated');
});
</script>
