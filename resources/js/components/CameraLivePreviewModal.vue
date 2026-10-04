<template>
  <div
    v-if="isOpen"
    role="dialog"
    aria-modal="true"
    aria-labelledby="camera-preview-title"
    @keydown.escape="close"
    class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-md animate-fadeIn"
    @click.self="close"
  >
    <div
      class="relative w-full max-w-5xl bg-white border border-slate-200 rounded-2xl shadow-2xl overflow-hidden flex flex-col max-h-[92vh]"
      :class="{ '!max-w-none !h-full !max-h-none !rounded-none': isFullscreen }"
    >
      <!-- Responsive Header <640px -->
      <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 px-4 sm:px-6 py-3 sm:py-4 bg-white border-b border-slate-200">
        <div class="flex items-center space-x-3 min-w-0">
          <div class="p-2 rounded-xl bg-indigo-50 border border-indigo-100 text-indigo-600 shrink-0">
            <svg class="w-6 h-6 animate-pulse" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
            </svg>
          </div>
          <div class="min-w-0">
            <div class="flex items-center space-x-2">
              <h3 id="camera-preview-title" class="text-base sm:text-lg font-bold text-slate-900 tracking-wide truncate">
                {{ device?.name || 'Camera Preview' }}
              </h3>
              <span
                class="px-2.5 py-0.5 text-xs font-semibold rounded-full flex items-center space-x-1.5 shrink-0"
                :class="statusBadgeClass"
              >
                <span class="w-1.5 h-1.5 rounded-full animate-ping" :class="statusDotClass"></span>
                <span>{{ playerStatus.state || 'CONNECTING' }}</span>
              </span>
            </div>
            <p class="text-[11px] sm:text-xs text-slate-500 font-mono mt-0.5 truncate">
              ID: <span class="text-slate-700">{{ device?.device_id }}</span> |
              Endpoint: <span class="text-slate-700 font-semibold">{{ previewHost }}</span> |
              <span class="hidden sm:inline">Stream: </span><span class="text-indigo-600 font-bold truncate">{{ wsUrlDisplay }}</span>
            </p>
          </div>
        </div>

        <!-- Header Actions with Quality Switcher ARIA attributes -->
        <div class="flex items-center justify-between sm:justify-end w-full sm:w-auto gap-2 flex-wrap">
          <!-- Quality Switcher with role="group" and aria-pressed -->
          <div role="group" aria-label="Stream video resolution" class="bg-slate-100 p-1 rounded-lg border border-slate-200 flex items-center text-xs font-medium">
            <button
              type="button"
              @click="switchQuality(0)"
              :aria-pressed="streamType === 0"
              class="px-2.5 py-1 rounded transition-all cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
              :class="streamType === 0 ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-600 hover:text-slate-900'"
            >
              1080P Main
            </button>
            <button
              type="button"
              @click="switchQuality(1)"
              :aria-pressed="streamType === 1"
              class="px-2.5 py-1 rounded transition-all cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
              :class="streamType === 1 ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-600 hover:text-slate-900'"
            >
              720P Sub
            </button>
          </div>

          <!-- Open Web UI -->
          <a
            :href="`${previewEndpointUrl}/#/preview`"
            target="_blank"
            rel="noopener noreferrer"
            aria-label="Open Camera Native Web UI in new tab"
            class="p-2 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-600 hover:text-slate-900 border border-slate-200 transition cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
            title="Open Camera Native Web UI"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
            </svg>
          </a>

          <!-- Fullscreen Toggle with accessible name -->
          <button
            type="button"
            @click="toggleFullscreen"
            :aria-label="isFullscreen ? 'Exit full screen video mode' : 'Toggle full screen video mode'"
            class="p-2 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-600 hover:text-slate-900 border border-slate-200 transition cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
            :title="isFullscreen ? 'Exit Fullscreen' : 'Enter Fullscreen'"
          >
            <svg v-if="!isFullscreen" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4" />
            </svg>
            <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>

          <!-- Close Modal with accessible name -->
          <button
            type="button"
            @click="close"
            aria-label="Close camera preview modal"
            class="p-2 rounded-lg bg-slate-100 hover:bg-rose-50 text-slate-500 hover:text-rose-600 border border-slate-200 hover:border-rose-200 transition cursor-pointer focus:outline-none focus:ring-2 focus:ring-rose-500"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
      </div>

      <!-- Main Video Feed Viewport -->
      <div class="relative flex-1 bg-slate-950 flex items-center justify-center overflow-hidden min-h-[380px]">
        <!-- WebGL Video Canvas -->
        <canvas
          ref="videoCanvas"
          class="w-full h-full object-contain max-h-[70vh]"
          :class="{ '!max-h-screen': isFullscreen }"
        ></canvas>

        <!-- Loading / Connecting Overlay -->
        <div
          v-if="playerStatus.state !== 'STREAMING' && !errorMessage"
          class="absolute inset-0 flex flex-col items-center justify-center bg-slate-950/80 backdrop-blur-sm z-10"
        >
          <div class="relative w-16 h-16 mb-4">
            <div class="absolute inset-0 rounded-full border-4 border-indigo-500/20 animate-ping"></div>
            <div class="absolute inset-0 rounded-full border-4 border-t-indigo-500 border-r-transparent border-b-transparent border-l-transparent animate-spin"></div>
          </div>
          <p class="text-sm font-semibold text-white tracking-wide uppercase">{{ playerStatus.message || 'Connecting to WebSocket Feed...' }}</p>
          <p class="text-xs text-slate-400 mt-1 font-mono">Negotiating auth key with {{ previewHost }}</p>
        </div>

        <!-- Error Overlay -->
        <div
          v-if="errorMessage"
          class="absolute inset-0 flex flex-col items-center justify-center bg-slate-950/90 backdrop-blur-sm z-10 px-6 text-center"
        >
          <div class="p-3 rounded-full bg-rose-500/10 border border-rose-500/20 text-rose-400 mb-3">
            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
          </div>
          <h4 class="text-base font-bold text-white">Stream Error</h4>
          <p class="text-xs text-rose-300 mt-1 max-w-md">{{ errorMessage }}</p>
          <div class="mt-4 flex items-center space-x-3">
            <button
              @click="reconnect"
              class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-xs font-semibold shadow-lg shadow-indigo-600/30 transition"
            >
              🔄 Retry Connection
            </button>
            <a
              :href="`${previewEndpointUrl}/#/preview`"
              target="_blank"
              class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-lg text-xs font-medium border border-slate-700 transition"
            >
              Open Camera Web Page
            </a>
          </div>
        </div>

        <!-- Cyberpunk OSD Telemetry HUD (Over Video) -->
        <div class="absolute top-4 left-4 pointer-events-none flex flex-col space-y-1 z-20">
          <div class="flex items-center space-x-2 bg-slate-950/70 backdrop-blur-md px-3 py-1.5 rounded-lg border border-slate-800 text-xs font-mono">
            <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
            <span class="text-emerald-400 font-bold">LIVE FEED</span>
            <span class="text-slate-500">|</span>
            <span class="text-slate-300">{{ decodeInfo.width ? `${decodeInfo.width}x${decodeInfo.height}` : (streamType === 0 ? '1920x1080' : '1280x720') }}</span>
            <span class="text-slate-500">|</span>
            <span class="text-indigo-300 font-bold">{{ decodeInfo.fps || 0 }} FPS</span>
          </div>

          <div v-if="decodeInfo.bitrateKbps" class="bg-slate-950/60 backdrop-blur-md px-2.5 py-1 rounded text-[10px] font-mono text-slate-400 border border-slate-800/80 w-max">
            Bitrate: <span class="text-slate-200">{{ decodeInfo.bitrateKbps }} kbps</span>
          </div>
        </div>

        <!-- Snapshot Flash Notification -->
        <div
          v-if="snapshotNotice"
          class="absolute bottom-4 right-4 z-30 bg-emerald-500/90 text-white text-xs px-3 py-2 rounded-xl shadow-lg flex items-center space-x-2 animate-bounce"
        >
          <span>📸 Snapshot Captured</span>
        </div>
      </div>

      <!-- Bottom Control Bar -->
      <div class="px-6 py-3.5 bg-white border-t border-slate-200 flex items-center justify-between">
        <div class="flex items-center space-x-4 text-xs text-slate-600 font-mono">
          <span class="flex items-center space-x-1.5">
            <span class="text-slate-500">Host:</span>
            <span class="text-slate-800 font-medium">{{ previewHost }}</span>
          </span>
          <span class="flex items-center space-x-1.5">
            <span class="text-slate-500">User:</span>
            <span class="text-slate-800 font-medium">{{ device?.username || 'admin' }}</span>
          </span>
          <span class="flex items-center space-x-1.5">
            <span class="text-slate-500">Codec:</span>
            <span class="text-emerald-600 font-semibold">H.264/WASM WebGL</span>
          </span>
        </div>

        <div class="flex items-center space-x-3">
          <!-- Capture Snapshot Button -->
          <button
            @click="takeSnapshot"
            class="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 hover:text-slate-900 rounded-lg text-xs font-semibold border border-slate-200 flex items-center space-x-1.5 transition"
          >
            <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
            <span>Capture Frame</span>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, watch, nextTick, onMounted, onBeforeUnmount, computed } from 'vue';
