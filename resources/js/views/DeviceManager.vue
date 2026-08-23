<template>
  <div class="space-y-6">
    <!-- Top Header & Device Enrollment -->
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 bg-slate-900/80 border border-slate-800 p-4 rounded-xl backdrop-blur-md">
      <div>
        <h2 class="text-lg font-semibold text-slate-100">Camera &amp; Edge Device Fleet</h2>
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
                class="p-1.5 text-slate-400 hover:text-indigo-400 hover:bg-slate-800 rounded transition-colors cursor-pointer" 
                title="Edit Camera & Configuration"
              >
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/>
                </svg>
              </button>
              <button 
                @click="deleteDevice(device)" 
                :disabled="deletingId === device.id"
                class="p-1.5 text-slate-400 hover:text-rose-400 hover:bg-slate-800 rounded transition-colors disabled:opacity-50 cursor-pointer" 
                title="Delete Camera"
              >
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
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
            <span class="text-slate-500 font-sans">MQTT Topic:</span>
            <span class="text-amber-400 truncate max-w-[170px]">{{ device.mqtt_topic || `mqtt/face/${device.device_id}` }}</span>
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
        <div class="grid grid-cols-3 gap-2 pt-2 border-t border-slate-800/80">
          <button 
            @click="testConnection(device)" 
            :disabled="testingId === device.id"
            class="py-1.5 px-2 bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-medium rounded-lg border border-slate-700 transition-colors disabled:opacity-50 cursor-pointer text-center truncate"
          >
            {{ testingId === device.id ? 'Testing...' : '📡 Ping' }}
          </button>
          <button 
            @click="auditCameraFaces(device)"
            class="py-1.5 px-2 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-medium rounded-lg border border-slate-700 transition-colors cursor-pointer text-center truncate"
          >
            👥 Audit
          </button>
          <button 
            @click="openEditModal(device)"
            class="py-1.5 px-2 bg-indigo-600/20 hover:bg-indigo-600/30 text-indigo-300 text-xs font-medium rounded-lg border border-indigo-500/30 transition-colors cursor-pointer text-center truncate"
          >
            ⚙️ Config
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

    <!-- Comprehensive Camera Configuration & Edit Modal -->
    <div v-if="deviceModal.show" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/85 backdrop-blur-md" @click.self="deviceModal.show = false">
      <div class="bg-slate-900 border border-slate-700 rounded-2xl max-w-4xl w-full p-6 sm:p-7 space-y-5 max-h-[92vh] overflow-y-auto shadow-2xl">
        <!-- Modal Header -->
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
          <div>
            <h3 class="text-base font-semibold text-slate-100 flex items-center gap-2">
              <span>{{ deviceModal.isEdit ? '⚙️ Camera Configuration & Parameters' : '➕ Register AI Camera Device' }}</span>
              <span v-if="deviceModal.isEdit" class="text-xs px-2 py-0.5 rounded font-mono bg-indigo-500/10 text-indigo-400 border border-indigo-500/20">
                ID: {{ deviceForm.device_id }}
              </span>
            </h3>
            <p class="text-xs text-slate-400 mt-0.5">
              {{ deviceModal.isEdit ? `Manage HTTP endpoints, MQTT telemetry streams, clock sync, and maintenance for ${deviceForm.name}` : 'Enter camera network coordinates and authentication' }}
            </p>
          </div>
          <button @click="deviceModal.show = false" class="text-slate-400 hover:text-white text-xl font-bold">&times;</button>
        </div>

        <!-- Navigation Tabs (When Editing) -->
        <div v-if="deviceModal.isEdit" class="flex items-center gap-1 border-b border-slate-800 overflow-x-auto pb-1">
          <button 
            type="button"
            @click="modalTab = 'general'"
            class="px-3 py-1.5 text-xs font-medium rounded-lg transition-colors whitespace-nowrap"
            :class="modalTab === 'general' ? 'bg-indigo-600 text-white font-semibold shadow' : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800'"
          >
            🔌 General &amp; Network
          </button>
          <button 
            type="button"
            @click="modalTab = 'mqtt'"
            class="px-3 py-1.5 text-xs font-medium rounded-lg transition-colors whitespace-nowrap"
            :class="modalTab === 'mqtt' ? 'bg-indigo-600 text-white font-semibold shadow' : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800'"
          >
            📡 MQTT Protocol
          </button>
          <button 
            type="button"
            @click="modalTab = 'time'"
            class="px-3 py-1.5 text-xs font-medium rounded-lg transition-colors whitespace-nowrap"
            :class="modalTab === 'time' ? 'bg-indigo-600 text-white font-semibold shadow' : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800'"
          >
            🕒 Time &amp; Clock
          </button>
          <button 
            type="button"
            @click="modalTab = 'resend'"
            class="px-3 py-1.5 text-xs font-medium rounded-lg transition-colors whitespace-nowrap"
            :class="modalTab === 'resend' ? 'bg-indigo-600 text-white font-semibold shadow' : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800'"
          >
            📥 Log Backfill
          </button>
          <button 
            type="button"
            @click="modalTab = 'maintenance'"
            class="px-3 py-1.5 text-xs font-medium rounded-lg transition-colors whitespace-nowrap"
            :class="modalTab === 'maintenance' ? 'bg-rose-900/60 text-rose-200 font-semibold shadow' : 'text-slate-400 hover:text-rose-400 hover:bg-slate-800'"
          >
            🛠️ Maintenance
          </button>
        </div>

        <!-- TAB 1: General & Network Parameters -->
        <form v-if="!deviceModal.isEdit || modalTab === 'general'" @submit.prevent="saveDevice" class="space-y-4">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-medium text-slate-300 mb-1 flex items-center justify-between">
                <span>Device ID / Serial *</span>
                <span v-if="deviceModal.isEdit" class="text-[10px] text-amber-400 font-mono">🔒 Hardware ID (Immutable)</span>
              </label>
              <input 
                v-model="deviceForm.device_id" 
                :readonly="deviceModal.isEdit"
                :disabled="deviceModal.isEdit"
                required 
                type="text" 
                placeholder="e.g. 1026230 or 005a213b000b93cc" 
                class="w-full border rounded-lg px-3 py-2 text-xs font-mono transition-colors"
                :class="deviceModal.isEdit ? 'bg-slate-900/80 border-slate-800 text-slate-400 cursor-not-allowed select-none' : 'bg-slate-800 border-slate-700 text-slate-100 focus:ring-1 focus:ring-indigo-500'" 
              />
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-300 mb-1">Friendly Display Name *</label>
              <input v-model="deviceForm.name" required type="text" placeholder="e.g. Main Entrance Gate" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-xs text-slate-100 focus:ring-1 focus:ring-indigo-500" />
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div class="sm:col-span-2">
              <label class="block text-xs font-medium text-slate-300 mb-1">Camera LAN IP Address *</label>
              <input v-model="deviceForm.ip_address" required type="text" placeholder="192.168.1.100" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-xs text-slate-100 font-mono focus:ring-1 focus:ring-indigo-500" />
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-300 mb-1">HTTP Port *</label>
              <input v-model.number="deviceForm.port" type="number" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-xs text-slate-100 font-mono focus:ring-1 focus:ring-indigo-500" />
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-medium text-slate-300 mb-1">HTTP Basic Auth Username</label>
              <input v-model="deviceForm.username" type="text" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-xs text-slate-100 font-mono" />
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-300 mb-1">HTTP Basic Auth Password</label>
              <input v-model="deviceForm.password" type="password" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-xs text-slate-100 font-mono" />
            </div>
          </div>

          <div class="flex items-center gap-2 pt-2">
            <input type="checkbox" id="device_active" v-model="deviceForm.is_active" class="rounded bg-slate-800 border-slate-700 text-indigo-600 focus:ring-0" />
            <label for="device_active" class="text-xs text-slate-300 cursor-pointer">Enable active telemetry synchronization &amp; face provisioning for this camera</label>
          </div>

          <div class="flex items-center justify-between pt-4 border-t border-slate-800">
            <button 
              v-if="deviceModal.isEdit" 
              type="button" 
              @click="testConnection({ id: deviceModal.id, name: deviceForm.name, ip_address: deviceForm.ip_address, port: deviceForm.port })" 
              class="px-3 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-medium rounded-lg border border-slate-700 transition-colors flex items-center gap-1.5"
            >
              <span>📡</span> Test Live HTTP Connection
            </button>
            <div v-else></div>

            <div class="flex items-center gap-3">
              <button type="button" @click="deviceModal.show = false" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-medium rounded-lg">Cancel</button>
              <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold rounded-lg shadow-lg shadow-indigo-600/20">
                {{ deviceModal.isEdit ? 'Save Device Changes' : 'Register Camera' }}
              </button>
            </div>
          </div>
        </form>

        <!-- TAB 2: MQTT Telemetry Protocol Configuration -->
        <div v-else-if="modalTab === 'mqtt'" class="space-y-4">
          <div class="bg-indigo-950/20 border border-indigo-500/20 p-3.5 rounded-xl text-xs text-indigo-300 flex items-start gap-2.5">
            <span class="text-base">ℹ️</span>
            <div>
              Configure how the camera publishes real-time verification logs (<code class="font-mono">VerifyPush</code>) and stranger detection snaps (<code class="font-mono">StrSnapPush</code>) to your MQTT broker via <code class="font-mono">/action/SetMQTTParam</code>.
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div class="sm:col-span-2">
              <label class="block text-xs font-medium text-slate-300 mb-1">MQTT Broker Host/IP *</label>
              <input v-model="mqttForm.MQAddr" type="text" placeholder="192.168.1.50" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-xs text-slate-100 font-mono" />
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-300 mb-1">MQTT Port *</label>
              <input v-model.number="mqttForm.MQPort" type="number" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-xs text-slate-100 font-mono" />
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-medium text-slate-300 mb-1">MQTT Telemetry Topic</label>
              <input v-model="mqttForm.MQTopic" type="text" placeholder="mqtt/face/{DeviceID}" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-xs text-slate-100 font-mono" />
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-300 mb-1">Cloud Device ID (MQCloudID)</label>
              <input v-model="mqttForm.MQCloudID" type="text" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-xs text-slate-100 font-mono" />
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-medium text-slate-300 mb-1">MQTT Username (Optional)</label>
              <input v-model="mqttForm.MQUser" type="text" placeholder="Leave blank if unauthenticated" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-xs text-slate-100 font-mono" />
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-300 mb-1">MQTT Password (Optional)</label>
              <input v-model="mqttForm.MQPwd" type="password" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-xs text-slate-100 font-mono" />
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-medium text-slate-300 mb-1">Recognition Upload Mode (RecordUploadType)</label>
              <select v-model.number="mqttForm.RecordUploadType" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-xs text-slate-100">
                <option :value="1">1: Upload with Captured Picture (Recommended)</option>
                <option :value="2">2: Upload Metadata Only (No Picture)</option>
                <option :value="0">0: Disabled (Do not upload records)</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-300 mb-1">Stranger Snap Upload Mode (StrangerUploadType)</label>
              <select v-model.number="mqttForm.StrangerUploadType" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-xs text-slate-100">
                <option :value="0">0: Upload Stranger Snapshot (Recommended)</option>
                <option :value="2">2: Upload Stranger Metadata Only</option>
                <option :value="1">1: Disabled (Do not upload strangers)</option>
              </select>
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-medium text-slate-300 mb-1">Keep-Alive Interval (Seconds)</label>
              <input v-model.number="mqttForm.KeepAliveInterval" type="number" min="10" max="300" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-xs text-slate-100 font-mono" />
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-300 mb-1">Breakpoint Resume / ACK Mechanism</label>
              <select v-model.number="mqttForm.ResumefromBreakpoint" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-xs text-slate-100">
                <option :value="1">1: Enabled (Reliable Transmission with PushAck)</option>
                <option :value="0">0: Disabled (Standard QoS 0 Fire &amp; Forget)</option>
              </select>
            </div>
          </div>

          <div class="flex items-center justify-between pt-4 border-t border-slate-800">
            <button 
              type="button" 
              @click="fetchCurrentCameraMqtt"
              :disabled="fetchingMqtt"
              class="px-3 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-medium rounded-lg border border-slate-700 transition-colors flex items-center gap-1.5 disabled:opacity-50"
            >
              <span>📥</span> {{ fetchingMqtt ? 'Querying Camera...' : 'Fetch From Camera' }}
            </button>

            <div class="flex items-center gap-3">
              <button type="button" @click="deviceModal.show = false" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-medium rounded-lg">Close</button>
              <button 
                type="button" 
                @click="pushMqttParamsToCamera" 
                :disabled="pushingMqtt"
                class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold rounded-lg shadow-lg shadow-indigo-600/20 disabled:opacity-50 flex items-center gap-1.5"
              >
                <span>📡</span> {{ pushingMqtt ? 'Pushing to Camera...' : 'Push & Apply to Camera' }}
              </button>
            </div>
          </div>
        </div>

        <!-- TAB 3: System Time & Clock Synchronization -->
        <div v-else-if="modalTab === 'time'" class="space-y-4">
          <div class="bg-indigo-950/20 border border-indigo-500/20 p-3.5 rounded-xl text-xs text-indigo-300">
            Synchronize the camera hardware clock with the central server timezone (<strong class="text-indigo-200">Asia/Manila (UTC+8)</strong>) via <code class="font-mono">/action/SetSysTime</code> to ensure verification timestamps match accurately.
          </div>

          <div class="bg-slate-800/40 rounded-xl p-4 space-y-3">
            <div class="text-xs text-slate-400">Current Server Clock Time:</div>
            <div class="text-xl font-bold font-mono text-emerald-400">
              {{ currentServerClock }} (Asia/Manila)
            </div>
          </div>

          <div>
            <label class="block text-xs font-medium text-slate-300 mb-1">Custom Clock Override (Optional)</label>
            <input v-model="customTimeInput" type="text" placeholder="YYYY-MM-DD HH:mm:ss (leave blank to use current server time)" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-xs text-slate-100 font-mono" />
          </div>

          <div class="flex justify-end gap-3 pt-4 border-t border-slate-800">
            <button type="button" @click="deviceModal.show = false" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-medium rounded-lg">Close</button>
            <button 
              type="button" 
              @click="syncCameraTime" 
              :disabled="syncingTime"
              class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-semibold rounded-lg shadow-lg shadow-emerald-600/20 disabled:opacity-50 flex items-center gap-1.5"
            >
              <span>🕒</span> {{ syncingTime ? 'Syncing Clock...' : 'Sync Camera Clock Now' }}
            </button>
          </div>
        </div>

        <!-- TAB 4: Telemetry Log Backfill & Resend -->
        <div v-else-if="modalTab === 'resend'" class="space-y-4">
          <div class="bg-amber-950/20 border border-amber-500/20 p-3.5 rounded-xl text-xs text-amber-300">
            Request the camera hardware to resend offline verification records (<code class="font-mono">ManualPushRecords</code>) or stranger captures (<code class="font-mono">ManualPushSnaps</code>) recorded during a specific timeframe.
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-medium text-slate-300 mb-1">Start Time (TimeS) *</label>
              <input v-model="resendForm.time_s" type="datetime-local" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-xs text-slate-100 font-mono" />
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-300 mb-1">End Time (TimeE) *</label>
              <input v-model="resendForm.time_e" type="datetime-local" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-xs text-slate-100 font-mono" />
            </div>
          </div>

          <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-800">
            <button 
              type="button" 
              @click="triggerManualPushRecords"
              :disabled="resendingLogs"
              class="px-3.5 py-2 bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-medium rounded-lg border border-slate-700 transition-colors disabled:opacity-50"
            >
              📥 Resend Access Records
            </button>
            <button 
              type="button" 
              @click="triggerManualPushSnaps"
              :disabled="resendingLogs"
              class="px-3.5 py-2 bg-amber-600 hover:bg-amber-500 text-white text-xs font-semibold rounded-lg shadow-lg shadow-amber-600/20 disabled:opacity-50"
            >
              🎭 Resend Stranger Snaps
            </button>
          </div>
        </div>

        <!-- TAB 5: Hardware Maintenance & Danger Zone -->
        <div v-else-if="modalTab === 'maintenance'" class="space-y-4">
          <!-- Hardware Info Section -->
          <div class="bg-slate-800/40 rounded-xl p-4 space-y-3">
            <div class="flex items-center justify-between">
              <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-300">Live Hardware Telemetry Info</h4>
              <button 
                @click="queryLiveHardwareInfo" 
                :disabled="queryingHardware"
                class="px-2.5 py-1 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs rounded border border-slate-700"
              >
                {{ queryingHardware ? 'Querying...' : '🔄 Query Camera' }}
              </button>
            </div>

            <div v-if="hardwareInfo" class="grid grid-cols-2 gap-2 text-xs font-mono text-slate-300">
              <div><span class="text-slate-500">Device Name:</span> {{ hardwareInfo.Name || '--' }}</div>
              <div><span class="text-slate-500">Firmware Version:</span> {{ hardwareInfo.Version || hardwareInfo.SoftWareVersion || '--' }}</div>
              <div><span class="text-slate-500">Hardware ID:</span> {{ hardwareInfo.DeviceID || '--' }}</div>
              <div><span class="text-slate-500">Device Type:</span> {{ hardwareInfo.DeviceType ?? '--' }}</div>
            </div>
            <div v-else class="text-xs text-slate-500 italic">Click "Query Camera" to retrieve on-device firmware and hardware specifications.</div>
          </div>

          <!-- Danger Operations -->
          <div class="border border-rose-500/20 bg-rose-950/10 rounded-xl p-4 space-y-3">
            <h4 class="text-xs font-bold uppercase tracking-wider text-rose-400">Device Operations &amp; Danger Zone</h4>

            <div class="space-y-2.5">
              <div class="flex items-center justify-between p-2.5 bg-slate-900/60 rounded-lg border border-slate-800">
                <div>
                  <div class="text-xs font-semibold text-slate-200">Remote Reboot Camera</div>
                  <div class="text-[11px] text-slate-400">Restarts camera hardware operating system via <code class="font-mono text-indigo-400">/action/RebootDevice</code></div>
                </div>
                <button 
                  type="button" 
                  @click="rebootDevice({ id: deviceModal.id, name: deviceForm.name, ip_address: deviceForm.ip_address })"
                  class="px-3 py-1.5 bg-amber-600/20 hover:bg-amber-600/30 text-amber-300 text-xs font-medium rounded-lg border border-amber-500/30"
                >
                  🔄 Reboot
                </button>
              </div>

              <div class="flex items-center justify-between p-2.5 bg-slate-900/60 rounded-lg border border-slate-800">
                <div>
                  <div class="text-xs font-semibold text-rose-300">Wipe On-Device Face Database</div>
                  <div class="text-[11px] text-slate-400">Clears all face whitelist/blacklist libraries from camera storage (<code class="font-mono text-rose-400">/action/DeleteAllPerson</code>)</div>
                </div>
                <button 
                  type="button" 
                  @click="clearCameraFaceDatabase"
                  class="px-3 py-1.5 bg-rose-950/60 hover:bg-rose-900/80 text-rose-300 text-xs font-medium rounded-lg border border-rose-800/50"
                >
                  🗑️ Clear Faces
                </button>
              </div>

              <div class="flex items-center justify-between p-2.5 bg-slate-900/60 rounded-lg border border-slate-800">
                <div>
                  <div class="text-xs font-semibold text-rose-300">Factory Reset Camera</div>
                  <div class="text-[11px] text-slate-400">Restores default camera parameters via <code class="font-mono text-rose-400">/action/SetFactoryDefault</code></div>
                </div>
                <button 
                  type="button" 
                  @click="factoryResetCamera"
                  class="px-3 py-1.5 bg-rose-950/60 hover:bg-rose-900/80 text-rose-300 text-xs font-medium rounded-lg border border-rose-800/50"
                >
                  ⚠️ Factory Reset
                </button>
              </div>

              <div class="flex items-center justify-between p-2.5 bg-slate-900/60 rounded-lg border border-slate-800">
                <div>
                  <div class="text-xs font-semibold text-rose-400">Delete Device From Hub</div>
                  <div class="text-[11px] text-slate-400">Removes camera entry from central PostgreSQL database</div>
                </div>
                <button 
                  type="button" 
                  @click="deleteDevice({ id: deviceModal.id, name: deviceForm.name, ip_address: deviceForm.ip_address })"
                  class="px-3 py-1.5 bg-rose-600 hover:bg-rose-500 text-white text-xs font-semibold rounded-lg shadow"
                >
                  Delete Camera
                </button>
              </div>
            </div>
          </div>

          <div class="flex justify-end pt-3 border-t border-slate-800">
            <button type="button" @click="deviceModal.show = false" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-medium rounded-lg">Close</button>
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
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { useCameraStore } from '../stores/cameraStore';
import { formatTime, formatDateTime } from '../utils/date';
import CameraLivePreviewModal from '../components/CameraLivePreviewModal.vue';
import notify from '../utils/notify';
import axios from 'axios';

