<template>
  <div class="min-h-screen bg-slate-950 text-slate-100 flex flex-col font-sans antialiased selection:bg-indigo-500 selection:text-white">
    <!-- Top Navigation Header -->
    <header class="bg-slate-900/90 border-b border-slate-800 sticky top-0 z-40 backdrop-blur-md">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
        <div class="flex items-center gap-3">
          <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-500 flex items-center justify-center shadow-lg shadow-indigo-600/30 text-white font-bold text-lg">
            👁️
          </div>
          <div>
            <h1 class="text-sm font-bold tracking-tight text-white flex items-center gap-2">
              Vision Edge & Telemetry Hub
              <span class="text-[10px] uppercase font-mono px-2 py-0.5 rounded bg-indigo-500/10 text-indigo-400 border border-indigo-500/20">X40Y AI Platform</span>
            </h1>
            <p class="text-[11px] text-slate-400">Decoupled Biometric Access Control & Edge Stream Processor</p>
          </div>
        </div>

        <!-- WebSocket & Service Status Indicator -->
        <div class="flex items-center gap-3">
          <div class="hidden sm:flex items-center gap-2 px-3 py-1.5 rounded-full bg-slate-800/80 border border-slate-700 text-xs">
            <span class="inline-block w-2 h-2 rounded-full" :class="store.wsConnected ? 'bg-emerald-400 animate-pulse' : 'bg-rose-500'"></span>
            <span class="text-slate-300 font-mono text-[11px]">
              {{ store.wsConnected ? 'WebSockets Active' : 'Connecting to Reverb...' }}
            </span>
          </div>

          <button 
            @click="store.fetchStats()" 
            class="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 rounded-lg text-xs font-medium transition-colors"
          >
            🔄 Sync Stats
          </button>
        </div>
      </div>
    </header>

    <!-- KPI Summary Metric Cards -->
    <section class="max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 pt-6">
      <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-5 gap-4">
        <!-- Metric 1: Total Verifications Today -->
        <div class="bg-slate-900/80 border border-slate-800/80 rounded-2xl p-4 shadow-lg backdrop-blur-sm">
          <div class="text-[11px] font-medium text-slate-400 uppercase tracking-wider">Scans Today</div>
          <div class="text-2xl font-bold text-slate-100 mt-1 font-mono">
            {{ store.stats.telemetry?.total_scans_today || 0 }}
          </div>
          <div class="text-[10px] text-emerald-400 mt-0.5">
            {{ store.stats.telemetry?.allowed_today || 0 }} allowed passes
          </div>
        </div>

        <!-- Metric 2: Denied / Strangers -->
        <div class="bg-slate-900/80 border border-slate-800/80 rounded-2xl p-4 shadow-lg backdrop-blur-sm">
          <div class="text-[11px] font-medium text-slate-400 uppercase tracking-wider">Alerts & Strangers</div>
          <div class="text-2xl font-bold text-amber-400 mt-1 font-mono">
            {{ (store.stats.telemetry?.rejected_today || 0) + (store.stats.telemetry?.strangers_today || 0) }}
          </div>
          <div class="text-[10px] text-rose-400 mt-0.5">
            {{ store.stats.telemetry?.rejected_today || 0 }} rejected / blacklist
          </div>
        </div>

        <!-- Metric 3: Online Camera Fleet -->
        <div class="bg-slate-900/80 border border-slate-800/80 rounded-2xl p-4 shadow-lg backdrop-blur-sm">
          <div class="text-[11px] font-medium text-slate-400 uppercase tracking-wider">Active Cameras</div>
          <div class="text-2xl font-bold text-emerald-400 mt-1 font-mono">
            {{ store.stats.devices?.online || 0 }} / {{ store.stats.devices?.total || 0 }}
          </div>
          <div class="text-[10px] text-slate-400 mt-0.5">
            {{ store.stats.devices?.offline || 0 }} offline
          </div>
        </div>

        <!-- Metric 4: Personnel Face Library -->
        <div class="bg-slate-900/80 border border-slate-800/80 rounded-2xl p-4 shadow-lg backdrop-blur-sm">
          <div class="text-[11px] font-medium text-slate-400 uppercase tracking-wider">Enrolled Face Lib</div>
          <div class="text-2xl font-bold text-indigo-400 mt-1 font-mono">
            {{ store.stats.personnel?.total || 0 }}
          </div>
          <div class="text-[10px] text-slate-400 mt-0.5">
            {{ store.stats.personnel?.whitelisted || 0 }} whitelist / {{ store.stats.personnel?.blacklisted || 0 }} block
          </div>
        </div>

        <!-- Metric 5: Redis Outbox Queue -->
        <div class="col-span-2 sm:col-span-4 lg:col-span-1 bg-slate-900/80 border border-slate-800/80 rounded-2xl p-4 shadow-lg backdrop-blur-sm">
          <div class="text-[11px] font-medium text-slate-400 uppercase tracking-wider">Sync Outbox</div>
          <div class="text-2xl font-bold mt-1 font-mono" :class="store.stats.sync?.failed > 0 ? 'text-rose-400' : 'text-slate-100'">
            {{ store.stats.sync?.pending || 0 }}
          </div>
          <div class="text-[10px]" :class="store.stats.sync?.failed > 0 ? 'text-rose-400 font-bold' : 'text-slate-400'">
            {{ store.stats.sync?.failed || 0 }} failed tasks
          </div>
        </div>
      </div>
    </section>

    <!-- Main Content Area with Tab Navigation -->
    <main class="max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6 flex-1 flex flex-col">
      <!-- Navigation Tabs -->
      <nav class="flex items-center gap-2 border-b border-slate-800 mb-6 overflow-x-auto pb-1">
        <button 
          v-for="tab in tabs" 
          :key="tab.id"
          @click="currentTab = tab.id"
          class="px-4 py-2.5 text-xs font-semibold rounded-t-xl transition-all whitespace-nowrap flex items-center gap-2 border-b-2"
          :class="currentTab === tab.id ? 'text-indigo-400 border-indigo-500 bg-slate-900/90' : 'text-slate-400 border-transparent hover:text-slate-200 hover:border-slate-700'"
        >
          <span>{{ tab.icon }}</span>
          <span>{{ tab.label }}</span>
        </button>
      </nav>

      <!-- Active Tab Component View -->
      <div class="flex-1">
        <LiveTelemetry v-if="currentTab === 'live'" />
        <StrangerSnapsMonitor v-else-if="currentTab === 'strangers'" />
        <PersonnelManager v-else-if="currentTab === 'personnel'" />
        <DeviceManager v-else-if="currentTab === 'devices'" />
        <AccessLogsHistory v-else-if="currentTab === 'logs'" />
        <SyncTasksMonitor v-else-if="currentTab === 'sync'" />
      </div>
    </main>

    <!-- Footer -->
    <footer class="bg-slate-950 border-t border-slate-900 py-4 text-center text-xs text-slate-500">
      <div class="max-w-7xl mx-auto px-4 flex flex-col sm:flex-row items-center justify-between gap-2">
        <span>Intelligent Vision Edge & Telemetry Hub &mdash; Laravel 11 &amp; Vue 3</span>
        <span>MQTT Broker: <code class="text-indigo-400 font-mono">:1883</code> &bull; Reverb WebSockets: <code class="text-indigo-400 font-mono">:8080</code></span>
      </div>
    </footer>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { useCameraStore } from './stores/cameraStore';
