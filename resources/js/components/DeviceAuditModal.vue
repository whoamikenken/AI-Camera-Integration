<template>
  <div v-if="isOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm" @click.self="$emit('close')">
    <div class="bg-white border border-slate-200 rounded-2xl max-w-4xl w-full p-6 space-y-5 max-h-[90vh] overflow-y-auto shadow-2xl">
      <!-- Modal Header -->
      <div class="flex items-center justify-between border-b border-slate-200 pb-3">
        <div>
          <h3 class="text-base font-bold text-slate-900 flex items-center gap-2">
            <span>👥 Device Audit &amp; Live Telemetry</span>
            <span v-if="device" class="text-xs px-2 py-0.5 rounded font-mono bg-indigo-50 text-indigo-700 border border-indigo-200 font-semibold">
              {{ device.name }} ({{ device.ip_address }})
            </span>
          </h3>
          <p class="text-xs text-slate-500 mt-0.5">
            Querying on-device face database and log records directly via HTTP API (<code class="font-mono text-indigo-600 font-semibold">/action/SearchPersonList</code> &amp; <code class="font-mono text-indigo-600 font-semibold">/action/ManualPushRecords</code>)
          </p>
        </div>
        <button @click="$emit('close')" class="text-slate-400 hover:text-slate-700 text-xl font-bold cursor-pointer">&times;</button>
      </div>

      <!-- Loading State -->
      <div v-if="loading" class="py-16 text-center text-slate-500 space-y-3">
        <div class="inline-block w-8 h-8 border-3 border-indigo-600 border-t-transparent rounded-full animate-spin"></div>
        <p class="text-xs font-semibold text-slate-700">Auditing camera storage &amp; hardware via HTTP API...</p>
      </div>

      <!-- Audit Results View -->
      <div v-else-if="auditData" class="space-y-5">
        <!-- Navigation Tabs -->
        <div class="flex items-center gap-2 border-b border-slate-200 pb-1">
          <button 
            @click="activeTab = 'faces'"
            class="px-3.5 py-2 text-xs font-semibold rounded-t-xl transition-all cursor-pointer border-b-2"
            :class="activeTab === 'faces' ? 'text-indigo-600 border-indigo-600 bg-indigo-50/50' : 'text-slate-500 border-transparent hover:text-slate-800'"
          >
            👥 Face Library Audit ({{ auditData.face_audit?.total_on_camera || 0 }})
          </button>
          <button 
            @click="activeTab = 'logs'"
            class="px-3.5 py-2 text-xs font-semibold rounded-t-xl transition-all cursor-pointer border-b-2"
            :class="activeTab === 'logs' ? 'text-indigo-600 border-indigo-600 bg-indigo-50/50' : 'text-slate-500 border-transparent hover:text-slate-800'"
          >
            📋 On-Device Logs &amp; Backfill ({{ auditData.recent_logs?.length || 0 }})
          </button>
          <button 
            @click="activeTab = 'diagnostics'"
            class="px-3.5 py-2 text-xs font-semibold rounded-t-xl transition-all cursor-pointer border-b-2"
            :class="activeTab === 'diagnostics' ? 'text-indigo-600 border-indigo-600 bg-indigo-50/50' : 'text-slate-500 border-transparent hover:text-slate-800'"
          >
            📡 Diagnostics &amp; Telemetry
          </button>
        </div>

        <!-- TAB 1: Face Library Audit -->
        <div v-if="activeTab === 'faces'" class="space-y-4">
          <!-- Summary Cards -->
          <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div class="bg-slate-50 border border-slate-200 rounded-xl p-3 text-center">
              <div class="text-[11px] font-medium text-slate-500 uppercase">On Camera Storage</div>
              <div class="text-xl font-bold text-indigo-600 mt-0.5 font-mono">{{ auditData.face_audit?.total_on_camera || 0 }}</div>
            </div>
            <div class="bg-slate-50 border border-slate-200 rounded-xl p-3 text-center">
              <div class="text-[11px] font-medium text-slate-500 uppercase">In Central DB</div>
              <div class="text-xl font-bold text-slate-900 mt-0.5 font-mono">{{ auditData.face_audit?.total_in_db || 0 }}</div>
            </div>
            <div class="bg-emerald-50/50 border border-emerald-200 rounded-xl p-3 text-center">
              <div class="text-[11px] font-medium text-emerald-700 uppercase">Synced Match</div>
              <div class="text-xl font-bold text-emerald-600 mt-0.5 font-mono">{{ auditData.face_audit?.synced_count || 0 }}</div>
            </div>
            <div class="bg-amber-50/50 border border-amber-200 rounded-xl p-3 text-center">
              <div class="text-[11px] font-medium text-amber-800 uppercase">Missing on Camera</div>
              <div class="text-xl font-bold text-amber-600 mt-0.5 font-mono">{{ auditData.face_audit?.missing_on_camera_count || 0 }}</div>
            </div>
          </div>

          <!-- On-Device Face Table -->
          <div class="space-y-2">
            <h4 class="text-xs font-bold text-slate-900 uppercase tracking-wider flex items-center justify-between">
              <span>On-Device Enrolled Personnel (HTTP <code class="text-indigo-600">/action/SearchPersonList</code>)</span>
              <button @click="fetchAudit" class="text-indigo-600 hover:text-indigo-800 text-xs font-normal underline cursor-pointer">🔄 Refresh Audit</button>
            </h4>

            <div class="border border-slate-200 rounded-xl overflow-hidden max-h-60 overflow-y-auto">
              <table class="w-full text-left text-xs">
                <thead class="bg-slate-100 text-slate-600 font-semibold uppercase text-[10px] sticky top-0">
                  <tr>
                    <th class="px-3 py-2">Custom ID</th>
                    <th class="px-3 py-2">Camera Name</th>
                    <th class="px-3 py-2">Person Type</th>
                    <th class="px-3 py-2">Central DB Match</th>
                    <th class="px-3 py-2 text-right">Status</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                  <tr v-for="item in auditData.face_audit?.camera_list" :key="item.customize_id" class="hover:bg-slate-50/80">
                    <td class="px-3 py-2 font-mono font-semibold text-slate-900">#{{ item.customize_id }}</td>
                    <td class="px-3 py-2 font-medium text-slate-800">{{ item.camera_name }}</td>
                    <td class="px-3 py-2">
                      <span class="px-2 py-0.5 rounded text-[10px] font-semibold" :class="item.camera_person_type === 1 ? 'bg-rose-50 text-rose-700 border border-rose-200' : 'bg-emerald-50 text-emerald-700 border border-emerald-200'">
                        {{ item.camera_person_type === 1 ? 'Blacklist' : 'Whitelist' }}
                      </span>
                    </td>
                    <td class="px-3 py-2 text-slate-600">
                      {{ item.db_match ? `${item.db_match.name} (#${item.customize_id})` : '--' }}
                    </td>
                    <td class="px-3 py-2 text-right">
                      <span class="px-2 py-0.5 rounded text-[10px] font-bold" :class="item.status === 'SYNCED' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'">
                        {{ item.status === 'SYNCED' ? '✅ SYNCED' : '⚠️ UNTRACKED' }}
                      </span>
                    </td>
                  </tr>
                  <tr v-if="!auditData.face_audit?.camera_list?.length">
                    <td colspan="5" class="px-3 py-6 text-center text-slate-500 italic">No personnel records found stored on camera.</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Missing on Camera Section -->
          <div v-if="auditData.face_audit?.missing_on_camera?.length" class="space-y-2 pt-2">
            <div class="flex items-center justify-between">
              <h4 class="text-xs font-bold text-amber-800 uppercase tracking-wider">
                ⚠️ Personnel in Central Database Missing on Camera ({{ auditData.face_audit.missing_on_camera.length }})
              </h4>
              <button 
                @click="syncAllMissingPersonnel" 
                :disabled="syncingAll"
                class="px-3 py-1 bg-amber-600 hover:bg-amber-700 text-white rounded text-xs font-semibold shadow-xs transition-colors cursor-pointer flex items-center gap-1 disabled:opacity-50"
              >
                <span>⚡</span> {{ syncingAll ? 'Dispatching Jobs...' : 'Sync All Missing to Camera' }}
              </button>
            </div>
            <div class="border border-amber-200 bg-amber-50/30 rounded-xl overflow-hidden max-h-40 overflow-y-auto">
              <table class="w-full text-left text-xs">
                <thead class="bg-amber-100/60 text-amber-900 font-semibold uppercase text-[10px] sticky top-0">
                  <tr>
                    <th class="px-3 py-2">Custom ID</th>
                    <th class="px-3 py-2">Name</th>
                    <th class="px-3 py-2">Type</th>
                    <th class="px-3 py-2 text-right">Action</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-amber-200/50">
                  <tr v-for="person in auditData.face_audit.missing_on_camera" :key="person.id" class="hover:bg-amber-100/30">
                    <td class="px-3 py-1.5 font-mono font-semibold text-slate-900">#{{ person.customize_id }}</td>
                    <td class="px-3 py-1.5 font-medium text-slate-800">{{ person.name }}</td>
                    <td class="px-3 py-1.5">
                      <span class="px-2 py-0.5 rounded text-[10px] font-semibold" :class="person.person_type === 1 ? 'bg-rose-100 text-rose-800' : 'bg-emerald-100 text-emerald-800'">
                        {{ person.person_type === 1 ? 'Blacklist' : 'Whitelist' }}
                      </span>
                    </td>
                    <td class="px-3 py-1.5 text-right">
                      <button @click="syncPersonNow(person.id)" class="px-2.5 py-1 bg-indigo-600 hover:bg-indigo-700 text-white rounded text-[11px] font-semibold cursor-pointer">
                        ⚡ Push to Camera
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- TAB 2: On-Device Logs & Backfill -->
        <div v-else-if="activeTab === 'logs'" class="space-y-4">
          <!-- Backfill Quick Controls -->
          <div class="bg-indigo-50/80 border border-indigo-200 p-4 rounded-xl space-y-3">
            <div class="flex items-center justify-between">
              <div>
                <h4 class="text-xs font-bold text-indigo-900">📥 Backfill &amp; Fetch Current Device Logs</h4>
                <p class="text-[11px] text-indigo-700">Trigger camera to push historical offline verification records (<code class="font-mono font-semibold">/action/ManualPushRecords</code>) directly to central hub</p>
              </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
              <button @click="fetchLogsForWindow(1)" :disabled="pullingLogs" class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-xs font-semibold disabled:opacity-50 cursor-pointer shadow-xs">
                ⏱️ Fetch Last 1 Hour
              </button>
              <button @click="fetchLogsForWindow(24)" :disabled="pullingLogs" class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-xs font-semibold disabled:opacity-50 cursor-pointer shadow-xs">
                📅 Fetch Last 24 Hours
              </button>
              <button @click="fetchLogsForWindow(168)" :disabled="pullingLogs" class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-xs font-semibold disabled:opacity-50 cursor-pointer shadow-xs">
                🗓️ Fetch Last 7 Days
              </button>
            </div>
          </div>

          <!-- Access Logs Table -->
          <div class="space-y-2">
            <h4 class="text-xs font-bold text-slate-900 uppercase tracking-wider">Recent Access Logs Recorded for {{ device?.name }}</h4>
            <div class="border border-slate-200 rounded-xl overflow-hidden max-h-64 overflow-y-auto">
              <table class="w-full text-left text-xs">
                <thead class="bg-slate-100 text-slate-600 font-semibold uppercase text-[10px] sticky top-0">
                  <tr>
                    <th class="px-3 py-2">Captured At</th>
                    <th class="px-3 py-2">Name / Person</th>
                    <th class="px-3 py-2">Similarity</th>
                    <th class="px-3 py-2">Status</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                  <tr v-for="log in auditData.recent_logs" :key="log.id" class="hover:bg-slate-50/80">
                    <td class="px-3 py-2 font-mono text-slate-600">{{ formatDateTime(log.captured_at) }}</td>
                    <td class="px-3 py-2 font-medium text-slate-900">{{ log.person_name || 'Unregistered' }}</td>
                    <td class="px-3 py-2 font-mono text-indigo-600 font-bold">{{ log.similarity ? `${log.similarity}%` : '--' }}</td>
                    <td class="px-3 py-2">
                      <span class="px-2 py-0.5 rounded text-[10px] font-bold" :class="log.verify_status === 1 ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'">
                        {{ log.verify_status === 1 ? 'ALLOWED' : 'DENIED' }}
                      </span>
                    </td>
                  </tr>
                  <tr v-if="!auditData.recent_logs?.length">
                    <td colspan="4" class="px-3 py-6 text-center text-slate-500 italic">No verification logs recorded for this camera yet.</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- TAB 3: Diagnostics & Telemetry -->
        <div v-else-if="activeTab === 'diagnostics'" class="space-y-4">
          <!-- Real-Time Hardware & System Params -->
          <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 space-y-3">
            <h4 class="text-xs font-bold uppercase tracking-wider text-slate-700 flex items-center justify-between">
              <span>🖥️ System &amp; Hardware Telemetry (HTTP <code class="font-mono text-indigo-600">/action/GetSysParam</code> &amp; <code class="font-mono text-indigo-600">/action/GetDeviceInformation</code>)</span>
              <span class="text-[10px] px-2 py-0.5 rounded bg-emerald-100 text-emerald-800 font-bold">LIVE VIA HTTP</span>
            </h4>
            
            <div v-if="auditData.realtime_hardware?.sys_param || auditData.hardware_info" class="grid grid-cols-2 sm:grid-cols-3 gap-3 text-xs font-mono text-slate-800">
              <div><span class="text-slate-500 font-sans block text-[10px] uppercase">Device ID:</span> {{ auditData.realtime_hardware?.sys_param?.DeviceID || auditData.hardware_info?.DeviceID || auditData.device?.device_id }}</div>
              <div><span class="text-slate-500 font-sans block text-[10px] uppercase">Camera Name:</span> {{ auditData.realtime_hardware?.sys_param?.Name || auditData.hardware_info?.Name || auditData.device?.name }}</div>
              <div><span class="text-slate-500 font-sans block text-[10px] uppercase">Firmware Version:</span> {{ auditData.realtime_hardware?.sys_param?.Version || auditData.hardware_info?.Version || 'v4.2.1-std' }}</div>
              <div><span class="text-slate-500 font-sans block text-[10px] uppercase">Network Scheme:</span> {{ (auditData.device?.scheme || 'http').toUpperCase() }}</div>
              <div><span class="text-slate-500 font-sans block text-[10px] uppercase">Endpoint Address:</span> {{ auditData.device?.endpoint_url || `${auditData.device?.ip_address}:${auditData.device?.port}` }}</div>
              <div><span class="text-slate-500 font-sans block text-[10px] uppercase">Device Type:</span> {{ auditData.realtime_hardware?.device_info?.DeviceType ?? auditData.hardware_info?.DeviceType ?? 0 }} (IPC Camera)</div>
              <div><span class="text-slate-500 font-sans block text-[10px] uppercase">Enrolled Faces Count:</span> {{ auditData.realtime_hardware?.person_num?.PersonNum ?? auditData.face_audit?.total_on_camera ?? 0 }} / {{ auditData.realtime_hardware?.person_num?.MaxListsNum ?? 10000 }}</div>
              <div><span class="text-slate-500 font-sans block text-[10px] uppercase">Last Heartbeat:</span> {{ formatDateTime(auditData.device?.last_heartbeat_at) || 'Never' }}</div>
            </div>
            <div v-else class="text-xs text-slate-500 italic">No detailed hardware telemetry payload returned.</div>
          </div>

          <!-- Real-Time MQTT Configuration on Camera -->
          <div class="bg-indigo-50/50 border border-indigo-200 rounded-xl p-4 space-y-3">
            <h4 class="text-xs font-bold uppercase tracking-wider text-indigo-900 flex items-center justify-between">
              <span>📡 On-Camera MQTT Configuration (HTTP <code class="font-mono text-indigo-600">/action/GetMQTTParam</code>)</span>
              <span class="text-[10px] px-2 py-0.5 rounded font-bold" :class="auditData.realtime_hardware?.mqtt_param?.MQEnable === 1 ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-700'">
                {{ auditData.realtime_hardware?.mqtt_param?.MQEnable === 1 ? 'MQTT ENABLED' : 'MQTT DISABLED' }}
              </span>
            </h4>
            
            <div v-if="auditData.realtime_hardware?.mqtt_param" class="grid grid-cols-2 sm:grid-cols-3 gap-3 text-xs font-mono text-slate-800">
              <div><span class="text-slate-500 font-sans block text-[10px] uppercase">Broker Address:</span> {{ auditData.realtime_hardware.mqtt_param.MQAddr || '192.168.1.50' }}:{{ auditData.realtime_hardware.mqtt_param.MQPort || 1883 }}</div>
              <div><span class="text-slate-500 font-sans block text-[10px] uppercase">MQTT Topic:</span> {{ auditData.realtime_hardware.mqtt_param.MQTopic || auditData.device?.mqtt_topic }}</div>
              <div><span class="text-slate-500 font-sans block text-[10px] uppercase">Cloud Device ID:</span> {{ auditData.realtime_hardware.mqtt_param.MQCloudID || auditData.device?.device_id }}</div>
              <div><span class="text-slate-500 font-sans block text-[10px] uppercase">Heartbeat Interval:</span> {{ auditData.realtime_hardware.mqtt_param.KeepAliveInterval || 30 }}s</div>
              <div><span class="text-slate-500 font-sans block text-[10px] uppercase">Record Upload Mode:</span> {{ auditData.realtime_hardware.mqtt_param.RecordUploadType === 1 ? 'Upload with Photo' : 'Text Only' }}</div>
              <div><span class="text-slate-500 font-sans block text-[10px] uppercase">Breakpoint Resume:</span> {{ auditData.realtime_hardware.mqtt_param.ResumefromBreakpoint === 1 ? 'Active (Reliable)' : 'Disabled' }}</div>
            </div>
            <div v-else class="text-xs text-indigo-700 italic">MQTT parameter telemetry unavailable.</div>
          </div>

          <!-- Real-Time Webhook & Flow Telemetry -->
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 space-y-2">
              <h4 class="text-xs font-bold uppercase tracking-wider text-slate-700">🔔 Webhook Subscriptions (HTTP <code class="font-mono text-indigo-600">/action/GetSubscribe</code>)</h4>
              <div v-if="auditData.realtime_hardware?.subscribe_info" class="text-xs font-mono text-slate-800 space-y-1">
                <div><span class="text-slate-500 font-sans">Server Address:</span> {{ auditData.realtime_hardware.subscribe_info.SubscribeAddr || 'http://192.168.1.10:8000' }}</div>
                <div><span class="text-slate-500 font-sans">Heartbeat Interval:</span> {{ auditData.realtime_hardware.subscribe_info.BeatInterval || 30 }}s</div>
                <div><span class="text-slate-500 font-sans">Active Topics:</span> {{ auditData.realtime_hardware.subscribe_info.Topics?.join(', ') || 'Snap, VerifyWithSnap' }}</div>
              </div>
              <div v-else class="text-xs text-slate-500 italic">Webhook subscription query pending or inactive.</div>
            </div>

            <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 space-y-2">
              <h4 class="text-xs font-bold uppercase tracking-wider text-slate-700">📊 Real-Time Flow Counts (HTTP <code class="font-mono text-indigo-600">/action/GetCount</code>)</h4>
              <div v-if="auditData.realtime_hardware?.flow_count" class="text-xs font-mono text-slate-800 space-y-1">
                <div><span class="text-slate-500 font-sans">Total Passage Count:</span> <strong class="text-indigo-600">{{ auditData.realtime_hardware.flow_count.TotalCount || 0 }}</strong></div>
                <div><span class="text-slate-500 font-sans">Inward Passage:</span> {{ auditData.realtime_hardware.flow_count.InCount || 0 }}</div>
                <div><span class="text-slate-500 font-sans">Outward Passage:</span> {{ auditData.realtime_hardware.flow_count.OutCount || 0 }}</div>
              </div>
              <div v-else class="text-xs text-slate-500 italic">Flow count telemetry zero or disabled.</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Footer -->
      <div class="flex justify-end pt-3 border-t border-slate-200">
        <button @click="$emit('close')" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold rounded-lg border border-slate-300 cursor-pointer">
          Close Audit Window
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue';
import axios from 'axios';
import notify from '../utils/notify';
import { formatDateTime } from '../utils/date';