const store = useCameraStore();
const testingId = ref(null);
const deletingId = ref(null);
const pushingMqtt = ref(false);
const fetchingMqtt = ref(false);
const syncingTime = ref(false);
const resendingLogs = ref(false);
const queryingHardware = ref(false);

const deviceModal = ref({ show: false, isEdit: false, id: null });
const modalTab = ref('general');
const previewModal = ref({ show: false, device: null });
const hardwareInfo = ref(null);

const currentServerClock = ref('');
let clockTimer = null;

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
  MQEnable: 1,
  MQAddr: '192.168.1.50',
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

const customTimeInput = ref('');

const resendForm = ref({
  time_s: '',
  time_e: '',
});

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

  // Auto-fetch current live MQTT configuration directly from edge camera
  fetchCurrentCameraMqtt();
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

async function fetchCurrentCameraMqtt() {
  if (!deviceModal.value.id) return;
  fetchingMqtt.value = true;
  try {
    const res = await axios.get(`/api/devices/${deviceModal.value.id}/mqtt-param`);
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
    } else {
      notify.error('Query Failed', res.data.error || 'Could not retrieve camera MQTT parameters.');
    }
  } catch (err) {
    notify.error('MQTT Query Error', err.response?.data?.message || err.message);
  } finally {
    fetchingMqtt.value = false;
  }
}

