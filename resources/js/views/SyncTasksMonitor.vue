<template>
  <div class="space-y-6">
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 bg-slate-900/80 border border-slate-800 p-4 rounded-xl backdrop-blur-md">
      <div>
        <h2 class="text-lg font-semibold text-slate-100">Edge Device Sync Outbox Queue</h2>
        <p class="text-xs text-slate-400">Monitor background Redis job queue state for personnel biometric provisioning to LAN edge cameras</p>
      </div>
      <button @click="fetchTasks" class="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 rounded-lg text-xs font-medium">
        🔄 Refresh Tasks
      </button>
    </div>

    <!-- Filter Bar -->
    <div class="flex items-center gap-3 bg-slate-900/60 border border-slate-800 p-3 rounded-xl">
      <select v-model="statusFilter" @change="fetchTasks" class="bg-slate-800/80 border border-slate-700 text-xs rounded-lg px-3 py-2 text-slate-200">
        <option value="">All Task Statuses</option>
        <option value="PENDING">PENDING</option>
        <option value="PROCESSING">PROCESSING</option>
        <option value="COMPLETED">COMPLETED</option>
        <option value="FAILED">FAILED</option>
      </select>
    </div>

    <!-- Tasks Table -->
    <div class="bg-slate-900/90 border border-slate-800 rounded-xl overflow-hidden shadow-xl">
      <div class="overflow-x-auto">
        <table class="w-full text-left text-xs text-slate-300">
          <thead class="bg-slate-800/60 text-slate-400 uppercase text-[11px] font-semibold border-b border-slate-800">
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
          <tbody class="divide-y divide-slate-800/60">
            <tr v-if="loading">
              <td colspan="8" class="py-12 text-center text-slate-500">Loading sync tasks...</td>
            </tr>
            <tr v-else-if="tasks.length === 0">
              <td colspan="8" class="py-12 text-center text-slate-500">No sync tasks recorded.</td>
            </tr>
            <tr v-for="task in tasks" :key="task.id" class="hover:bg-slate-800/30 transition-colors">
              <td class="py-3 px-4 font-mono text-slate-400">#{{ task.id }}</td>
              <td class="py-3 px-4 font-mono text-indigo-400">{{ task.device?.name || task.device_id }}</td>
              <td class="py-3 px-4">
                <div class="font-semibold text-slate-100">{{ task.personnel?.name || `Person #${task.personnel_id}` }}</div>
                <div class="text-[11px] text-slate-500 font-mono">CustomID: {{ task.personnel?.customize_id || '--' }}</div>
              </td>
              <td class="py-3 px-4 font-mono font-bold" :class="getActionColor(task.action)">{{ task.action }}</td>
              <td class="py-3 px-4">
                <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase tracking-wider" :class="getStatusBadgeClass(task.status)">
                  {{ task.status }}
                </span>
                <div v-if="task.error_message" class="text-[10px] text-rose-400 mt-1 max-w-xs truncate" :title="task.error_message">
                  {{ task.error_message }}
                </div>
              </td>
              <td class="py-3 px-4 font-mono">{{ task.attempts }}</td>
              <td class="py-3 px-4 font-mono text-slate-400">{{ formatDateTime(task.updated_at) }}</td>
              <td class="py-3 px-4 text-right">
                <button 
                  v-if="task.status === 'FAILED'" 
                  @click="retryTask(task)"
                  class="px-2.5 py-1 text-[11px] bg-indigo-600/20 hover:bg-indigo-600/30 text-indigo-300 border border-indigo-500/30 rounded transition-colors"
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
import { ref, onMounted } from 'vue';
import { formatDateTime } from '../utils/date';
import notify from '../utils/notify';
import axios from 'axios';

const tasks = ref([]);
const loading = ref(false);
const statusFilter = ref('');

async function fetchTasks() {
  loading.value = true;
  try {
    const res = await axios.get('/api/sync-tasks', {
      params: { status: statusFilter.value }
    });
    tasks.value = res.data.data;
  } catch (err) {
    console.error('Failed to load sync tasks:', err);
  } finally {
    loading.value = false;
  }
}

async function retryTask(task) {
  try {
    await axios.post(`/api/sync-tasks/${task.id}/retry`);
    notify.success('Task Re-queued', `Sync task #${task.id} has been re-dispatched to the camera-sync queue.`);
    fetchTasks();
  } catch (err) {
    notify.error('Retry Failed', 'Failed to re-dispatch sync task');
  }
}

function getActionColor(action) {
  switch (action) {
    case 'ADD': return 'text-emerald-400';
    case 'EDIT': return 'text-amber-400';
    case 'DELETE': return 'text-rose-400';
    default: return 'text-slate-300';
  }
}

function getStatusBadgeClass(status) {
  switch (status) {
    case 'COMPLETED': return 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20';
    case 'FAILED': return 'bg-rose-500/10 text-rose-400 border border-rose-500/20';
    case 'PROCESSING': return 'bg-sky-500/10 text-sky-400 border border-sky-500/20';
    default: return 'bg-slate-800 text-slate-400 border border-slate-700';
  }
}

onMounted(() => {
  fetchTasks();
});
</script>
