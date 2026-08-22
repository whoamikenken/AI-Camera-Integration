<template>
  <div class="space-y-6">
    <!-- Top Header & Device Enrollment -->
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 bg-slate-900/80 border border-slate-800 p-4 rounded-xl backdrop-blur-md">
      <div>
        <h2 class="text-lg font-semibold text-slate-100">Camera & Edge Device Fleet</h2>
        <p class="text-xs text-slate-400">Configure edge AI cameras, dispatch MQTT parameters, trigger remote reboots, and monitor device heartbeats</p>
      </div>

      <div class="flex items-center gap-3">
        <button 
          @click="openCreateModal"
          class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold rounded-lg shadow-lg shadow-indigo-600/20 transition-all flex items-center gap-2"
        >
          <span>➕ Add Camera Device</span>
        </button>
      </div>
    </div>

    <!-- Device Cards Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
      <div 
        v-for="device in store.devices" 
        :key="device.id"
        class="bg-slate-900/90 border rounded-2xl p-5 space-y-4 shadow-xl transition-all hover:border-slate-700"
        :class="device.is_online ? 'border-emerald-500/30' : 'border-slate-800'"
      >
        <!-- Card Header -->
        <div class="flex items-start justify-between">
          <div>
            <div class="flex items-center gap-2">
              <span class="inline-block w-2.5 h-2.5 rounded-full" :class="device.is_online ? 'bg-emerald-500 shadow-lg shadow-emerald-500/50' : 'bg-slate-600'"></span>
              <h3 class="font-semibold text-slate-100 text-base">{{ device.name }}</h3>
            </div>
            <div class="text-xs text-slate-400 font-mono mt-0.5">ID: {{ device.device_id }}</div>
          </div>
          <div class="flex items-center gap-2">
            <span 
              class="text-[10px] uppercase font-bold px-2 py-0.5 rounded-full"
              :class="device.is_online ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-slate-800 text-slate-400 border border-slate-700'"
            >
              {{ device.is_online ? 'Online' : 'Offline' }}
            </span>
            <div class="flex items-center gap-1">
              <button 
                @click="openEditModal(device)" 
                class="p-1 text-slate-400 hover:text-indigo-400 hover:bg-slate-800 rounded transition-colors cursor-pointer" 
                title="Edit Camera Details"
              >
                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/>
                </svg>
              </button>
              <button 
                @click="deleteDevice(device)" 
                :disabled="deletingId === device.id"
                class="p-1 text-slate-400 hover:text-rose-400 hover:bg-slate-800 rounded transition-colors disabled:opacity-50 cursor-pointer" 
                title="Delete Camera"
              >
                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <polyline points="3 6 5 6 21 6"/>
                  <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                  <line x1="10" y1="11" x2="10" y2="17"/>
                  <line x1="14" y1="11" x2="14" y2="17"/>
                </svg>
              </button>
            </div>
          </div>
        </div>

        <!-- Network Info -->
        <div class="bg-slate-800/40 rounded-xl p-3 text-xs space-y-1.5 font-mono text-slate-300">
          <div class="flex justify-between">
            <span class="text-slate-500 font-sans">LAN Endpoint:</span>
            <span class="text-indigo-400">{{ device.ip_address }}:{{ device.port }}</span>
          </div>
          <div class="flex justify-between">
            <span class="text-slate-500 font-sans">Device Type:</span>
            <span>{{ getDeviceTypeName(device.device_type) }}</span>
          </div>
          <div class="flex justify-between">
            <span class="text-slate-500 font-sans">Last Heartbeat:</span>
            <span>{{ formatHeartbeat(device.last_heartbeat_at) }}</span>
          </div>
        </div>

        <!-- Telemetry Counts -->
        <div class="grid grid-cols-2 gap-2 text-center text-xs">
          <div class="bg-slate-800/30 rounded-lg p-2 border border-slate-800/80">
            <div class="text-slate-400 text-[11px]">Verifications</div>
            <div class="font-bold text-slate-200 text-sm mt-0.5">{{ device.access_logs_count || 0 }}</div>
          </div>
          <div class="bg-slate-800/30 rounded-lg p-2 border border-slate-800/80">
            <div class="text-slate-400 text-[11px]">Strangers</div>
            <div class="font-bold text-amber-400 text-sm mt-0.5">{{ device.stranger_snaps_count || 0 }}</div>
          </div>
        </div>

        <!-- Primary Live Feed Preview Button -->
        <button 
          @click="openPreview(device)"
          class="w-full py-2 px-3 bg-indigo-600/20 hover:bg-indigo-600/30 text-indigo-300 hover:text-indigo-200 text-xs font-semibold rounded-xl border border-indigo-500/30 flex items-center justify-center space-x-2 transition-all shadow-sm cursor-pointer"
        >
          <svg class="w-4 h-4 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
          </svg>
          <span>🎥 Live Camera Preview</span>
        </button>

        <!-- Secondary Actions -->
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-2 pt-2 border-t border-slate-800/80">
          <button 
            @click="testConnection(device)" 
            :disabled="testingId === device.id"
            class="py-1.5 px-2.5 bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-medium rounded-lg border border-slate-700 transition-colors disabled:opacity-50 cursor-pointer"
          >
            {{ testingId === device.id ? 'Testing...' : '📡 Ping' }}
          </button>
          <button 
            @click="openMqttModal(device)"
            class="py-1.5 px-2.5 bg-slate-800 hover:bg-slate-700 text-indigo-300 text-xs font-medium rounded-lg border border-slate-700 transition-colors cursor-pointer"
          >
            ⚙️ MQTT
          </button>
          <button 
            @click="auditCameraFaces(device)"
            class="py-1.5 px-2.5 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-medium rounded-lg border border-slate-700 transition-colors cursor-pointer"
          >
            👥 Audit
          </button>
          <button 
            @click="rebootDevice(device)"
            class="py-1.5 px-2.5 bg-amber-950/30 hover:bg-amber-900/50 text-amber-300 text-xs font-medium rounded-lg border border-amber-800/30 transition-colors cursor-pointer"
          >
            🔄 Reboot
          </button>
          <button 
            @click="openEditModal(device)"
            class="py-1.5 px-2.5 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-medium rounded-lg border border-slate-700 transition-colors cursor-pointer"
          >
            ✏️ Edit
          </button>
          <button 
            @click="deleteDevice(device)" 
            :disabled="deletingId === device.id"
            class="py-1.5 px-2.5 bg-rose-950/30 hover:bg-rose-900/50 text-rose-300 text-xs font-medium rounded-lg border border-rose-800/30 transition-colors disabled:opacity-50 cursor-pointer"
          >
            {{ deletingId === device.id ? 'Deleting...' : '🗑️ Delete' }}
          </button>
        </div>
      </div>
    </div>

    <!-- Empty State -->
    <div v-if="store.devices.length === 0" class="bg-slate-900/40 border border-dashed border-slate-800 rounded-2xl p-16 text-center text-slate-500">
      <div class="text-4xl mb-3">📡</div>
      <div class="text-slate-300 font-semibold text-base">No Cameras Registered Yet</div>
      <p class="text-xs text-slate-500 mt-1 max-w-sm mx-auto">Add your edge camera's LAN IP address and Device ID to begin bidirectional synchronization and telemetry streaming.</p>
      <button @click="openCreateModal" class="mt-4 px-4 py-2 bg-indigo-600 text-white rounded-lg text-xs font-semibold">Add Camera Now</button>
    </div>

    <!-- Device Setup Modal -->
    <div v-if="deviceModal.show" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm" @click.self="deviceModal.show = false">
      <div class="bg-slate-900 border border-slate-700 rounded-2xl max-w-lg w-full p-6 space-y-4">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
          <h3 class="text-base font-semibold text-slate-100">{{ deviceModal.isEdit ? 'Edit Camera Parameters' : 'Register AI Camera Device' }}</h3>
          <button @click="deviceModal.show = false" class="text-slate-400 hover:text-white text-lg font-bold">&times;</button>
        </div>

        <form @submit.prevent="saveDevice" class="space-y-4">
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-medium text-slate-300 mb-1">Device ID / Serial *</label>
              <input v-model="deviceForm.device_id" required type="text" placeholder="e.g. 005a213b000b93cc" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-xs text-slate-100" />
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-300 mb-1">Friendly Name *</label>
              <input v-model="deviceForm.name" required type="text" placeholder="e.g. Main Entrance Gate" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-xs text-slate-100" />
            </div>
          </div>

          <div class="grid grid-cols-3 gap-3">
            <div class="col-span-2">
              <label class="block text-xs font-medium text-slate-300 mb-1">Camera IP Address *</label>
              <input v-model="deviceForm.ip_address" required type="text" placeholder="192.168.1.100" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-xs text-slate-100" />
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-300 mb-1">Port</label>
              <input v-model.number="deviceForm.port" type="number" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-xs text-slate-100" />
            </div>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-medium text-slate-300 mb-1">HTTP Username</label>
              <input v-model="deviceForm.username" type="text" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-xs text-slate-100" />
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-300 mb-1">HTTP Password</label>
              <input v-model="deviceForm.password" type="password" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-xs text-slate-100" />
            </div>
          </div>

          <div>
            <label class="block text-xs font-medium text-slate-300 mb-1">Device Form Factor</label>
            <select v-model.number="deviceForm.device_type" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-xs text-slate-100">
              <option :value="0">0: IPC (Smart IP Camera)</option>
              <option :value="1">1: DVR</option>
              <option :value="2">2: NVR</option>
              <option :value="3">3: Access Control Panel Unit</option>
            </select>
          </div>

          <div class="flex items-center justify-between pt-3 border-t border-slate-800">
            <button 
              v-if="deviceModal.isEdit" 
              type="button" 
              @click="deleteDevice({ id: deviceModal.id, name: deviceForm.name, ip_address: deviceForm.ip_address })" 
              class="px-3 py-2 bg-rose-950/40 hover:bg-rose-900/60 text-rose-300 text-xs font-medium rounded-lg border border-rose-800/40 transition-colors"
            >
              🗑️ Delete Camera
            </button>
            <div v-else></div>

            <div class="flex items-center gap-3">
              <button type="button" @click="deviceModal.show = false" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-medium rounded-lg">Cancel</button>
              <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold rounded-lg shadow-lg shadow-indigo-600/20">
                {{ deviceModal.isEdit ? 'Update Camera' : 'Save Device' }}
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>

    <!-- Push MQTT Config Modal -->
    <div v-if="mqttModal.show" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm" @click.self="mqttModal.show = false">
      <div class="bg-slate-900 border border-slate-700 rounded-2xl max-w-md w-full p-6 space-y-4">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
          <h3 class="text-base font-semibold text-slate-100">Push MQTT Broker Configuration</h3>
          <button @click="mqttModal.show = false" class="text-slate-400 hover:text-white text-lg font-bold">&times;</button>
        </div>

        <p class="text-xs text-slate-400">Sends HTTP POST <code class="text-indigo-400">/action/SetMQTTParam</code> to the camera to direct telemetry streams to the server's broker.</p>

        <form @submit.prevent="pushMqttSettings" class="space-y-3">
          <div>
            <label class="block text-xs font-medium text-slate-300 mb-1">MQTT Broker Host/IP</label>
            <input v-model="mqttForm.MQAddr" required type="text" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-xs text-slate-100" />
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-300 mb-1">MQTT Port</label>
            <input v-model.number="mqttForm.MQPort" required type="number" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-xs text-slate-100" />
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-300 mb-1">Custom Topic</label>
            <input v-model="mqttForm.MQTopic" type="text" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-xs text-slate-100" />
          </div>

          <div class="flex justify-end gap-3 pt-3 border-t border-slate-800">
            <button type="button" @click="mqttModal.show = false" class="px-4 py-2 bg-slate-800 text-slate-300 text-xs rounded-lg">Cancel</button>
            <button type="submit" :disabled="pushingMqtt" class="px-4 py-2 bg-indigo-600 text-white text-xs font-semibold rounded-lg">
              {{ pushingMqtt ? 'Configuring Camera...' : 'Push Settings' }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Live WebSocket Feed Preview Modal -->
    <CameraLivePreviewModal
      :is-open="previewModal.show"
      :device="previewModal.device"
      @close="previewModal.show = false"
    />
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useCameraStore } from '../stores/cameraStore';
import { formatTime } from '../utils/date';
import CameraLivePreviewModal from '../components/CameraLivePreviewModal.vue';
import notify from '../utils/notify';
import axios from 'axios';

