<template>
  <div v-if="isOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm" @click.self="close">
    <div class="bg-white border border-slate-200 rounded-2xl max-w-xl w-full p-6 space-y-5 shadow-2xl">
      <!-- Modal Header -->
      <div class="flex items-center justify-between border-b border-slate-200 pb-3.5">
        <div>
          <h3 class="text-base font-bold text-slate-900 flex items-center gap-2">
            <span>📥 Camera Log Historical Backfill</span>
          </h3>
          <p class="text-xs text-slate-500 mt-0.5">
            Retrieve missing camera verification logs or stranger captures from onboard flash storage for selected date ranges
          </p>
        </div>
        <button @click="close" class="text-slate-400 hover:text-slate-700 text-xl font-bold cursor-pointer">&times;</button>
      </div>

      <!-- Form Content -->
      <div class="space-y-4 text-xs">
        <!-- Info Banner -->
        <div class="bg-indigo-50 border border-indigo-200 p-3 rounded-xl text-indigo-900 leading-relaxed flex items-start gap-2.5">
          <span class="text-base shrink-0">💡</span>
          <div>
            <strong>How Backfill Works:</strong> The hub sends HTTP POST <code class="font-mono text-indigo-700">/action/ManualPushRecords</code> or <code class="font-mono text-indigo-700">/action/ManualPushSnaps</code> commands to the edge camera. The camera then queries its local storage and streams historical logs back to the hub.
          </div>
        </div>

        <!-- Target Camera Selection -->
        <div>
          <label class="block font-semibold text-slate-700 mb-1">Target Camera Device *</label>
          <select 
            v-model="form.deviceId" 
            class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 cursor-pointer shadow-xs font-mono"
          >
            <option value="all">🌐 All Active Camera Devices ({{ activeDevicesCount }})</option>
            <option v-for="device in store.devices" :key="device.id" :value="device.id">
              {{ device.name }} ({{ device.device_id }} - {{ device.ip_address }})
            </option>
          </select>
        </div>

        <!-- Log Type Selection -->
        <div>
          <label class="block font-semibold text-slate-700 mb-1.5">Log Type to Backfill *</label>
          <div class="grid grid-cols-3 gap-2">
            <button 
              type="button" 
              @click="form.logType = 'both'"
              class="py-2 px-3 rounded-xl border text-xs font-semibold flex items-center justify-center gap-1.5 transition-all cursor-pointer"
              :class="form.logType === 'both' ? 'bg-indigo-600 text-white border-indigo-600 shadow-xs' : 'bg-slate-50 text-slate-700 border-slate-200 hover:bg-slate-100'"
            >
              <span>🔄 All Logs</span>
            </button>
            <button 
              type="button" 
              @click="form.logType = 'records'"
              class="py-2 px-3 rounded-xl border text-xs font-semibold flex items-center justify-center gap-1.5 transition-all cursor-pointer"
              :class="form.logType === 'records' ? 'bg-indigo-600 text-white border-indigo-600 shadow-xs' : 'bg-slate-50 text-slate-700 border-slate-200 hover:bg-slate-100'"
            >
              <span>👥 Verification</span>
            </button>
            <button 
              type="button" 
              @click="form.logType = 'snaps'"
              class="py-2 px-3 rounded-xl border text-xs font-semibold flex items-center justify-center gap-1.5 transition-all cursor-pointer"
              :class="form.logType === 'snaps' ? 'bg-indigo-600 text-white border-indigo-600 shadow-xs' : 'bg-slate-50 text-slate-700 border-slate-200 hover:bg-slate-100'"
            >
              <span>🎭 Strangers</span>
            </button>
          </div>
        </div>

        <!-- Quick Date Range Presets -->
        <div>
          <div class="flex items-center justify-between mb-1.5">
            <label class="font-semibold text-slate-700">Quick Date Presets</label>
            <span class="text-[10px] text-slate-400 font-mono">Asia/Manila (UTC+8)</span>
          </div>
          <div class="grid grid-cols-4 gap-2">
            <button 
              type="button" 
              @click="applyPreset('today')"
              class="py-1.5 px-2 bg-slate-100 hover:bg-indigo-50 hover:text-indigo-700 text-slate-700 font-medium rounded-lg border border-slate-200 transition-colors text-center text-[11px] cursor-pointer"
            >
              📅 Today
            </button>
            <button 
              type="button" 
              @click="applyPreset('yesterday')"
              class="py-1.5 px-2 bg-slate-100 hover:bg-indigo-50 hover:text-indigo-700 text-slate-700 font-medium rounded-lg border border-slate-200 transition-colors text-center text-[11px] cursor-pointer"
            >
              📅 Yesterday
            </button>
            <button 
              type="button" 
              @click="applyPreset('7days')"
              class="py-1.5 px-2 bg-slate-100 hover:bg-indigo-50 hover:text-indigo-700 text-slate-700 font-medium rounded-lg border border-slate-200 transition-colors text-center text-[11px] cursor-pointer"
            >
              📅 Last 7 Days
            </button>
            <button 
              type="button" 
              @click="applyPreset('30days')"
              class="py-1.5 px-2 bg-slate-100 hover:bg-indigo-50 hover:text-indigo-700 text-slate-700 font-medium rounded-lg border border-slate-200 transition-colors text-center text-[11px] cursor-pointer"
            >
              📅 Last 30 Days
            </button>
          </div>
        </div>

        <!-- Date & Time Inputs -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 bg-slate-50 border border-slate-200 p-3.5 rounded-xl">
          <div>
            <label class="block font-semibold text-slate-700 mb-1">Start Date &amp; Time (TimeS) *</label>
            <input 
              v-model="form.timeS" 
              type="datetime-local" 
              class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs" 
            />
          </div>
          <div>
            <label class="block font-semibold text-slate-700 mb-1">End Date &amp; Time (TimeE) *</label>
            <input 
              v-model="form.timeE" 
              type="datetime-local" 
              class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs" 
            />
          </div>
        </div>

        <!-- Webhook Callback Address Field -->
        <div>
          <label class="block font-semibold text-slate-700 mb-1">Webhook Callback Address (SubscribeAddr)</label>
          <input 
            v-model="form.subscribeAddr" 
            type="text" 
            placeholder="e.g. http://192.168.1.50:8080 or http://192.168.1.8:8085"
            class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs" 
          />
          <p class="text-[11px] text-slate-500 mt-1">Re-streams historical logs to this reachable IPv4 callback endpoint via HTTP Subscribe.</p>
        </div>

        <!-- Progress Output Banner -->
        <div v-if="statusMessage" class="p-3 rounded-xl text-xs font-mono border" :class="statusSuccess ? 'bg-emerald-50 text-emerald-800 border-emerald-200' : 'bg-rose-50 text-rose-800 border-rose-200'">
          {{ statusMessage }}
        </div>
      </div>

      <!-- Modal Footer -->
      <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-200">
        <button 
          type="button" 
          @click="close" 
          class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold rounded-lg border border-slate-300 transition-colors cursor-pointer"
        >
          Cancel
        </button>

        <button 
          type="button" 
          @click="submitBackfill" 
          :disabled="loading"
          class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-lg shadow-sm disabled:opacity-50 transition-all flex items-center gap-2 cursor-pointer"
        >
          <span v-if="loading" class="animate-spin text-sm">⏳</span>
          <span>{{ loading ? 'Requesting Backfill...' : '🚀 Start Historical Backfill' }}</span>
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { useCameraStore } from '../stores/cameraStore';
import notify from '../utils/notify';
import apiClient from '../api/client';