import echo from './echo';
import LiveTelemetry from './views/LiveTelemetry.vue';
import StrangerSnapsMonitor from './views/StrangerSnapsMonitor.vue';
import PersonnelManager from './views/PersonnelManager.vue';
import DeviceManager from './views/DeviceManager.vue';
import AccessLogsHistory from './views/AccessLogsHistory.vue';
import SyncTasksMonitor from './views/SyncTasksMonitor.vue';

const store = useCameraStore();
const currentTab = ref('live');

const tabs = [
  { id: 'live', label: 'Live Telemetry', icon: '📹' },
  { id: 'strangers', label: 'Stranger Snaps', icon: '🎭' },
  { id: 'personnel', label: 'Personnel & Face Library', icon: '👥' },
  { id: 'devices', label: 'Camera Devices', icon: '📡' },
  { id: 'logs', label: 'Access Audit Logs', icon: '📋' },
  { id: 'sync', label: 'Sync Outbox Queue', icon: '⚡' },
];

onMounted(() => {
  store.fetchStats();
  store.fetchDevices();
  store.fetchRecentLogs();

  // Subscribe to Laravel Reverb WebSocket channels
  echo.channel('access-logs')
    .listen('.AccessLogReceived', (e) => store.addLiveLog(e))
    .listen('AccessLogReceived', (e) => store.addLiveLog(e));

  echo.channel('stranger-snaps')
    .listen('.StrangerSnapReceived', (e) => store.addStrangerSnap(e))
    .listen('StrangerSnapReceived', (e) => store.addStrangerSnap(e));

  echo.channel('device-status')
    .listen('.DeviceStatusUpdated', (e) => store.updateDeviceStatus(e))
    .listen('DeviceStatusUpdated', (e) => store.updateDeviceStatus(e));

  // Track Echo connection state
  if (echo.connector?.pusher?.connection) {
    echo.connector.pusher.connection.bind('connected', () => {
      store.wsConnected = true;
    });
    echo.connector.pusher.connection.bind('disconnected', () => {
      store.wsConnected = false;
    });
    echo.connector.pusher.connection.bind('connecting', () => {
      store.wsConnected = false;
    });
    if (echo.connector.pusher.connection.state === 'connected') {
      store.wsConnected = true;
    }
  }

  // Periodic poll for metrics
  const interval = setInterval(() => {
    store.fetchStats();
  }, 10000);

  onUnmounted(() => {
    clearInterval(interval);
    echo.leaveChannel('access-logs');
    echo.leaveChannel('stranger-snaps');
    echo.leaveChannel('device-status');
  });
});
</script>