const store = useCameraStore();
const testingId = ref(null);
const deletingId = ref(null);
const pushingMqtt = ref(false);

const deviceModal = ref({ show: false, isEdit: false, id: null });
const mqttModal = ref({ show: false, device: null });
const previewModal = ref({ show: false, device: null });

const deviceForm = ref({
  device_id: '',
  name: '',
  ip_address: '192.168.1.100',
  port: 8080,
  username: 'admin',
  password: 'admin',
  device_type: 0,
  is_active: true,
});

const mqttForm = ref({
  MQAddr: '192.168.1.50',
  MQPort: 1883,
  MQTopic: '',
});

function getDeviceTypeName(type) {
  switch (type) {
    case 0: return 'IPC (Smart Camera)';
    case 1: return 'DVR';
    case 2: return 'NVR';
    case 3: return 'Access Panel';
    default: return 'Camera Unit';
  }
}

function formatHeartbeat(dateStr) {
  if (!dateStr) return 'Never';
  const d = new Date(dateStr);
  const diffSec = Math.floor((Date.now() - d.getTime()) / 1000);
  if (diffSec < 60) return `${diffSec}s ago`;
  if (diffSec < 3600) return `${Math.floor(diffSec / 60)}m ago`;
  return formatTime(d);
}

function openPreview(device) {
  previewModal.value = { show: true, device };
}