async function pushMqttParamsToCamera() {
  if (!deviceModal.value.id) return;
  pushingMqtt.value = true;
  try {
    const res = await axios.post(`/api/devices/${deviceModal.value.id}/sync-mqtt`, mqttForm.value);
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
    const res = await axios.post(`/api/devices/${deviceModal.value.id}/sync-time`, payload);
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
  if (!resendForm.value.time_s || !resendForm.value.time_e) {
    notify.warning('Time Range Required', 'Please select both start and end times.');
    return;
  }
  resendingLogs.value = true;
  try {
    const formatStr = (str) => str.replace('T', ' ') + ':00';
    const res = await axios.post(`/api/devices/${deviceModal.value.id}/manual-push-records`, {
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
  if (!resendForm.value.time_s || !resendForm.value.time_e) {
    notify.warning('Time Range Required', 'Please select both start and end times.');
    return;
  }
  resendingLogs.value = true;
  try {
    const formatStr = (str) => str.replace('T', ' ') + ':00';
    const res = await axios.post(`/api/devices/${deviceModal.value.id}/manual-push-snaps`, {
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

async function queryLiveHardwareInfo() {
  if (!deviceModal.value.id) return;
  queryingHardware.value = true;
  try {
    const res = await axios.get(`/api/devices/${deviceModal.value.id}/sys-param`);
    if (res.data.success && res.data.data?.info) {
      hardwareInfo.value = res.data.data.info;
      notify.toast('Hardware info retrieved from camera', 'success');
    } else {
      notify.error('Query Failed', res.data.error || 'Could not query camera system parameters.');
    }
  } catch (err) {
    notify.error('Query Error', err.response?.data?.message || err.message);
  } finally {
    queryingHardware.value = false;
  }
}

async function clearCameraFaceDatabase() {
  const confirmed = await notify.confirm(
    'Wipe All Face Data from Camera?',
    `This will remove ALL registered personnel and face templates from ${deviceForm.value.name} (${deviceForm.value.ip_address}). The camera will automatically reboot.`,
    'Yes, Wipe Face Library',
    'Cancel',
    true
  );

  if (!confirmed) return;

  try {
    const res = await axios.post(`/api/devices/${deviceModal.value.id}/clear-face-database`);
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
  const confirmed = await notify.confirm(
    'Restore Camera to Factory Defaults?',
    `This will reset all hardware and algorithmic settings on ${deviceForm.value.name} (${deviceForm.value.ip_address}).`,
    'Yes, Factory Reset',
    'Cancel',
    true
  );

  if (!confirmed) return;

  try {
    const res = await axios.post(`/api/devices/${deviceModal.value.id}/factory-reset`, {
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

onMounted(() => {
  store.fetchDevices();
  updateClock();
  clockTimer = setInterval(updateClock, 1000);
});

onUnmounted(() => {
  if (clockTimer) clearInterval(clockTimer);
});
</script>