const props = defineProps({
  isOpen: {
    type: Boolean,
    default: false
  },
  initialDevice: {
    type: [Object, String, Number],
    default: null
  }
});

const emit = defineEmits(['close', 'success']);

const store = useCameraStore();
const loading = ref(false);
const statusMessage = ref('');
const statusSuccess = ref(true);

const form = ref({
  deviceId: 'all',
  logType: 'both', // 'both', 'records', 'snaps'
  timeS: '',
  timeE: '',
  subscribeAddr: window.location.origin
});

const activeDevicesCount = computed(() => store.devices.length);

watch(() => props.isOpen, (newVal) => {
  if (newVal) {
    statusMessage.value = '';
    if (props.initialDevice) {
      if (typeof props.initialDevice === 'object' && props.initialDevice.id) {
        form.value.deviceId = props.initialDevice.id;
      } else {
        form.value.deviceId = props.initialDevice;
      }
    } else {
      form.value.deviceId = 'all';
    }
    if (!form.value.timeS || !form.value.timeE) {
      applyPreset('today');
    }
  }
}, { immediate: true });

function close() {
  emit('close');
}

function formatDateForInput(d) {
  const pad = (n) => String(n).padStart(2, '0');
  const year = d.getFullYear();
  const month = pad(d.getMonth() + 1);
  const day = pad(d.getDate());
  const hours = pad(d.getHours());
  const minutes = pad(d.getMinutes());
  return `${year}-${month}-${day}T${hours}:${minutes}`;
}