function openCreateModal() {
  deviceModal.value = { show: true, isEdit: false, id: null };
  deviceForm.value = {
    device_id: '',
    name: '',
    ip_address: '192.168.1.100',
    port: 8080,
    username: 'admin',
    password: 'admin',
    device_type: 0,
    is_active: true,
  };
}

function openEditModal(device) {
  deviceModal.value = { show: true, isEdit: true, id: device.id };
  deviceForm.value = {
    device_id: device.device_id,
    name: device.name,
    ip_address: device.ip_address,
    port: device.port || 8080,
    username: device.username || 'admin',
    password: device.password || 'admin',
    device_type: device.device_type ?? 0,
    is_active: device.is_active ?? true,
  };
}

async function deleteDevice(device) {
  const confirmed = await notify.confirm(
    `Delete Camera "${device.name}"?`,
    `All associated telemetry records and access logs for this camera (${device.ip_address}) will be removed permanently.`,
    'Yes, Delete Camera',
    'Cancel',
    true
  );

  if (!confirmed) return;

  deletingId.value = device.id;
  try {
    await axios.delete(`/api/devices/${device.id}`);
    deviceModal.value.show = false;
    await store.fetchDevices();
    await store.fetchStats();
    notify.toast(`Camera "${device.name}" removed successfully`, 'success');
  } catch (err) {
    notify.error('Delete Failed', err.response?.data?.message || err.message);
  } finally {
    deletingId.value = null;
  }
}