import { CameraHqPlayer } from '../utils/cameraHqPlayer';

const props = defineProps({
  isOpen: {
    type: Boolean,
    default: false,
  },
  device: {
    type: Object,
    default: null,
  },
});

const emit = defineEmits(['close']);

const videoCanvas = ref(null);
const playerInstance = ref(null);
const streamType = ref(0); // 0: 1080P, 1: 720P
const isFullscreen = ref(false);
const snapshotNotice = ref(false);
const errorMessage = ref('');
const playerStatus = ref({ state: 'CONNECTING', message: 'Connecting to WebSocket feed...' });
const decodeInfo = ref({ width: 0, height: 0, fps: 0, bitrateKbps: 0 });

const previewHost = computed(() => {
  if (props.device?.endpoint_url) {
    const clean = props.device.endpoint_url
      .replace(/^https?:\/\//i, '')
      .replace(/^wss?:\/\//i, '')
      .replace(/\/.*$/, '');
    return clean.replace(/ai-camera-api\./i, 'ai-camera.');
  }
  if (!props.device?.ip_address) return 'ai-camera.philyra.cloud';
  return props.device.ip_address
    .replace(/^https?:\/\//i, '')
    .replace(/^wss?:\/\//i, '')
    .replace(/\/.*$/, '')
    .replace(/ai-camera-api\./i, 'ai-camera.');
});

const isSecure = computed(() => {
  if (typeof window !== 'undefined' && window.location?.protocol === 'https:') return true;
  if (props.device?.scheme === 'https') return true;
  if (previewHost.value.includes('.cloud')) return true;
  return false;
});

const wsUrlDisplay = computed(() => {
  const protocol = isSecure.value ? 'wss:' : 'ws:';
  return `${protocol}//${previewHost.value}/`;
});

const previewEndpointUrl = computed(() => {
  if (!props.device) return '';
  const scheme = isSecure.value ? 'https' : (props.device.scheme || 'http');
  return `${scheme}://${previewHost.value}`;
});

const statusBadgeClass = computed(() => {
  switch (playerStatus.value.state) {
    case 'STREAMING':
      return 'bg-emerald-50 border border-emerald-200 text-emerald-700';
    case 'AUTHENTICATING':
    case 'HANDSHAKE':
      return 'bg-indigo-50 border border-indigo-200 text-indigo-700';
    case 'DISCONNECTED':
      return 'bg-amber-50 border border-amber-200 text-amber-700';
    default:
      return 'bg-slate-100 border border-slate-200 text-slate-600';
  }
});

const statusDotClass = computed(() => {
  switch (playerStatus.value.state) {
    case 'STREAMING':
      return 'bg-emerald-400';
    case 'AUTHENTICATING':
    case 'HANDSHAKE':
      return 'bg-indigo-400';
    case 'DISCONNECTED':
      return 'bg-amber-400';
    default:
      return 'bg-slate-400';
  }
});

function initPlayer() {
  destroyPlayer();
  errorMessage.value = '';
  playerStatus.value = { state: 'CONNECTING', message: 'Connecting to camera WebSocket...' };

  if (!props.device || !videoCanvas.value) return;

  try {
    playerInstance.value = new CameraHqPlayer(videoCanvas.value, {
      scheme: isSecure.value ? 'https' : (props.device.scheme || 'http'),
      host: previewHost.value,
      port: (isSecure.value || previewHost.value.includes('.cloud')) ? 443 : (props.device.port || 80),
      username: props.device.username || 'admin',
      password: props.device.password || 'admin',
      streamType: streamType.value,
      channel: 0,
      onStatus: (status) => {
        playerStatus.value = status;
        if (status.state === 'STREAMING') {
          errorMessage.value = '';
        }
      },
      onError: (err) => {
        errorMessage.value = err;
      },
      onDecodeInfo: (info) => {
        decodeInfo.value = { ...decodeInfo.value, ...info };
      },
    });
  } catch (e) {
    errorMessage.value = 'Player init error: ' + e.message;
  }
}

function destroyPlayer() {
  if (playerInstance.value) {
    playerInstance.value.destroy();
    playerInstance.value = null;
  }
  decodeInfo.value = { width: 0, height: 0, fps: 0, bitrateKbps: 0 };
}

function switchQuality(type) {
  streamType.value = type;
  if (playerInstance.value) {
    playerInstance.value.switchStream(type);
  }
}

function takeSnapshot() {
  if (!playerInstance.value) return;
  const dataUrl = playerInstance.value.takeSnapshot();
  if (!dataUrl) return;

  const a = document.createElement('a');
  a.href = dataUrl;
  a.download = `${props.device?.name || 'camera'}_snapshot_${Date.now()}.jpg`;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);

  snapshotNotice.value = true;
  setTimeout(() => {
    snapshotNotice.value = false;
  }, 2500);
}

function toggleFullscreen() {
  isFullscreen.value = !isFullscreen.value;
}

function reconnect() {
  initPlayer();
}

function close() {
  destroyPlayer();
  isFullscreen.value = false;
  emit('close');
}

watch(
  () => props.isOpen,
  (val) => {
    if (val) {
      nextTick(() => {
        initPlayer();
      });
    } else {
      destroyPlayer();
    }
  }
);

function handleGlobalKeydown(e) {
  if (e.key === 'Escape' && props.isOpen) {
    close();
  }
}

onMounted(() => {
  window.addEventListener('keydown', handleGlobalKeydown);
});

onBeforeUnmount(() => {
  destroyPlayer();
  window.removeEventListener('keydown', handleGlobalKeydown);
});
</script>

<style scoped>
@keyframes fadeIn {
  from {
    opacity: 0;
    transform: scale(0.98);
  }
  to {
    opacity: 1;
    transform: scale(1);
  }
}

.animate-fadeIn {
  animation: fadeIn 0.2s ease-out forwards;
}
</style>