function applyPreset(preset) {
  const now = new Date();
  const end = new Date(now);

  let start = new Date(now);

  if (preset === 'today') {
    start.setHours(0, 0, 0, 0);
  } else if (preset === 'yesterday') {
    start.setDate(start.getDate() - 1);
    start.setHours(0, 0, 0, 0);
    end.setDate(end.getDate() - 1);
    end.setHours(23, 59, 59, 999);
  } else if (preset === '7days') {
    start.setDate(start.getDate() - 7);
    start.setHours(0, 0, 0, 0);
  } else if (preset === '30days') {
    start.setDate(start.getDate() - 30);
    start.setHours(0, 0, 0, 0);
  }

  form.value.timeS = formatDateForInput(start);
  form.value.timeE = formatDateForInput(end);
}

function formatApiTime(dtStr) {
  if (!dtStr) return '';
  return dtStr.replace('T', ' ') + ':00';
}

async function submitBackfill() {
  if (!form.value.timeS || !form.value.timeE) {
    notify.warning('Date Range Required', 'Please select both start and end dates.');
    return;
  }

  const timeSFormatted = formatApiTime(form.value.timeS);
  const timeEFormatted = formatApiTime(form.value.timeE);

  if (new Date(timeSFormatted) >= new Date(timeEFormatted)) {
    notify.warning('Invalid Date Range', 'Start date must be before end date.');
    return;
  }

  // Determine target devices list
  let targets = [];
  if (form.value.deviceId === 'all') {
    targets = [...store.devices];
  } else {
    const found = store.devices.find(d => String(d.id) === String(form.value.deviceId));
    if (found) {
      targets = [found];
    }
  }

  if (targets.length === 0) {
    notify.warning('No Camera Selected', 'Please register or select a valid camera device.');
    return;
  }

  loading.value = true;
  statusMessage.value = '';

  let successCount = 0;
  let totalCalls = 0;
  let errors = [];

  for (const dev of targets) {
    const devName = dev.name || dev.device_id;

    // 1. Dispatch ManualPushRecords if requested
    if (form.value.logType === 'both' || form.value.logType === 'records') {
      totalCalls++;
      try {
        const res = await apiClient.post(`/api/devices/${dev.id}/manual-push-records`, {
          time_s: timeSFormatted,
          time_e: timeEFormatted,
          subscribe_addr: form.value.subscribeAddr
        });
        if (res.data.success) {
          successCount++;
        } else {
          errors.push(`${devName} (Records): ${res.data.error || 'Camera rejected command'}`);
        }
      } catch (err) {
        errors.push(`${devName} (Records): ${err.response?.data?.message || err.message}`);
      }
    }

    // 2. Dispatch ManualPushSnaps if requested
    if (form.value.logType === 'both' || form.value.logType === 'snaps') {
      totalCalls++;
      try {
        const res = await apiClient.post(`/api/devices/${dev.id}/manual-push-snaps`, {
          time_s: timeSFormatted,
          time_e: timeEFormatted,
          subscribe_addr: form.value.subscribeAddr
        });
        if (res.data.success) {
          successCount++;
        } else {
          errors.push(`${devName} (Snaps): ${res.data.error || 'Camera rejected command'}`);
        }
      } catch (err) {
        errors.push(`${devName} (Snaps): ${err.response?.data?.message || err.message}`);
      }
    }
  }

  loading.value = false;

  if (successCount > 0) {
    statusSuccess.value = true;
    statusMessage.value = `✓ Successfully dispatched ${successCount}/${totalCalls} backfill request(s) for period ${timeSFormatted} to ${timeEFormatted}. Cameras are streaming records back to the hub.`;
    notify.success('Backfill Requested', `Dispatched backfill stream command to ${targets.length} camera(s).`);
    emit('success');
  } else {
    statusSuccess.value = false;
    statusMessage.value = `❌ Failed to trigger backfill: ${errors.join('; ')}`;
    notify.error('Backfill Command Failed', errors[0] || 'Cameras rejected backfill commands.');
  }
}
</script>