async function saveDevice() {
  try {
    if (deviceModal.value.isEdit) {
      await axios.put(`/api/devices/${deviceModal.value.id}`, deviceForm.value);
      notify.toast('Camera parameters updated', 'success');
    } else {
      await axios.post('/api/devices', deviceForm.value);
      notify.toast('Camera registered successfully', 'success');
    }
    deviceModal.value.show = false;
    store.fetchDevices();
  } catch (err) {
    notify.error('Save Failed', err.response?.data?.message || 'Failed to save camera device');
  }
}

async function testConnection(device) {
  testingId.value = device.id;
  try {
    const res = await axios.post(`/api/devices/${device.id}/test-connection`);
    if (res.data.success) {
      notify.success('Camera Connected!', `Device at ${device.ip_address}:${device.port} responded with HTTP 200 OK.`);
    } else {
      notify.error('Connection Failed', res.data.error || 'Check camera IP, port, and credentials');
    }
    store.fetchDevices();
  } catch (err) {
    notify.error('Connection Error', err.response?.data?.error || err.message);
  } finally {
    testingId.value = null;
  }
}

async function rebootDevice(device) {
  const confirmed = await notify.confirm(
    `Reboot Camera "${device.name}"?`,
    `The edge camera at ${device.ip_address} will undergo a remote hardware reboot.`,
    'Reboot Camera',
    'Cancel',
    true
  );

  if (!confirmed) return;

  try {
    const res = await axios.post(`/api/devices/${device.id}/reboot`);
    if (res.data.success) {
      notify.success('Reboot Initiated', 'Reboot instruction was accepted by the camera.');
    } else {
      notify.error('Reboot Failed', res.data.error || 'Hardware rejected reboot command');
    }
  } catch (err) {
    notify.error('Reboot Request Error', err.message);
  }
}

function openMqttModal(device) {
  mqttModal.value = { show: true, device };
  mqttForm.value = {
    MQAddr: window.location.hostname || '192.168.1.50',
    MQPort: 1883,
    MQTopic: device.mqtt_topic || `mqtt/face/${device.device_id}`,
  };
}

async function pushMqttSettings() {
  pushingMqtt.value = true;
  try {
    const res = await axios.post(`/api/devices/${mqttModal.value.device.id}/sync-mqtt`, mqttForm.value);
    if (res.data.success) {
      notify.success('MQTT Synchronized', 'MQTT broker parameters pushed to the edge camera.');
      mqttModal.value.show = false;
    } else {
      notify.error('Configuration Failed', res.data.error || 'Failed to update MQTT parameters');
    }
  } catch (err) {
    notify.error('MQTT Push Error', err.message);
  } finally {
    pushingMqtt.value = false;
  }
}

async function auditCameraFaces(device) {
  try {
    const res = await axios.get(`/api/devices/${device.id}/search-camera-list`);
    if (res.data.success) {
      const info = res.data.data?.info || {};
      notify.info('Face Library Audit', `Camera reports ${info.Listnum || 0} registered personnel enrolled in on-device storage.`);
    } else {
      notify.error('Audit Failed', res.data.error || 'Could not query camera face list');
    }
  } catch (err) {
    notify.error('Query Error', 'Camera search query failed');
  }
}

onMounted(() => {
  store.fetchDevices();
});
</script>
