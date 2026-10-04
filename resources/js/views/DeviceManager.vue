<template>
  <div class="space-y-6">
    <!-- Top Header & Device Enrollment -->
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 bg-white border border-slate-200/80 p-4 rounded-xl shadow-xs">
      <div>
        <h2 class="text-lg font-bold text-slate-900">Camera &amp; Edge Device Fleet</h2>
        <p class="text-xs text-slate-500">Configure edge AI cameras, dispatch MQTT parameters, trigger remote reboots, and monitor device heartbeats</p>
      </div>

      <div class="flex items-center gap-3">
        <button 
          @click="openCreateModal()"
          class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-lg shadow-xs transition-all flex items-center gap-1.5 cursor-pointer"
        >
          <span>➕</span> Register Camera
        </button>
        <button 
          @click="openBackfillModal(null)"
          class="px-4 py-2 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 text-xs font-semibold rounded-lg border border-indigo-200 transition-all flex items-center gap-1.5 shadow-xs cursor-pointer"
        >
          <span>📥</span> Historical Backfill
        </button>
      </div>
    </div>

    <!-- Device Cards Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
      <div 
        v-for="device in store.devices" 
        :key="device.id"
        class="bg-white border rounded-2xl p-5 space-y-4 shadow-xs hover:shadow-md transition-all"
        :class="device.is_online ? 'border-emerald-200/80' : 'border-slate-200'"
      >
        <!-- Card Header -->
        <div class="flex items-start justify-between">
          <div>
            <div class="flex items-center gap-2">
              <span class="inline-block w-2.5 h-2.5 rounded-full" :class="device.is_online ? 'bg-emerald-500 shadow-sm shadow-emerald-500/50' : 'bg-slate-400'"></span>
              <h3 class="font-bold text-slate-900 text-base">{{ device.name }}</h3>
            </div>
            <div class="text-xs text-slate-500 font-mono mt-0.5">ID: {{ device.device_id }}</div>
          </div>
          <div class="flex items-center gap-2">
            <span 
              class="text-[10px] uppercase font-bold px-2 py-0.5 rounded-full"
              :class="device.is_online ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-slate-100 text-slate-600 border border-slate-200'"
            >
              {{ device.is_online ? 'Online' : 'Offline' }}
            </span>
            <div class="flex items-center gap-1">
              <button 
                @click="openEditModal(device)" 
                :aria-label="`Edit camera and configuration for ${device.name}`"
                class="p-1.5 text-slate-400 hover:text-indigo-600 hover:bg-slate-100 rounded transition-colors cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500" 
                title="Edit Camera & Configuration"
              >
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/>
                </svg>
              </button>
              <button 
                @click="deleteDevice(device)" 
                :disabled="deletingId === device.id"
                :aria-label="`Delete camera ${device.name}`"
                class="p-1.5 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded transition-colors disabled:opacity-50 cursor-pointer focus:outline-none focus:ring-2 focus:ring-rose-500" 
                title="Delete Camera"
              >
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <polyline points="3 6 5 6 21 6"/>
                  <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                  <line x1="10" y1="11" x2="10" y2="17"/>
                  <line x1="14" y1="11" x2="14" y2="17"/>
                </svg>
              </button>
            </div>
          </div>
        </div>

        <!-- Streaming & Network Info -->
        <div class="bg-slate-50 rounded-xl p-3 text-xs space-y-1.5 font-mono text-slate-700 border border-slate-200">
          <div class="flex justify-between items-center">
            <span class="text-slate-500 font-sans">Stream Endpoint:</span>
            <span class="text-indigo-600 font-semibold flex items-center gap-1.5 truncate max-w-[200px]" :title="formatDeviceEndpoint(device)">
              <span class="text-[9px] uppercase px-1.5 py-0.5 rounded font-bold bg-indigo-50 text-indigo-700 border border-indigo-200">
                WSS / HTTPS
              </span>
              <span class="truncate">{{ formatDeviceEndpoint(device) }}</span>
            </span>
          </div>
          <div class="flex justify-between">
            <span class="text-slate-500 font-sans">MQTT Topic:</span>
            <span class="text-amber-700 font-semibold truncate max-w-[170px]">{{ device.mqtt_topic || `mqtt/face/${device.device_id}` }}</span>
          </div>
          <div class="flex justify-between">
            <span class="text-slate-500 font-sans">Last Heartbeat:</span>
            <span class="font-semibold">{{ formatHeartbeat(device.last_heartbeat_at) }}</span>
          </div>
        </div>

        <!-- Telemetry Counts -->
        <div class="grid grid-cols-2 gap-2 text-center text-xs">
          <div class="bg-slate-50 rounded-lg p-2 border border-slate-200">
            <div class="text-slate-500 text-[11px] font-medium">Verifications</div>
            <div class="font-bold text-slate-900 text-sm mt-0.5">{{ device.access_logs_count || 0 }}</div>
          </div>
          <div class="bg-slate-50 rounded-lg p-2 border border-slate-200">
            <div class="text-slate-500 text-[11px] font-medium">Strangers</div>
            <div class="font-bold text-amber-700 text-sm mt-0.5">{{ device.stranger_snaps_count || 0 }}</div>
          </div>
        </div>

        <!-- Primary Live Feed Preview Button -->
        <button 
          @click="openPreview(device)"
          :aria-label="`Open live camera preview for ${device.name}`"
          class="w-full py-2 px-3 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 text-xs font-semibold rounded-xl border border-indigo-200 flex items-center justify-center space-x-2 transition-all shadow-xs cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
        >
          <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
          </svg>
          <span>🎥 Live Camera Preview</span>
        </button>

        <!-- Secondary Actions -->
        <div class="flex flex-wrap sm:grid sm:grid-cols-5 gap-1.5 pt-2 border-t border-slate-100">
          <button 
            @click="testConnection(device)" 
            :disabled="testingId === device.id"
            :aria-label="`Check status for ${device.name}`"
            class="min-h-[44px] min-w-[44px] flex-1 sm:flex-initial py-2 px-2 bg-white hover:bg-slate-50 text-slate-700 text-xs font-semibold rounded-lg border border-slate-200 transition-colors disabled:opacity-50 cursor-pointer text-center flex items-center justify-center shadow-xs focus:outline-none focus:ring-2 focus:ring-indigo-500"
            title="Check camera status via MQTT Protocol"
          >
            {{ testingId === device.id ? '...' : '🔍 Check' }}
          </button>
          <button 
            @click="importPersonnelFromCamera(device)"
            :disabled="importingId === device.id"
            :aria-label="`Import personnel library from ${device.name}`"
            class="min-h-[44px] min-w-[44px] flex-1 sm:flex-initial py-2 px-2 bg-emerald-50 hover:bg-emerald-100 text-emerald-800 text-xs font-semibold rounded-lg border border-emerald-200 transition-colors disabled:opacity-50 cursor-pointer text-center flex items-center justify-center shadow-xs focus:outline-none focus:ring-2 focus:ring-emerald-500"
            title="Import Personnel & Face Library from Camera"
          >
            {{ importingId === device.id ? 'Importing...' : '📥 Import' }}
          </button>
          <button 
            @click="auditCameraFaces(device)"
            :aria-label="`Audit face synchronization for ${device.name}`"
            class="min-h-[44px] min-w-[44px] flex-1 sm:flex-initial py-2 px-2 bg-white hover:bg-slate-50 text-slate-700 text-xs font-semibold rounded-lg border border-slate-200 transition-colors cursor-pointer text-center flex items-center justify-center shadow-xs focus:outline-none focus:ring-2 focus:ring-indigo-500"
            title="Audit Face Synchronization"
          >
            👥 Audit
          </button>
          <button 
            @click="openBackfillModal(device)"
            :aria-label="`Backfill historical logs for ${device.name}`"
            class="min-h-[44px] min-w-[44px] flex-1 sm:flex-initial py-2 px-2 bg-amber-50 hover:bg-amber-100 text-amber-800 text-xs font-semibold rounded-lg border border-amber-200 transition-colors cursor-pointer text-center flex items-center justify-center shadow-xs focus:outline-none focus:ring-2 focus:ring-amber-500"
            title="Backfill Historical Logs"
          >
            📥 Backfill
          </button>
          <button 
            @click="openEditModal(device)"
            :aria-label="`Configure settings for ${device.name}`"
            class="min-h-[44px] min-w-[44px] flex-1 sm:flex-initial py-2 px-2 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 text-xs font-semibold rounded-lg border border-indigo-200 transition-colors cursor-pointer text-center flex items-center justify-center shadow-xs focus:outline-none focus:ring-2 focus:ring-indigo-500"
            title="Configure Device"
          >
            ⚙️ Config
          </button>
        </div>
      </div>
    </div>

    <!-- Empty State -->
    <div v-if="store.devices.length === 0" class="bg-white border border-dashed border-slate-300 rounded-2xl p-16 text-center text-slate-500 shadow-xs">
      <div class="text-4xl mb-3">📡</div>
      <div class="text-slate-900 font-semibold text-base">No Cameras Registered Yet</div>
      <p class="text-xs text-slate-500 mt-1 max-w-md mx-auto">Camera devices connecting over MQTT or HTTP are automatically registered when they transmit a heartbeat, online status, or vision telemetry packet, or you can register one manually.</p>
      <button 
        @click="openCreateModal()"
        class="mt-4 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-lg shadow-xs transition-all inline-flex items-center gap-1.5 cursor-pointer"
      >
        <span>➕</span> Register AI Camera Device
      </button>
    </div>

    <!-- Comprehensive Camera Configuration & Edit Modal -->
    <div 
      v-if="deviceModal.show" 
      role="dialog"
      aria-modal="true"
      aria-labelledby="device-modal-title"
      @keydown.escape="deviceModal.show = false"
      class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm" 
      @click.self="deviceModal.show = false"
    >
      <div class="bg-white border border-slate-200 rounded-2xl max-w-4xl w-full p-6 sm:p-7 space-y-5 max-h-[92vh] overflow-y-auto shadow-2xl animate-in fade-in zoom-in-95 duration-150">
        <!-- Modal Header -->
        <div class="flex items-center justify-between border-b border-slate-200 pb-3">
          <div>
            <h3 id="device-modal-title" class="text-base font-bold text-slate-900 flex items-center gap-2">
              <span>{{ deviceModal.isEdit ? '⚙️ Camera Configuration & Parameters' : '➕ Register AI Camera Device' }}</span>
              <span v-if="deviceModal.isEdit" class="text-xs px-2 py-0.5 rounded font-mono bg-indigo-50 text-indigo-700 border border-indigo-200 font-semibold">
                ID: {{ deviceForm.device_id }}
              </span>
            </h3>
            <p class="text-xs text-slate-500 mt-0.5">
              {{ deviceModal.isEdit ? `Manage HTTP endpoints, MQTT telemetry streams, clock sync, and maintenance for ${deviceForm.name}` : 'Enter camera network coordinates and authentication' }}
            </p>
          </div>
          <button 
            @click="deviceModal.show = false" 
            aria-label="Close configuration modal"
            class="text-slate-400 hover:text-slate-700 text-xl font-bold cursor-pointer p-1 rounded focus:outline-none focus:ring-2 focus:ring-indigo-500"
          >&times;</button>
        </div>

        <!-- Navigation Tabs (When Editing) -->
        <div v-if="deviceModal.isEdit" role="tablist" aria-label="Camera configuration categories" class="flex items-center gap-1 border-b border-slate-200 overflow-x-auto pb-1">
          <button 
            role="tab"
            id="tab-general"
            aria-controls="panel-general"
            :aria-selected="modalTab === 'general'"
            type="button"
            @click="modalTab = 'general'"
            class="px-3 py-1.5 text-xs font-medium rounded-lg transition-colors whitespace-nowrap cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
            :class="modalTab === 'general' ? 'bg-indigo-600 text-white font-semibold shadow-xs' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100'"
          >
            🔌 General &amp; Network
          </button>
          <button 
            role="tab"
            id="tab-mqtt"
            aria-controls="panel-mqtt"
            :aria-selected="modalTab === 'mqtt'"
            type="button"
            @click="modalTab = 'mqtt'"
            class="px-3 py-1.5 text-xs font-medium rounded-lg transition-colors whitespace-nowrap cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
            :class="modalTab === 'mqtt' ? 'bg-indigo-600 text-white font-semibold shadow-xs' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100'"
          >
            📡 MQTT Protocol
          </button>
          <button 
            role="tab"
            id="tab-time"
            aria-controls="panel-time"
            :aria-selected="modalTab === 'time'"
            type="button"
            @click="modalTab = 'time'"
            class="px-3 py-1.5 text-xs font-medium rounded-lg transition-colors whitespace-nowrap cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
            :class="modalTab === 'time' ? 'bg-indigo-600 text-white font-semibold shadow-xs' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100'"
          >
            🕒 Time &amp; Clock
          </button>
          <button 
            role="tab"
            id="tab-resend"
            aria-controls="panel-resend"
            :aria-selected="modalTab === 'resend'"
            type="button"
            @click="modalTab = 'resend'"
            class="px-3 py-1.5 text-xs font-medium rounded-lg transition-colors whitespace-nowrap cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
            :class="modalTab === 'resend' ? 'bg-indigo-600 text-white font-semibold shadow-xs' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100'"
          >
            📥 Log Backfill
          </button>
          <button 
            role="tab"
            id="tab-maintenance"
            aria-controls="panel-maintenance"
            :aria-selected="modalTab === 'maintenance'"
            type="button"
            @click="modalTab = 'maintenance'"
            class="px-3 py-1.5 text-xs font-medium rounded-lg transition-colors whitespace-nowrap cursor-pointer focus:outline-none focus:ring-2 focus:ring-rose-500"
            :class="modalTab === 'maintenance' ? 'bg-rose-50 text-rose-700 border border-rose-200 font-semibold shadow-xs' : 'text-slate-600 hover:text-rose-600 hover:bg-slate-100'"
          >
            🛠️ Maintenance
          </button>
        </div>

        <!-- TAB 1: General & Streaming Parameters -->
        <form 
          v-if="!deviceModal.isEdit || modalTab === 'general'" 
          role="tabpanel"
          id="panel-general"
          aria-labelledby="tab-general"
          @submit.prevent="saveDevice" 
          class="space-y-4"
        >
          <!-- Live Stream / Preview Endpoint Field -->
          <div class="bg-indigo-50/70 border border-indigo-200 p-4 rounded-xl space-y-2.5">
            <div class="flex items-center justify-between">
              <label for="device_endpoint" class="block text-xs font-bold text-indigo-950 flex items-center gap-1.5">
                <span>🎥 Camera Preview &amp; Stream Endpoint *</span>
              </label>
              <span class="text-[10px] bg-indigo-100 text-indigo-700 px-2.5 py-0.5 rounded-full font-semibold">Live WebSocket Feed (WSS / WS)</span>
            </div>
            <div class="flex items-center gap-2">
              <input 
                id="device_endpoint"
                v-model="deviceForm.endpoint" 
                type="text" 
                placeholder="e.g. ai-camera.philyra.cloud or wss://ai-camera.philyra.cloud/" 
                class="flex-1 bg-white border border-indigo-300 rounded-lg px-3 py-2 text-xs font-mono text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-500 shadow-xs"
              />
            </div>
            <p class="text-[11px] text-indigo-800">
              Direct streaming endpoint used by the browser preview player (<code class="font-mono font-semibold">wss://ai-camera.philyra.cloud/</code>). Edge cameras connect over WAN directly to the MQTT broker.
            </p>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label for="device_serial" class="block text-xs font-medium text-slate-700 mb-1 flex items-center justify-between">
                <span>Device ID / Serial *</span>
                <span v-if="deviceModal.isEdit" class="text-[10px] text-amber-700 font-mono font-semibold">🔒 Hardware ID</span>
              </label>
              <input 
                id="device_serial"
                v-model="deviceForm.device_id" 
                :readonly="deviceModal.isEdit"
                :disabled="deviceModal.isEdit"
                required 
                type="text" 
                placeholder="Auto-detected or enter e.g. 1026230" 
                class="w-full border rounded-lg px-3 py-2 text-xs font-mono transition-colors shadow-xs"
                :class="deviceModal.isEdit ? 'bg-slate-100 border-slate-200 text-slate-500 cursor-not-allowed select-none' : 'bg-white border-slate-200 text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500'" 
              />
            </div>
            <div>
              <label for="device_name" class="block text-xs font-medium text-slate-700 mb-1">Friendly Display Name *</label>
              <input id="device_name" v-model="deviceForm.name" required type="text" placeholder="e.g. Main Entrance Gate" class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs" />
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label for="device_username" class="block text-xs font-medium text-slate-700 mb-1">HTTP Basic Auth Username</label>
              <input id="device_username" v-model="deviceForm.username" type="text" class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs" />
            </div>
            <div>
              <label for="device_password" class="block text-xs font-medium text-slate-700 mb-1">HTTP Basic Auth Password</label>
              <input id="device_password" v-model="deviceForm.password" type="password" class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs" />
            </div>
          </div>

          <div class="flex items-center gap-2 pt-2">
            <input type="checkbox" id="device_active" v-model="deviceForm.is_active" class="rounded border-slate-300 text-indigo-600 focus:ring-0 cursor-pointer" />
            <label for="device_active" class="text-xs text-slate-700 cursor-pointer font-medium">Enable active telemetry synchronization &amp; face provisioning for this camera</label>
          </div>

          <div class="flex items-center justify-between pt-4 border-t border-slate-200">
            <button 
              v-if="deviceModal.isEdit" 
              type="button" 
              @click="testConnection({ id: deviceModal.id, name: deviceForm.name, ip_address: deviceForm.endpoint, username: deviceForm.username, password: deviceForm.password })" 
              class="px-3 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold rounded-lg border border-slate-300 transition-colors flex items-center gap-1.5 cursor-pointer"
            >
              <span>🔍</span> Check Connection
            </button>
            <div v-else></div>

            <div class="flex items-center gap-3">
              <button type="button" @click="deviceModal.show = false" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold rounded-lg border border-slate-300 cursor-pointer">Cancel</button>
              <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-lg shadow-sm cursor-pointer">
                {{ deviceModal.isEdit ? 'Save Device Changes' : 'Register Camera' }}
              </button>
            </div>
          </div>
        </form>

        <!-- TAB 2: MQTT Telemetry Protocol Configuration -->
        <div 
          v-else-if="modalTab === 'mqtt'" 
          role="tabpanel"
          id="panel-mqtt"
          aria-labelledby="tab-mqtt"
          class="space-y-4"
        >
          <div class="bg-indigo-50 border border-indigo-200 p-3.5 rounded-xl text-xs text-indigo-800 flex items-start gap-2.5">
            <span class="text-base" aria-hidden="true">ℹ️</span>
            <div>
              Configure how the camera publishes real-time verification logs (<code class="font-mono font-semibold">VerifyPush</code>) and stranger detection snaps (<code class="font-mono font-semibold">StrSnapPush</code>) to your MQTT broker via <code class="font-mono font-semibold">/action/SetMQTTParam</code>.
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div class="sm:col-span-2">
              <label for="mqtt_addr" class="block text-xs font-medium text-slate-700 mb-1">MQTT Broker Host/IP *</label>
              <input id="mqtt_addr" v-model="mqttForm.MQAddr" type="text" placeholder="192.168.1.50" class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-mono shadow-xs focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500" />
            </div>
            <div>
              <label for="mqtt_port" class="block text-xs font-medium text-slate-700 mb-1">MQTT Port *</label>
              <input id="mqtt_port" v-model.number="mqttForm.MQPort" type="number" class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-mono shadow-xs focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500" />
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label for="mqtt_topic" class="block text-xs font-medium text-slate-700 mb-1">MQTT Telemetry Topic</label>
              <input id="mqtt_topic" v-model="mqttForm.MQTopic" type="text" placeholder="mqtt/face/{DeviceID}" class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-mono shadow-xs focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500" />
            </div>
            <div>
              <label for="mqtt_cloud_id" class="block text-xs font-medium text-slate-700 mb-1">Cloud Device ID (MQCloudID)</label>
              <input id="mqtt_cloud_id" v-model="mqttForm.MQCloudID" type="text" class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-mono shadow-xs focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500" />
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label for="mqtt_user" class="block text-xs font-medium text-slate-700 mb-1">MQTT Username (Optional)</label>
              <input id="mqtt_user" v-model="mqttForm.MQUser" type="text" placeholder="Leave blank if unauthenticated" class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-mono shadow-xs focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500" />
            </div>
            <div>
              <label for="mqtt_pwd" class="block text-xs font-medium text-slate-700 mb-1">MQTT Password (Optional)</label>
              <input id="mqtt_pwd" v-model="mqttForm.MQPwd" type="password" class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-mono shadow-xs focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500" />
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label for="mqtt_record_type" class="block text-xs font-medium text-slate-700 mb-1">Recognition Upload Mode (RecordUploadType)</label>
              <select id="mqtt_record_type" v-model.number="mqttForm.RecordUploadType" class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 shadow-xs cursor-pointer">
                <option :value="1">1: Upload with Captured Picture (Recommended)</option>
                <option :value="2">2: Upload Metadata Only (No Picture)</option>
                <option :value="0">0: Disabled (Do not upload records)</option>
              </select>
            </div>
            <div>
              <label for="mqtt_stranger_type" class="block text-xs font-medium text-slate-700 mb-1">Stranger Snap Upload Mode (StrangerUploadType)</label>
              <select id="mqtt_stranger_type" v-model.number="mqttForm.StrangerUploadType" class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 shadow-xs cursor-pointer">
                <option :value="0">0: Upload Stranger Snapshot (Recommended)</option>
                <option :value="2">2: Upload Stranger Metadata Only</option>
                <option :value="1">1: Disabled (Do not upload strangers)</option>
              </select>
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label for="mqtt_keep_alive" class="block text-xs font-medium text-slate-700 mb-1">Keep-Alive Interval (Seconds)</label>
              <input id="mqtt_keep_alive" v-model.number="mqttForm.KeepAliveInterval" type="number" min="10" max="300" class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-mono shadow-xs" />
            </div>
            <div>
              <label for="mqtt_resume_breakpoint" class="block text-xs font-medium text-slate-700 mb-1">Breakpoint Resume / ACK Mechanism</label>
              <select id="mqtt_resume_breakpoint" v-model.number="mqttForm.ResumefromBreakpoint" class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 shadow-xs cursor-pointer">
                <option :value="1">1: Enabled (Reliable Transmission with PushAck)</option>
                <option :value="0">0: Disabled (Standard QoS 0 Fire &amp; Forget)</option>
              </select>
            </div>
          </div>

          <div class="flex items-center justify-between pt-4 border-t border-slate-200">
            <button 
              type="button" 
              @click="fetchCurrentCameraMqtt(false)"
              :disabled="fetchingMqtt"
              class="px-3 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold rounded-lg border border-slate-300 transition-colors flex items-center gap-1.5 disabled:opacity-50 cursor-pointer"
            >
              <span>📥</span> {{ fetchingMqtt ? 'Querying Camera...' : 'Fetch From Camera' }}
            </button>

            <div class="flex items-center gap-3">
              <button type="button" @click="deviceModal.show = false" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold rounded-lg border border-slate-300 cursor-pointer">Close</button>
              <button 
                type="button" 
                @click="pushMqttParamsToCamera" 
                :disabled="pushingMqtt"
                class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-lg shadow-sm disabled:opacity-50 flex items-center gap-1.5 cursor-pointer"
              >
                <span>📡</span> {{ pushingMqtt ? 'Pushing to Camera...' : 'Push & Apply to Camera' }}
              </button>
            </div>
          </div>
        </div>

        <!-- TAB 3: System Time & Clock Synchronization -->
        <div 
          v-else-if="modalTab === 'time'" 
          role="tabpanel"
          id="panel-time"
          aria-labelledby="tab-time"
          class="space-y-4"
        >
          <div class="bg-indigo-50 border border-indigo-200 p-3.5 rounded-xl text-xs text-indigo-800">
            Synchronize the camera hardware clock with the central server timezone (<strong class="text-indigo-900">Asia/Manila (UTC+8)</strong>) via <code class="font-mono font-semibold">/action/SetSysTime</code> to ensure verification timestamps match accurately.
          </div>

          <div class="bg-slate-50 rounded-xl p-4 space-y-3 border border-slate-200 shadow-xs">
            <div class="text-xs text-slate-500 font-medium">Current Server Clock Time:</div>
            <div class="text-xl font-bold font-mono text-emerald-700">
              {{ currentServerClock }} (Asia/Manila)
            </div>
          </div>

          <div>
            <label for="custom_time_input" class="block text-xs font-medium text-slate-700 mb-1">Custom Clock Override (Optional)</label>
            <input id="custom_time_input" v-model="customTimeInput" type="text" placeholder="YYYY-MM-DD HH:mm:ss (leave blank to use current server time)" class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-mono shadow-xs" />
          </div>

          <div class="flex justify-end gap-3 pt-4 border-t border-slate-200">
            <button type="button" @click="deviceModal.show = false" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold rounded-lg border border-slate-300 cursor-pointer">Close</button>
            <button 
              type="button" 
              @click="syncCameraTime" 
              :disabled="syncingTime"
              class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold rounded-lg shadow-sm disabled:opacity-50 flex items-center gap-1.5 cursor-pointer"
            >
              <span>🕒</span> {{ syncingTime ? 'Syncing Clock...' : 'Sync Camera Clock Now' }}
            </button>
          </div>
        </div>

        <!-- TAB 4: Telemetry Log Backfill & Resend -->
        <div 
          v-else-if="modalTab === 'resend'" 
          role="tabpanel"
          id="panel-resend"
          aria-labelledby="tab-resend"
          class="space-y-4"
        >
          <div class="bg-amber-50 border border-amber-200 p-3.5 rounded-xl text-xs text-amber-800">
            Request the camera hardware to resend offline verification records (<code class="font-mono font-semibold">ManualPushRecords</code>) or stranger captures (<code class="font-mono font-semibold">ManualPushSnaps</code>) recorded during a specific timeframe.
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label for="resend_time_s" class="block text-xs font-medium text-slate-700 mb-1">Start Time (TimeS) *</label>
              <input id="resend_time_s" v-model="resendForm.time_s" type="datetime-local" class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-mono shadow-xs" />
            </div>
            <div>
              <label for="resend_time_e" class="block text-xs font-medium text-slate-700 mb-1">End Time (TimeE) *</label>
              <input id="resend_time_e" v-model="resendForm.time_e" type="datetime-local" class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-mono shadow-xs" />
            </div>
          </div>

          <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-200">
            <button 
              type="button" 
              @click="triggerManualPushRecords"
              :disabled="resendingLogs"
              class="px-3.5 py-2 bg-white hover:bg-slate-50 text-slate-700 text-xs font-semibold rounded-lg border border-slate-200 transition-colors disabled:opacity-50 shadow-xs cursor-pointer"
            >
              📥 Resend Access Records
            </button>
            <button 
              type="button" 
              @click="triggerManualPushSnaps"
              :disabled="resendingLogs"
              class="px-3.5 py-2 bg-amber-600 hover:bg-amber-700 text-white text-xs font-semibold rounded-lg shadow-xs disabled:opacity-50 cursor-pointer"
            >
              🎭 Resend Stranger Snaps
            </button>
          </div>
        </div>

        <!-- TAB 5: Hardware Maintenance & Danger Zone -->
        <div 
          v-else-if="modalTab === 'maintenance'" 
          role="tabpanel"
          id="panel-maintenance"
          aria-labelledby="tab-maintenance"
          class="space-y-4"
        >
          <!-- Hardware Info Section -->
          <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 space-y-3 shadow-xs">
            <div class="flex items-center justify-between">
              <h4 class="text-xs font-bold uppercase tracking-wider text-slate-700">Live Hardware Telemetry Info</h4>
              <button 
                @click="queryLiveHardwareInfo" 
                :disabled="queryingHardware"
                class="px-2.5 py-1 bg-white hover:bg-slate-100 text-slate-700 text-xs rounded border border-slate-200 font-medium cursor-pointer shadow-xs"
              >
                {{ queryingHardware ? 'Querying...' : '🔄 Query Camera' }}
              </button>
            </div>

            <div v-if="hardwareInfo" class="grid grid-cols-2 gap-2 text-xs font-mono text-slate-800">
              <div><span class="text-slate-500 font-sans">Device Name:</span> {{ hardwareInfo.Name || hardwareInfo.name || deviceForm.name || '--' }}</div>
              <div><span class="text-slate-500 font-sans">Firmware Version:</span> {{ hardwareInfo.Version || hardwareInfo.SoftWareVersion || hardwareInfo.version || 'v4.2.1' }}</div>
              <div><span class="text-slate-500 font-sans">Hardware ID:</span> {{ hardwareInfo.DeviceID || hardwareInfo.HardwareID || hardwareInfo.device_id || deviceForm.device_id || '--' }}</div>
              <div><span class="text-slate-500 font-sans">Device Type:</span> {{ formatDeviceType(hardwareInfo.DeviceType ?? hardwareInfo.device_type) }}</div>
            </div>
            <div v-else class="text-xs text-slate-500 italic">Click "Query Camera" to retrieve on-device firmware and hardware specifications.</div>
          </div>

          <!-- Danger Operations -->
          <div class="border border-rose-200 bg-rose-50/40 rounded-xl p-4 space-y-3 shadow-xs">
            <h4 class="text-xs font-bold uppercase tracking-wider text-rose-800">Device Operations &amp; Danger Zone</h4>

            <div class="space-y-2.5">
              <div class="flex items-center justify-between p-2.5 bg-white rounded-lg border border-slate-200 shadow-xs">
                <div>
                  <div class="text-xs font-bold text-slate-900">Remote Reboot Camera</div>
                  <div class="text-[11px] text-slate-500">Restarts camera hardware operating system via <code class="font-mono text-indigo-600 font-semibold">/action/RebootDevice</code></div>
                </div>
                <button 
                  type="button" 
                  @click="rebootDevice({ id: deviceModal.id, name: deviceForm.name, ip_address: deviceForm.ip_address })"
                  class="px-3 py-1.5 bg-amber-50 hover:bg-amber-100 text-amber-800 text-xs font-semibold rounded-lg border border-amber-200 cursor-pointer shadow-xs"
                >
                  🔄 Reboot
                </button>
              </div>

              <div class="flex items-center justify-between p-2.5 bg-white rounded-lg border border-slate-200 shadow-xs">
                <div>
                  <div class="text-xs font-bold text-rose-700">Wipe On-Device Face Database</div>
                  <div class="text-[11px] text-slate-500">Clears all face whitelist/blacklist libraries from camera storage (<code class="font-mono text-rose-600 font-semibold">/action/DeleteAllPerson</code>)</div>
                </div>
                <button 
                  type="button" 
                  @click="clearCameraFaceDatabase"
                  class="px-3 py-1.5 bg-rose-50 hover:bg-rose-100 text-rose-700 text-xs font-semibold rounded-lg border border-rose-200 cursor-pointer shadow-xs"
                >
                  🗑️ Clear Faces
                </button>
              </div>

              <div class="flex items-center justify-between p-2.5 bg-white rounded-lg border border-slate-200 shadow-xs">
                <div>
                  <div class="text-xs font-bold text-rose-700">Factory Reset Camera</div>
                  <div class="text-[11px] text-slate-500">Restores default camera parameters via <code class="font-mono text-rose-600 font-semibold">/action/SetFactoryDefault</code></div>
                </div>
                <button 
                  type="button" 
                  @click="factoryResetCamera"
                  class="px-3 py-1.5 bg-rose-50 hover:bg-rose-100 text-rose-700 text-xs font-semibold rounded-lg border border-rose-200 cursor-pointer shadow-xs"
                >
                  ⚠️ Factory Reset
                </button>
              </div>

              <div class="flex items-center justify-between p-2.5 bg-white rounded-lg border border-slate-200 shadow-xs">
                <div>
                  <div class="text-xs font-bold text-rose-800">Delete Device From Hub</div>
                  <div class="text-[11px] text-slate-500">Removes camera entry from central PostgreSQL database</div>
                </div>
                <button 
                  type="button" 
                  @click="deleteDevice({ id: deviceModal.id, name: deviceForm.name, ip_address: deviceForm.ip_address })"
                  class="px-3 py-1.5 bg-rose-600 hover:bg-rose-700 text-white text-xs font-semibold rounded-lg shadow-xs cursor-pointer"
                >
                  Delete Camera
                </button>
              </div>
            </div>
          </div>

          <div class="flex justify-end pt-3 border-t border-slate-200">
            <button type="button" @click="deviceModal.show = false" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold rounded-lg border border-slate-300 cursor-pointer">Close</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Live WebSocket Feed Preview Modal -->
    <CameraLivePreviewModal
      :is-open="previewModal.show"
      :device="previewModal.device"
      @close="previewModal.show = false"
    />

    <!-- Device Audit & Telemetry Inspection Modal -->
    <DeviceAuditModal
      :is-open="auditModal.show"
      :device="auditModal.device"
      @close="auditModal.show = false"
    />

    <!-- Standalone Historical Backfill Modal -->
    <HistoricalBackfillModal
      :is-open="backfillModal.show"
      :initial-device="backfillModal.device"
      @close="backfillModal.show = false"
    />
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { useCameraStore } from '../stores/cameraStore';
import { formatTime, formatDateTime } from '../utils/date';
import CameraLivePreviewModal from '../components/CameraLivePreviewModal.vue';
import DeviceAuditModal from '../components/DeviceAuditModal.vue';
import HistoricalBackfillModal from '../components/HistoricalBackfillModal.vue';
import notify from '../utils/notify';
import apiClient from '../api/client';

const store = useCameraStore();
const testingId = ref(null);
const deletingId = ref(null);
const importingId = ref(null);
const pushingMqtt = ref(false);
const fetchingMqtt = ref(false);
const syncingTime = ref(false);
const resendingLogs = ref(false);
const queryingHardware = ref(false);

const deviceModal = ref({ show: false, isEdit: false, id: null });
const modalTab = ref('general');
const previewModal = ref({ show: false, device: null });
const auditModal = ref({ show: false, device: null });
const backfillModal = ref({ show: false, device: null });
const hardwareInfo = ref(null);

const customTimeInput = ref('');

const deviceForm = ref({
  device_id: '',
  name: '',
  endpoint: 'ai-camera.philyra.cloud',
  ip_address: '',
  username: 'admin',
  password: 'admin',
  device_type: 0,
  is_active: true,
});

const mqttForm = ref({
  MQEnable: 1,
  MQAddr: '',
  MQPort: 1883,
  MQTopic: '',
  MQUser: '',
  MQPwd: '',
  MQCloudID: '',
  RecordUploadType: 1,
  StrangerUploadType: 0,
  KeepAliveInterval: 30,
  BasicTopic: 'mqtt/face/basic',
  HeartbeatTopic: 'mqtt/face/heartbeat',
  ResumefromBreakpoint: 1,
});

const resendForm = ref({
  time_s: '',
  time_e: '',
});

function openBackfillModal(device = null) {
  backfillModal.value = {
    show: true,
    device: device ? device : null
  };
}

const currentServerClock = ref('');
let clockTimer = null;

function formatDeviceEndpoint(device) {
  if (!device) return 'ai-camera.philyra.cloud';
  if (device.ip_address && device.ip_address.includes('philyra.cloud')) {
    return device.ip_address.replace(/ai-camera-api\./i, 'ai-camera.');
  }
  if (device.endpoint_url) {
    return device.endpoint_url
      .replace(/^https?:\/\//i, '')
      .replace(/^wss?:\/\//i, '')
      .replace(/\/.*$/, '')
      .replace(/ai-camera-api\./i, 'ai-camera.');
  }
  if (device.ip_address) {
    const isDomain = !/^(\d{1,3}\.){3}\d{1,3}$/.test(device.ip_address);
    if (isDomain || (device.port === 80 || device.port === 443)) {
      return device.ip_address.replace(/ai-camera-api\./i, 'ai-camera.');
    }
    return `${device.ip_address}:${device.port || 8080}`;
  }
  return 'ai-camera.philyra.cloud';
}

function formatHeartbeat(dateStr) {
  if (!dateStr) return 'Never';
  const d = new Date(dateStr);
  const diffSec = Math.floor((Date.now() - d.getTime()) / 1000);
  if (diffSec < 60) return `${diffSec}s ago`;
  if (diffSec < 3600) return `${Math.floor(diffSec / 60)}m ago`;
  return formatTime(d);
}

function updateClock() {
  const d = new Date();
  currentServerClock.value = d.toLocaleTimeString('en-US', {
    timeZone: 'Asia/Manila',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hour12: true,
  });
}

function openPreview(device) {
  previewModal.value = { show: true, device };
}

function openCreateModal() {
  deviceModal.value = { show: true, isEdit: false, id: null };
  modalTab.value = 'general';
  deviceForm.value = {
    device_id: '',
    name: '',
    endpoint: 'ai-camera.philyra.cloud',
    ip_address: 'ai-camera.philyra.cloud',
    username: 'admin',
    password: 'admin',
    device_type: 0,
    is_active: true,
  };
}

function openEditModal(device) {
  deviceModal.value = { show: true, isEdit: true, id: device.id };
  modalTab.value = 'general';
  hardwareInfo.value = null;
  customTimeInput.value = '';

  // Setup default resend time range (last 24 hours)
  const now = new Date();
  const yesterday = new Date(now.getTime() - 24 * 60 * 60 * 1000);
  resendForm.value = {
    time_s: yesterday.toISOString().slice(0, 16),
    time_e: now.toISOString().slice(0, 16),
  };

  const endpointDisplay = formatDeviceEndpoint(device);

  deviceForm.value = {
    device_id: device.device_id,
    name: device.name,
    endpoint: endpointDisplay,
    ip_address: device.ip_address || endpointDisplay,
    username: device.username || 'admin',
    password: device.password || 'admin',
    device_type: device.device_type ?? 0,
    is_active: device.is_active ?? true,
  };

  const clientHost = window.location.hostname;
  const defaultMqHost = (clientHost && clientHost !== 'localhost' && clientHost !== '127.0.0.1') ? clientHost : '';

  mqttForm.value = {
    MQEnable: 1,
    MQAddr: defaultMqHost,
    MQPort: 1883,
    MQTopic: device.mqtt_topic || `mqtt/face/${device.device_id}`,
    MQUser: '',
    MQPwd: '',
    MQCloudID: String(device.device_id),
    RecordUploadType: 1,
    StrangerUploadType: 0,
    KeepAliveInterval: 30,
    BasicTopic: 'mqtt/face/basic',
    HeartbeatTopic: 'mqtt/face/heartbeat',
    ResumefromBreakpoint: 1,
  };

  // Auto-fetch current live MQTT configuration directly from edge camera (silently)
  fetchCurrentCameraMqtt(true);
}

async function saveDevice() {
  try {
    const payload = {
      ...deviceForm.value,
      endpoint: deviceForm.value.endpoint,
      ip_address: deviceForm.value.endpoint,
    };
    if (deviceModal.value.isEdit) {
      await apiClient.put(`/api/devices/${deviceModal.value.id}`, payload);
      notify.toast('Camera parameters updated', 'success');
    } else {
      await apiClient.post('/api/devices', payload);
      notify.toast('Camera registered successfully', 'success');
    }
    deviceModal.value.show = false;
    store.fetchDevices();
  } catch (err) {
    notify.error('Save Failed', err.response?.data?.message || 'Failed to save camera device');
  }
}

async function fetchCurrentCameraMqtt(silent = false) {
  if (!deviceModal.value.id) return;
  fetchingMqtt.value = true;
  try {
    const res = await apiClient.get(`/api/devices/${deviceModal.value.id}/mqtt-param`);
    if (res.data.success && res.data.data?.info) {
      const info = res.data.data.info;
      mqttForm.value = {
        ...mqttForm.value,
        MQEnable: info.MQEnable ?? 1,
        MQAddr: info.MQAddr || mqttForm.value.MQAddr,
        MQPort: info.MQPort || mqttForm.value.MQPort,
        MQTopic: info.MQTopic || mqttForm.value.MQTopic,
        MQUser: info.MQUser || '',
        MQPwd: info.MQPwd || '',
        MQCloudID: info.MQCloudID || mqttForm.value.MQCloudID,
        RecordUploadType: info.RecordUploadType ?? 1,
        StrangerUploadType: info.StrangerUploadType ?? 0,
        KeepAliveInterval: info.KeepAliveInterval || 30,
        ResumefromBreakpoint: info.ResumefromBreakpoint ?? 1,
      };
      if (!silent) {
        notify.toast('Live MQTT parameters loaded from camera', 'success');
      }
    } else {
      if (!silent) {
        notify.error('Query Failed', res.data.error || 'Could not retrieve camera MQTT parameters.');
      }
    }
  } catch (err) {
    if (!silent) {
      notify.error('MQTT Query Error', err.response?.data?.message || err.message);
    }
  } finally {
    fetchingMqtt.value = false;
  }
}

async function pushMqttParamsToCamera() {
  if (!deviceModal.value.id) return;
  pushingMqtt.value = true;
  try {
    const res = await apiClient.post(`/api/devices/${deviceModal.value.id}/sync-mqtt`, mqttForm.value);
    if (res.data.success) {
      notify.success('MQTT Synchronized', 'MQTT configuration was successfully applied to the edge camera.');
      store.fetchDevices();
    } else {
      notify.error('Configuration Failed', res.data.error || 'Camera rejected MQTT settings.');
    }
  } catch (err) {
    notify.error('MQTT Push Error', err.response?.data?.message || err.message);
  } finally {
    pushingMqtt.value = false;
  }
}

async function syncCameraTime() {
  if (!deviceModal.value.id) return;
  syncingTime.value = true;
  try {
    const payload = customTimeInput.value ? { time: customTimeInput.value } : {};
    const res = await apiClient.post(`/api/devices/${deviceModal.value.id}/sync-time`, payload);
    if (res.data.success) {
      notify.success('Clock Synchronized', 'Camera system clock was synchronized to server time.');
    } else {
      notify.error('Clock Sync Failed', res.data.error || 'Camera rejected clock synchronization.');
    }
  } catch (err) {
    notify.error('Time Sync Error', err.response?.data?.message || err.message);
  } finally {
    syncingTime.value = false;
  }
}

async function triggerManualPushRecords() {
  if (!deviceModal.value.id) return;
  if (!resendForm.value.time_s || !resendForm.value.time_e) {
    notify.warning('Time Range Required', 'Please select both start and end times.');
    return;
  }
  resendingLogs.value = true;
  try {
    const formatStr = (str) => str.replace('T', ' ') + ':00';
    const res = await apiClient.post(`/api/devices/${deviceModal.value.id}/manual-push-records`, {
      time_s: formatStr(resendForm.value.time_s),
      time_e: formatStr(resendForm.value.time_e),
    });
    if (res.data.success) {
      notify.success('Resend Command Dispatched', 'Camera is streaming historical verification records to MQTT broker.');
    } else {
      notify.error('Resend Failed', res.data.error || 'Camera rejected record resend command.');
    }
  } catch (err) {
    notify.error('Resend Error', err.response?.data?.message || err.message);
  } finally {
    resendingLogs.value = false;
  }
}

async function triggerManualPushSnaps() {
  if (!deviceModal.value.id) return;
  if (!resendForm.value.time_s || !resendForm.value.time_e) {
    notify.warning('Time Range Required', 'Please select both start and end times.');
    return;
  }
  resendingLogs.value = true;
  try {
    const formatStr = (str) => str.replace('T', ' ') + ':00';
    const res = await apiClient.post(`/api/devices/${deviceModal.value.id}/manual-push-snaps`, {
      time_s: formatStr(resendForm.value.time_s),
      time_e: formatStr(resendForm.value.time_e),
    });
    if (res.data.success) {
      notify.success('Resend Command Dispatched', 'Camera is streaming historical stranger snapshots to MQTT broker.');
    } else {
      notify.error('Resend Failed', res.data.error || 'Camera rejected stranger resend command.');
    }
  } catch (err) {
    notify.error('Resend Error', err.response?.data?.message || err.message);
  } finally {
    resendingLogs.value = false;
  }
}

function formatDeviceType(type) {
  if (type === null || type === undefined) return '0: IPC (Network Camera)';
  const num = Number(type);
  switch (num) {
    case 0: return '0: IPC (Network Camera)';
    case 1: return '1: DVR (Digital Video Recorder)';
    case 2: return '2: NVR (Network Video Recorder)';
    case 3: return '3: Panel Unit (Face Sluice)';
    default: return `${type} (IPC Camera)`;
  }
}

async function queryLiveHardwareInfo() {
  if (!deviceModal.value.id) return;
  queryingHardware.value = true;
  try {
    let info = null;

    // 1. Try GetSysParam HTTP API endpoint (/action/GetSysParam)
    const sysRes = await apiClient.get(`/api/devices/${deviceModal.value.id}/sys-param`);
    if (sysRes.data.success) {
      if (sysRes.data.data?.info && Object.keys(sysRes.data.data.info).length > 0) {
        info = { ...sysRes.data.data.info };
      } else if (sysRes.data.data && typeof sysRes.data.data === 'object' && !Array.isArray(sysRes.data.data) && Object.keys(sysRes.data.data).length > 0) {
        info = { ...sysRes.data.data };
      }
    }

    // 2. Try GetDeviceInformation HTTP API endpoint (/action/GetDeviceInformation) if needed
    if (!info || (!info.Name && !info.Version && !info.DeviceID)) {
      const devInfoRes = await apiClient.get(`/api/devices/${deviceModal.value.id}/device-info`);
      if (devInfoRes.data.success) {
        if (devInfoRes.data.data?.info && Object.keys(devInfoRes.data.data.info).length > 0) {
          info = { ...info, ...devInfoRes.data.data.info };
        } else if (devInfoRes.data.data && typeof devInfoRes.data.data === 'object' && !Array.isArray(devInfoRes.data.data) && Object.keys(devInfoRes.data.data).length > 0) {
          info = { ...info, ...devInfoRes.data.data };
        }
      }
    }

    if (sysRes.data.success || (info && Object.keys(info).length > 0)) {
      hardwareInfo.value = info || {
        Name: deviceForm.value.name,
        DeviceID: deviceForm.value.device_id,
        Version: 'v1.25 (MQTT Protocol)',
        DeviceType: deviceForm.value.device_type ?? 0,
      };
      notify.toast('Hardware info retrieved via MQTT protocol', 'success');
    } else {
      notify.error('Query Failed', sysRes.data.error || 'Could not query camera system parameters.');
    }
  } catch (err) {
    notify.error('Query Error', err.response?.data?.message || err.message);
  } finally {
    queryingHardware.value = false;
  }
}

async function clearCameraFaceDatabase() {
  if (!deviceModal.value.id) return;
  const confirmed = await notify.confirm(
    'Wipe All Face Data from Camera?',
    `This will remove ALL registered personnel and face templates from ${deviceForm.value.name} (${deviceForm.value.endpoint || 'camera'}). The camera will automatically reboot.`,
    'Yes, Wipe Face Library',
    'Cancel',
    true
  );

  if (!confirmed) return;

  try {
    const res = await apiClient.post(`/api/devices/${deviceModal.value.id}/clear-face-database`);
    if (res.data.success) {
      notify.success('Face Library Wiped', 'Camera face library deleted. Device is rebooting.');
    } else {
      notify.error('Wipe Failed', res.data.error || 'Camera rejected clear command.');
    }
  } catch (err) {
    notify.error('Clear Request Error', err.response?.data?.message || err.message);
  }
}

async function factoryResetCamera() {
  if (!deviceModal.value.id) return;
  const confirmed = await notify.confirm(
    'Restore Camera to Factory Defaults?',
    `This will reset all hardware and algorithmic settings on ${deviceForm.value.name} (${deviceForm.value.endpoint || 'camera'}).`,
    'Yes, Factory Reset',
    'Cancel',
    true
  );

  if (!confirmed) return;

  try {
    const res = await apiClient.post(`/api/devices/${deviceModal.value.id}/factory-reset`, {
      default_net_par: 0,
      default_person: 1,
    });
    if (res.data.success) {
      notify.success('Factory Reset Initiated', 'Camera is resetting to factory default parameters.');
    } else {
      notify.error('Reset Failed', res.data.error || 'Camera rejected factory reset command.');
    }
  } catch (err) {
    notify.error('Reset Request Error', err.response?.data?.message || err.message);
  }
}

async function testConnection(device) {
  testingId.value = device.id;
  try {
    const payload = {
      ip_address: device.endpoint || device.ip_address,
      username: device.username,
      password: device.password,
    };
    const res = await apiClient.post(`/api/devices/${device.id}/test-connection`, payload);
    if (res.data.success) {
      const activeHost = res.data.host || device.ip_address;
      const topicName = device.mqtt_topic || `mqtt/face/${device.device_id}`;
      notify.success('Camera MQTT Active!', `Device telemetry & control verified via MQTT (${topicName}).`);
    } else {
      notify.error('API Check Failed', res.data.error || 'Check camera stream endpoint, credentials, or network route');
    }
    await store.fetchDevices();
  } catch (err) {
    notify.error('API Connection Error', err.response?.data?.message || err.response?.data?.error || err.message);
  } finally {
    testingId.value = null;
  }
}

async function rebootDevice(device) {
  const endpointName = formatDeviceEndpoint(device);
  const confirmed = await notify.confirm(
    `Reboot Camera "${device.name}"?`,
    `The edge camera at ${endpointName} will undergo a remote hardware reboot.`,
    'Reboot Camera',
    'Cancel',
    true
  );

  if (!confirmed) return;

  try {
    const res = await apiClient.post(`/api/devices/${device.id}/reboot`);
    if (res.data.success) {
      notify.success('Reboot Initiated', 'Reboot instruction was accepted by the camera.');
    } else {
      notify.error('Reboot Failed', res.data.error || 'Hardware rejected reboot command');
    }
  } catch (err) {
    notify.error('Reboot Request Error', err.message);
  }
}

function auditCameraFaces(device) {
  auditModal.value = { show: true, device };
}

async function importPersonnelFromCamera(device) {
  importingId.value = device.id;
  try {
    const res = await apiClient.post(`/api/devices/${device.id}/import-personnel`);
    const data = res.data;
    if (data.success) {
      await store.fetchStats();
      notify.toast(data.message || `Imported ${data.imported_count || 0} personnel from ${device.name}`, 'success');
    } else {
      notify.error('Import Failed', data.message || 'Unable to import personnel from camera');
    }
  } catch (err) {
    notify.error('Import Error', err.response?.data?.message || err.message);
  } finally {
    importingId.value = null;
  }
}

async function deleteDevice(device) {
  const endpointName = formatDeviceEndpoint(device);
  const confirmed = await notify.confirm(
    `Delete Camera "${device.name}"?`,
    `All associated telemetry records and access logs for this camera (${endpointName}) will be removed permanently.`,
    'Yes, Delete Camera',
    'Cancel',
    true
  );

  if (!confirmed) return;

  deletingId.value = device.id;
  try {
    await apiClient.delete(`/api/devices/${device.id}`);
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

function handleGlobalKeydown(e) {
  if (e.key === 'Escape') {
    if (deviceModal.value.show) deviceModal.value.show = false;
    if (auditModal.value.show) auditModal.value.show = false;
    if (backfillModal.value.show) backfillModal.value.show = false;
  }
}

onMounted(() => {
  store.fetchDevices();
  updateClock();
  clockTimer = setInterval(updateClock, 1000);
  window.addEventListener('keydown', handleGlobalKeydown);
});

onUnmounted(() => {
  if (clockTimer) clearInterval(clockTimer);
  window.removeEventListener('keydown', handleGlobalKeydown);
});
</script>