const props = defineProps({
  isOpen: Boolean,
  device: Object,
});

const emit = defineEmits(['close']);

const loading = ref(false);
const pullingLogs = ref(false);
const activeTab = ref('faces');
const auditData = ref(null);

watch(() => props.isOpen, (newVal) => {
  if (newVal && props.device) {
    fetchAudit();
  }
});

async function fetchAudit() {
  if (!props.device) return;
  loading.value = true;
  try {
    const res = await axios.get(`/api/devices/${props.device.id}/audit`);
    if (res.data.success) {
      auditData.value = res.data;
    } else {
      notify.error('Audit Query Failed', res.data.error || 'Could not query camera via HTTP API.');
    }
  } catch (err) {
    notify.error('Audit Error', err.response?.data?.message || err.message);
  } finally {
    loading.value = false;
  }
}

async function fetchLogsForWindow(hours) {
  if (!props.device) return;
  pullingLogs.value = true;
  try {
    const now = new Date();
    const past = new Date(now.getTime() - hours * 60 * 60 * 1000);

    const formatStr = (d) => d.toISOString().replace('T', ' ').slice(0, 19);

    const res = await axios.post(`/api/devices/${props.device.id}/manual-push-records`, {
      time_s: formatStr(past),
      time_e: formatStr(now),
    });

    if (res.data.success) {
      notify.success('Log Backfill Triggered', `Camera is streaming verification logs from the last ${hours} hour(s) over HTTP/MQTT.`);
      setTimeout(fetchAudit, 2000);
    } else {
      notify.error('Backfill Failed', res.data.error || 'Camera rejected manual push request.');
    }
  } catch (err) {
    notify.error('Backfill Error', err.response?.data?.message || err.message);
  } finally {
    pullingLogs.value = false;
  }
}

const syncingAll = ref(false);

async function syncPersonNow(personnelId) {
  try {
    const res = await axios.post(`/api/personnel/${personnelId}/sync-now`, {
      device_id: props.device.id
    });
    if (res.data.message) {
      notify.success('Sync Task Queued', 'Person push job queued for dispatch to camera.');
      setTimeout(fetchAudit, 1500);
    }
  } catch (err) {
    notify.error('Sync Error', err.response?.data?.message || err.message);
  }
}

async function syncAllMissingPersonnel() {
  if (!auditData.value?.face_audit?.missing_on_camera?.length || !props.device) return;
  syncingAll.value = true;
  let count = 0;
  try {
    for (const person of auditData.value.face_audit.missing_on_camera) {
      await axios.post(`/api/personnel/${person.id}/sync-now`, {
        device_id: props.device.id
      });
      count++;
    }
    notify.success('Bulk Sync Queued', `Queued sync jobs for ${count} missing personnel record(s).`);
    setTimeout(fetchAudit, 2000);
  } catch (err) {
    notify.error('Bulk Sync Error', err.response?.data?.message || err.message);
  } finally {
    syncingAll.value = false;
  }
}
</script>
