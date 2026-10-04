<template>
  <div class="space-y-6">
    <!-- Top Control Bar -->
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 bg-white border border-slate-200/80 p-4 rounded-xl shadow-xs">
      <div class="flex items-center gap-3">
        <div class="relative flex h-3.5 w-3.5">
          <span v-if="store.wsConnected" class="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span>
          <span :class="store.wsConnected ? 'bg-amber-500' : 'bg-rose-500'" class="relative inline-flex rounded-full h-3.5 w-3.5"></span>
        </div>
        <div>
          <h2 class="text-lg font-bold text-slate-900 flex items-center gap-2">
            Unregistered Face Captures (Strangers)
            <span class="text-xs px-2.5 py-0.5 rounded-full font-mono font-medium" :class="store.wsConnected ? 'bg-amber-50 text-amber-700 border border-amber-200' : 'bg-rose-50 text-rose-700 border border-rose-200'">
              {{ store.wsConnected ? 'Real-Time Monitoring' : 'Reconnecting...' }}
            </span>
          </h2>
          <p class="text-xs text-slate-500">Live edge stranger snapshot captures, unidentified face detections, and 1-click biometric personnel enrollment</p>
        </div>
      </div>

      <div class="flex items-center gap-2 flex-wrap w-full sm:w-auto justify-end">
        <!-- View Mode Switcher -->
        <div class="bg-slate-100 p-0.5 rounded-lg border border-slate-200 flex items-center">
          <button 
            @click="viewMode = 'grid'" 
            class="px-2.5 py-1 text-xs font-medium rounded-md transition-colors cursor-pointer"
            :class="viewMode === 'grid' ? 'bg-indigo-600 text-white shadow-xs' : 'text-slate-600 hover:text-slate-900'"
            title="Grid Gallery View"
          >
            🖼️ Cards
          </button>
          <button 
            @click="viewMode = 'table'" 
            class="px-2.5 py-1 text-xs font-medium rounded-md transition-colors cursor-pointer"
            :class="viewMode === 'table' ? 'bg-indigo-600 text-white shadow-xs' : 'text-slate-600 hover:text-slate-900'"
            title="Table List View"
          >
            📋 Table
          </button>
        </div>

        <button 
          @click="showBackfillModal = true" 
          class="px-3 py-1.5 bg-amber-600 hover:bg-amber-700 text-white border border-amber-600 rounded-lg text-xs font-semibold flex items-center gap-1.5 transition-colors shadow-xs cursor-pointer"
        >
          <span>📥</span> Backfill Logs
        </button>

        <button 
          @click="fetchSnaps" 
          class="px-3 py-1.5 bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 rounded-lg text-xs font-medium flex items-center gap-1.5 transition-colors shadow-xs cursor-pointer"
        >
          <span>🔄</span> Refresh
        </button>
      </div>
    </div>

    <!-- Filters & Stats Banner -->
    <div class="bg-white border border-slate-200 p-4 rounded-xl space-y-3 shadow-xs">
      <div class="grid grid-cols-1 sm:grid-cols-3 lg:grid-cols-4 gap-3">
        <!-- Camera Filter -->
        <div>
          <label for="stranger-filter-device" class="block text-[11px] font-medium text-slate-500 mb-1">Camera Device</label>
          <select 
            id="stranger-filter-device"
            v-model="filters.deviceId" 
            @change="applyFilters" 
            class="w-full bg-white border border-slate-200 text-xs rounded-lg px-3 py-2 text-slate-700 focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 cursor-pointer shadow-xs"
          >
            <option value="">All Cameras</option>
            <option v-for="d in store.devices" :key="d.device_id" :value="d.device_id">
              {{ d.name }} ({{ d.device_id }})
            </option>
          </select>
        </div>

        <!-- Date From -->
        <div>
          <label for="stranger-filter-from" class="block text-[11px] font-medium text-slate-500 mb-1">From Date</label>
          <input 
            id="stranger-filter-from"
            type="date" 
            v-model="filters.from" 
            @change="applyFilters"
            class="w-full bg-white border border-slate-200 text-xs rounded-lg px-3 py-2 text-slate-700 focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 shadow-xs"
          />
        </div>

        <!-- Date To -->
        <div>
          <label for="stranger-filter-to" class="block text-[11px] font-medium text-slate-500 mb-1">To Date</label>
          <input 
            id="stranger-filter-to"
            type="date" 
            v-model="filters.to" 
            @change="applyFilters"
            class="w-full bg-white border border-slate-200 text-xs rounded-lg px-3 py-2 text-slate-700 focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 shadow-xs"
          />
        </div>

        <!-- Reset Button -->
        <div class="flex items-end">
          <button 
            @click="resetFilters" 
            class="w-full py-2 px-3 bg-slate-50 hover:bg-slate-100 text-slate-700 text-xs rounded-lg border border-slate-200 transition-colors shadow-xs cursor-pointer font-medium"
          >
            Clear Filters
          </button>
        </div>
      </div>
    </div>

    <!-- Live Snaps Stream Banner if active -->
    <div v-if="store.strangerSnaps.length > 0 && !isFiltered" class="bg-amber-50 border border-amber-200 rounded-xl p-4 shadow-xs">
      <div class="flex items-center justify-between mb-3">
        <div class="flex items-center gap-2">
          <span class="inline-block w-2 h-2 rounded-full bg-amber-500 animate-ping"></span>
          <h3 class="text-xs font-bold uppercase tracking-wider text-amber-800">
            Live Incoming Stream ({{ store.strangerSnaps.length }} captures this session)
          </h3>
        </div>
        <span class="text-[11px] text-amber-700">Real-time MQTT <code class="font-mono font-semibold">mqtt/face/+/Snap</code></span>
      </div>

      <div class="flex items-center gap-3 overflow-x-auto pb-2 scrollbar-thin">
        <div 
          v-for="snap in store.strangerSnaps.slice(0, 10)" 
          :key="'live-' + (snap.id || snap.captured_at)"
          role="button"
          tabindex="0"
          :aria-label="'Inspect stranger face snapshot captured by camera ' + snap.device_id"
          class="shrink-0 w-36 bg-white border border-amber-300 rounded-xl p-2 cursor-pointer hover:border-amber-500 transition-all group shadow-xs hover:shadow-md"
          @click="openImageModal(snap.snap_pic_url, snap.scene_pic_url, snap.device_id, snap.captured_at, snap.alarm_action)"
          @keydown.enter="openImageModal(snap.snap_pic_url, snap.scene_pic_url, snap.device_id, snap.captured_at, snap.alarm_action)"
        >
          <div class="w-full h-24 rounded-lg bg-slate-100 overflow-hidden relative border border-slate-200">
            <img v-if="snap.snap_pic_url" :src="formatMediaUrl(snap.snap_pic_url)" alt="Stranger Crop" class="w-full h-full object-cover group-hover:scale-105 transition-transform" />
            <div v-else class="w-full h-full flex items-center justify-center text-[10px] text-slate-400 font-mono">NO PIC</div>
            <div v-if="snap.is_no_mask === 1" class="absolute bottom-0 right-0 bg-amber-500 text-black text-[8px] px-1 font-bold rounded-tl">NO MASK</div>
          </div>
          <div class="mt-1.5">
            <div class="text-[11px] font-bold text-amber-900 truncate">Cam: {{ snap.device_id }}</div>
            <div class="text-[10px] text-slate-500 font-mono">{{ formatTime(snap.captured_at) }}</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Main Content: Grid Mode -->
    <div v-if="viewMode === 'grid'">
      <div class="flex items-center justify-between mb-4">
        <h3 class="text-sm font-semibold uppercase tracking-wider text-slate-500 flex items-center gap-2">
          Stranger Snapshot Archive ({{ pagination.total }})
        </h3>
        <span class="text-xs text-slate-500">Page {{ pagination.current_page }} of {{ pagination.last_page || 1 }}</span>
      </div>

      <div v-if="loading" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
        <div v-for="i in 8" :key="'skel-card-' + i" class="bg-white border border-slate-200 rounded-xl h-64 animate-pulse shadow-xs"></div>
      </div>

      <div v-else-if="snaps.length === 0" class="bg-white border border-dashed border-slate-300 rounded-xl p-12 text-center text-slate-500 shadow-xs">
        <div class="text-4xl mb-2">🎭</div>
        <div class="font-medium text-slate-800">No Stranger Captures Found</div>
        <div class="text-xs mt-1 text-slate-500">Stranger face events captured by cameras will be listed here.</div>
      </div>

      <div v-else class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
        <div 
          v-for="snap in snaps" 
          :key="snap.id"
          role="button"
          tabindex="0"
          :aria-label="'Inspect stranger face snapshot captured by ' + (snap.device?.name || 'Camera ' + snap.device_id)"
          @keydown.enter="openImageModal(snap.snap_pic_url, snap.scene_pic_url, snap.device?.name || snap.device_id, snap.captured_at, snap.alarm_action)"
          class="bg-white border border-slate-200/80 rounded-xl overflow-hidden hover:border-amber-400 transition-all flex flex-col justify-between shadow-xs hover:shadow-md"
        >
          <!-- Image Section -->
          <div class="relative bg-slate-950 h-48 overflow-hidden cursor-pointer group" @click="openImageModal(snap.snap_pic_url, snap.scene_pic_url, snap.device?.name || snap.device_id, snap.captured_at, snap.alarm_action)">
            <img 
              v-if="snap.snap_pic_url" 
              :src="formatMediaUrl(snap.snap_pic_url)" 
              alt="Stranger Capture" 
              class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300" 
            />
            <div v-else class="w-full h-full flex items-center justify-center text-xs text-slate-400 font-mono">
              No Snapshot Image
            </div>

            <!-- Overlay Badges -->
            <div class="absolute top-2 left-2 flex items-center gap-1.5">
              <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-amber-500 text-slate-950 shadow-xs">
                Stranger
              </span>
              <span v-if="snap.is_no_mask === 1" class="px-1.5 py-0.5 rounded text-[9px] font-bold bg-rose-600 text-white shadow-xs">
                NO MASK
              </span>
            </div>

            <div class="absolute bottom-2 right-2">
              <span v-if="snap.scene_pic_url" class="px-2 py-0.5 rounded-md text-[10px] font-medium bg-white/90 text-slate-800 border border-slate-200 backdrop-blur-sm shadow-xs">
                🔍 Full Scene
              </span>
            </div>
          </div>

          <!-- Info Details -->
          <div class="p-3.5 space-y-2">
            <div class="flex items-center justify-between">
              <div class="text-xs font-bold text-slate-900 truncate">
                {{ snap.device?.name || ('Camera ' + snap.device_id) }}
              </div>
              <span class="text-[10px] text-slate-500 font-mono">
                ID: {{ snap.device_id }}
              </span>
            </div>

            <div class="text-[11px] text-slate-500 flex items-center justify-between font-mono">
              <span>Time:</span>
              <span class="text-slate-700 font-medium">{{ formatDateTime(snap.captured_at) }}</span>
            </div>

            <div v-if="snap.alarm_action" class="text-[10px] text-rose-700 bg-rose-50 border border-rose-200 px-2 py-0.5 rounded truncate font-medium">
              ⚠️ {{ snap.alarm_action }}
            </div>

            <div class="pt-2 border-t border-slate-100 flex items-center justify-between gap-2">
              <button 
                @click="openImageModal(snap.snap_pic_url, snap.scene_pic_url, snap.device?.name || snap.device_id, snap.captured_at, snap.alarm_action)"
                class="flex-1 py-1.5 px-2 bg-slate-50 hover:bg-slate-100 text-slate-700 text-xs font-semibold rounded-lg border border-slate-200 transition-colors text-center cursor-pointer shadow-xs"
              >
                Inspect
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Main Content: Table Mode -->
    <div v-else class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-xs">
      <div class="overflow-x-auto">
        <table class="w-full text-left text-xs text-slate-700">
          <thead class="bg-slate-50 text-slate-500 uppercase text-[11px] font-semibold border-b border-slate-200">
            <tr>
              <th class="py-3 px-4">Face Crop</th>
              <th class="py-3 px-4">Timestamp</th>
              <th class="py-3 px-4">Camera</th>
              <th class="py-3 px-4">Alarm / Flags</th>
              <th class="py-3 px-4 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-if="loading" v-for="i in 5" :key="'skel-row-' + i" class="animate-pulse">
              <td class="py-3 px-4"><div class="w-12 h-12 bg-slate-200 rounded-lg"></div></td>
              <td class="py-3 px-4"><div class="h-4 bg-slate-200 rounded w-24"></div></td>
              <td class="py-3 px-4"><div class="h-4 bg-slate-200 rounded w-32"></div></td>
              <td class="py-3 px-4"><div class="h-4 bg-slate-200 rounded w-16"></div></td>
              <td class="py-3 px-4 text-right"><div class="h-4 bg-slate-200 rounded w-12 ml-auto"></div></td>
            </tr>
            <tr v-else-if="snaps.length === 0">
              <td colspan="5" class="py-12 text-center text-slate-500">No stranger snapshots recorded.</td>
            </tr>
            <tr v-for="snap in snaps" :key="snap.id" role="button" tabindex="0" :aria-label="'Inspect stranger face snapshot captured by ' + (snap.device?.name || 'Camera')" class="hover:bg-slate-50 transition-colors cursor-pointer group" @click="openImageModal(snap.snap_pic_url, snap.scene_pic_url, snap.device?.name || snap.device_id, snap.captured_at, snap.alarm_action)" @keydown.enter="openImageModal(snap.snap_pic_url, snap.scene_pic_url, snap.device?.name || snap.device_id, snap.captured_at, snap.alarm_action)">
              <td class="py-3 px-4">
                <div class="w-12 h-12 rounded-lg bg-slate-100 border border-slate-200 overflow-hidden shrink-0">
                  <img v-if="snap.snap_pic_url" :src="formatMediaUrl(snap.snap_pic_url)" class="w-full h-full object-cover" />
                  <span v-else class="w-full h-full flex items-center justify-center text-[10px] text-slate-400">No Pic</span>
                </div>
              </td>
              <td class="py-3 px-4 font-mono text-slate-700 font-medium">{{ formatDateTime(snap.captured_at) }}</td>
              <td class="py-3 px-4">
                <div class="font-semibold text-slate-900">{{ snap.device?.name || 'Camera' }}</div>
                <div class="text-[11px] text-slate-500 font-mono">{{ snap.device_id }}</div>
              </td>
              <td class="py-3 px-4">
                <div class="flex items-center gap-1.5 flex-wrap">
                  <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase tracking-wider bg-amber-50 text-amber-700 border border-amber-200">
                    Stranger
                  </span>
                  <span v-if="snap.is_no_mask === 1" class="px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase tracking-wider bg-rose-50 text-rose-700 border border-rose-200">
                    No Mask
                  </span>
                  <span v-if="snap.alarm_action" class="text-[11px] text-slate-600 font-medium">
                    {{ snap.alarm_action }}
                  </span>
                </div>
              </td>
              <td class="py-3 px-4 text-right">
                <button 
                  @click="openImageModal(snap.snap_pic_url, snap.scene_pic_url, snap.device?.name || snap.device_id, snap.captured_at, snap.alarm_action)" 
                  class="text-xs text-indigo-600 hover:text-indigo-800 font-semibold px-2.5 py-1 rounded bg-slate-50 border border-slate-200 shadow-xs hover:bg-slate-100 transition-colors cursor-pointer"
                >
                  Inspect
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Pagination Controls -->
    <div v-if="pagination.total > pagination.per_page" class="flex items-center justify-between bg-white border border-slate-200 px-4 py-3 rounded-xl text-xs text-slate-600 shadow-xs">
      <div>
        Showing {{ (pagination.current_page - 1) * pagination.per_page + 1 }} to 
        {{ Math.min(pagination.current_page * pagination.per_page, pagination.total) }} of {{ pagination.total }} captures
      </div>
      <div class="flex items-center gap-2">
        <button 
          :disabled="pagination.current_page === 1" 
          @click="goToPage(pagination.current_page - 1)" 
          class="px-3 py-1 bg-white hover:bg-slate-50 disabled:opacity-40 disabled:hover:bg-white text-slate-700 rounded-lg border border-slate-200 transition-colors shadow-xs cursor-pointer font-medium"
        >
          Previous
        </button>
        <span class="px-2 font-mono text-slate-700 font-medium">{{ pagination.current_page }} / {{ pagination.last_page }}</span>
        <button 
          :disabled="pagination.current_page === pagination.last_page" 
          @click="goToPage(pagination.current_page + 1)" 
          class="px-3 py-1 bg-white hover:bg-slate-50 disabled:opacity-40 disabled:hover:bg-white text-slate-700 rounded-lg border border-slate-200 transition-colors shadow-xs cursor-pointer font-medium"
        >
          Next
        </button>
      </div>
    </div>

    <!-- Image Inspection Modal -->
    <div v-if="modal.show" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm" @click.self="modal.show = false" @keydown.escape="modal.show = false" tabindex="-1">
      <div class="bg-white border border-slate-200 rounded-2xl max-w-3xl w-full p-6 space-y-4 max-h-[90vh] overflow-y-auto shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="stranger-inspect-title">
        <div class="flex items-center justify-between border-b border-slate-200 pb-3">
          <div>
            <h3 id="stranger-inspect-title" class="text-base font-bold text-slate-900 flex items-center gap-2">
              <span>🎭 Stranger Detection Snapshot</span>
              <span class="text-xs px-2 py-0.5 rounded bg-amber-50 text-amber-700 border border-amber-200 font-semibold">Unregistered Face</span>
            </h3>
            <p class="text-xs text-slate-500 mt-0.5">Camera: <strong class="text-slate-800">{{ modal.cameraName }}</strong> &bull; Time: <span class="font-mono text-slate-700 font-medium">{{ formatDateTime(modal.capturedAt) }}</span></p>
          </div>
          <button @click="modal.show = false" aria-label="Close dialog" class="text-slate-400 hover:text-slate-700 text-xl font-bold cursor-pointer">&times;</button>
        </div>

        <div class="grid grid-cols-1">
          <div v-if="modal.snapUrl" class="space-y-2">
            <div class="text-xs font-semibold text-slate-500 flex items-center justify-between">
              <span class="flex items-center gap-1.5">
                <span>Biometric Face Crop</span>
                <span class="text-[10px] px-1.5 py-0.5 rounded bg-amber-50 text-amber-700 border border-amber-200 font-semibold">Ready to Enroll</span>
              </span>
              <div class="flex items-center gap-2">
                <button 
                  @click="openEnrollModalFromSnap" 
                  class="text-[11px] text-indigo-600 hover:text-indigo-800 font-semibold flex items-center gap-1 transition-colors cursor-pointer"
                  title="Add this stranger as personnel"
                >
                  <span>➕ Add as Personnel</span>
                </button>
                <span class="text-slate-300">&bull;</span>
                <a :href="modal.snapUrl" target="_blank" download class="text-[11px] text-slate-500 hover:text-slate-800 hover:underline">Download</a>
              </div>
            </div>
            <div class="relative group rounded-xl overflow-hidden border border-slate-200 bg-slate-950">
              <img :src="formatMediaUrl(modal.snapUrl)" class="rounded-xl w-full max-h-80 object-contain mx-auto" />
              <div class="absolute inset-0 bg-slate-900/40 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center gap-2">
                <button 
                  @click="openEnrollModalFromSnap"
                  class="px-3.5 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-lg shadow-md flex items-center gap-1.5 transition-transform group-hover:scale-105 cursor-pointer"
                >
                  <span>👤 Enroll this Face</span>
                </button>
              </div>
            </div>
          </div>
          <div v-if="modal.sceneUrl" class="space-y-2">
            <div class="text-xs font-semibold text-slate-500 flex items-center justify-between">
              <span>Context Scene View</span>
              <a :href="formatMediaUrl(modal.sceneUrl)" target="_blank" download class="text-[11px] text-indigo-600 hover:underline font-medium">Download</a>
            </div>
            <img :src="formatMediaUrl(modal.sceneUrl)" class="rounded-xl border border-slate-200 w-full max-h-80 object-contain bg-slate-950" />
          </div>
        </div>

        <div v-if="modal.alarmAction" class="bg-rose-50 border border-rose-200 p-3 rounded-xl text-xs text-rose-700 font-medium">
          <strong>Alarm Action:</strong> {{ modal.alarmAction }}
        </div>

        <div class="flex items-center justify-between pt-3 border-t border-slate-200">
          <button 
            v-if="modal.snapUrl"
            @click="openEnrollModalFromSnap" 
            class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-lg shadow-xs transition-all flex items-center gap-2 cursor-pointer"
          >
            <span>👤 Add as Personnel</span>
          </button>
          <div v-else></div>

          <button @click="modal.show = false" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold rounded-lg border border-slate-300 transition-colors cursor-pointer">
            Close
          </button>
        </div>
      </div>
    </div>

    <!-- Enroll Stranger as Personnel Modal -->
    <div v-if="enrollModal.show" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm" @click.self="enrollModal.show = false" @keydown.escape="enrollModal.show = false" tabindex="-1">
      <div class="bg-white border border-slate-200 rounded-2xl max-w-2xl w-full p-6 space-y-4 max-h-[90vh] overflow-y-auto shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="stranger-enroll-title">
        <div class="flex items-center justify-between border-b border-slate-200 pb-3">
          <div>
            <h3 id="stranger-enroll-title" class="text-base font-bold text-slate-900 flex items-center gap-2">
              <span>👤 Enroll Stranger as Personnel</span>
              <span class="text-xs px-2 py-0.5 rounded bg-indigo-50 text-indigo-700 border border-indigo-200 font-semibold">Biometric Enrollment</span>
            </h3>
            <p class="text-xs text-slate-500 mt-0.5">
              Convert stranger detection snapshot into an authorized personnel record and sync to edge cameras
            </p>
          </div>
          <button @click="enrollModal.show = false" aria-label="Close dialog" class="text-slate-400 hover:text-slate-700 text-xl font-bold cursor-pointer">&times;</button>
        </div>

        <form @submit.prevent="saveStrangerAsPersonnel" class="space-y-4">
          <!-- Face Photo Preview & Info -->
          <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 space-y-3 shadow-xs">
            <div class="text-xs font-semibold text-slate-700 flex items-center justify-between">
              <span>Biometric Face Template</span>
              <span class="text-[11px] text-amber-700 font-mono font-medium">
                Source: {{ modal.cameraName }} &bull; {{ formatTime(modal.capturedAt) }}
              </span>
            </div>

            <div class="flex flex-col sm:flex-row items-center gap-4">
              <div class="w-24 h-24 rounded-xl bg-slate-950 border border-indigo-200 overflow-hidden flex items-center justify-center shrink-0 relative shadow-inner">
                <img v-if="enrollModal.previewPhoto" :src="formatMediaUrl(enrollModal.previewPhoto)" class="w-full h-full object-cover" />
                <span v-else class="text-3xl text-slate-400">👤</span>
              </div>
              <div class="flex-1 space-y-1.5 text-left w-full">
                <div class="flex items-center gap-2 flex-wrap">
                  <span class="text-xs text-slate-700 font-medium">Face crop from camera detection</span>
                  <button 
                    v-if="enrollModal.previewPhoto !== modal.snapUrl" 
                    type="button" 
                    @click="resetToSnapPhoto" 
                    class="text-[11px] text-amber-700 hover:underline font-semibold"
                  >
                    Reset to snapshot face
                  </button>
                </div>
                <input 
                  type="file" 
                  accept="image/*" 
                  @change="onEnrollFileSelected" 
                  class="text-xs text-slate-600 file:mr-3 file:py-1 file:px-2.5 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-indigo-600 file:text-white hover:file:bg-indigo-700 cursor-pointer" 
                />
                <p class="text-[10px] text-slate-500">The stranger face crop will be synchronized as the biometric face credential for edge cameras.</p>
              </div>
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <!-- Full Name -->
            <div>
              <label for="enroll-name" class="block text-xs font-medium text-slate-700 mb-1">Full Name *</label>
              <input 
                id="enroll-name"
                v-model="enrollForm.name" 
                required 
                aria-required="true"
                type="text" 
                placeholder="e.g., Jane Doe / Visitor 01"
                class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs" 
              />
            </div>

            <!-- Category (Whitelist / Blacklist) -->
            <div>
              <label for="enroll-person-type" class="block text-xs font-medium text-slate-700 mb-1">Access Category *</label>
              <select id="enroll-person-type" v-model="enrollForm.person_type" class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs cursor-pointer">
                <option :value="0">Whitelist (Allowed Access)</option>
                <option :value="1">Blacklist (Denied / Trigger Alarm)</option>
              </select>
            </div>

            <!-- ID Card -->
            <div>
              <label for="enroll-id-card" class="block text-xs font-medium text-slate-700 mb-1">National ID / Badge Number</label>
              <input 
                id="enroll-id-card"
                v-model="enrollForm.id_card" 
                type="text" 
                placeholder="e.g., ID-90823"
                class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs" 
              />
            </div>

            <!-- Phone Number -->
            <div>
              <label for="enroll-tel-num" class="block text-xs font-medium text-slate-700 mb-1">Phone Number</label>
              <input 
                id="enroll-tel-num"
                v-model="enrollForm.tel_num" 
                type="text" 
                placeholder="e.g., +1 234 567 890"
                class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs" 
              />
            </div>

            <!-- Gender & Birthday -->
            <div>
              <label for="enroll-gender" class="block text-xs font-medium text-slate-700 mb-1">Gender</label>
              <select id="enroll-gender" v-model="enrollForm.gender" class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs cursor-pointer">
                <option :value="0">Male</option>
                <option :value="1">Female</option>
              </select>
            </div>

            <div>
              <label for="enroll-birthday" class="block text-xs font-medium text-slate-700 mb-1">Birthday</label>
              <input 
                id="enroll-birthday"
                v-model="enrollForm.birthday" 
                type="date" 
                class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs" 
              />
            </div>
          </div>

          <!-- Schedule & Validity -->
          <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 space-y-3 shadow-xs">
            <div class="flex items-center justify-between">
              <label class="text-xs font-semibold text-slate-700">Access Schedule & Validity</label>
              <div class="flex items-center gap-4 text-xs">
                <label for="enroll-temp-valid-perm" class="flex items-center gap-1.5 cursor-pointer">
                  <input id="enroll-temp-valid-perm" type="radio" :value="0" v-model="enrollForm.temp_valid" class="text-indigo-600" />
                  <span class="text-slate-800 font-medium">Permanent</span>
                </label>
                <label for="enroll-temp-valid-temp" class="flex items-center gap-1.5 cursor-pointer">
                  <input id="enroll-temp-valid-temp" type="radio" :value="1" v-model="enrollForm.temp_valid" class="text-indigo-600" />
                  <span class="text-slate-800 font-medium">Temporary Period</span>
                </label>
              </div>
            </div>

            <div v-if="enrollForm.temp_valid === 1" class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2">
              <div>
                <label for="enroll-valid-begin" class="block text-[11px] text-slate-500 mb-1">Valid Start Time</label>
                <input 
                  id="enroll-valid-begin"
                  v-model="enrollForm.valid_begin" 
                  type="datetime-local" 
                  class="w-full bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs" 
                />
              </div>
              <div>
                <label for="enroll-valid-end" class="block text-[11px] text-slate-500 mb-1">Valid End Time</label>
                <input 
                  id="enroll-valid-end"
                  v-model="enrollForm.valid_end" 
                  type="datetime-local" 
                  class="w-full bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs" 
                />
              </div>
            </div>
          </div>

          <div class="flex items-center justify-between pt-3 border-t border-slate-200">
            <button 
              type="button" 
              @click="enrollModal.show = false" 
              class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold rounded-lg border border-slate-300 transition-colors cursor-pointer"
            >
              Cancel
            </button>
            <button 
              type="submit" 
              :disabled="enrollSaving" 
              class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-lg shadow-sm transition-all flex items-center gap-2 disabled:opacity-50 cursor-pointer"
            >
              <span>{{ enrollSaving ? 'Enrolling & Syncing...' : '💾 Enroll & Sync to Cameras' }}</span>
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Historical Backfill Modal -->
    <HistoricalBackfillModal
      :is-open="showBackfillModal"
      @close="showBackfillModal = false"
      @success="fetchSnaps"
    />
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { useCameraStore } from '../stores/cameraStore';
import { formatTime, formatDateTime } from '../utils/date';
import formatMediaUrl from '../utils/media';
import HistoricalBackfillModal from '../components/HistoricalBackfillModal.vue';
import notify from '../utils/notify';
import apiClient from '../api/client';

const store = useCameraStore();
const viewMode = ref('grid');
const loading = ref(false);
const showBackfillModal = ref(false);
const snaps = ref([]);

const filters = ref({
  deviceId: '',
  from: '',
  to: '',
});

const pagination = ref({
  current_page: 1,
  last_page: 1,
  per_page: 20,
  total: 0,
});

const modal = ref({
  show: false,
  snapUrl: '',
  sceneUrl: '',
  cameraName: '',
  capturedAt: null,
  alarmAction: '',
  deviceId: '',
});

const enrollModal = ref({
  show: false,
  previewPhoto: null,
  selectedFile: null,
});

const enrollSaving = ref(false);

const enrollForm = ref({
  name: '',
  person_type: 0,
  gender: 0,
  id_card: '',
  tel_num: '',
  address: '',
  birthday: '',
  temp_valid: 0,
  valid_begin: '',
  valid_end: '',
  effect_number: 10000,
});

const isFiltered = computed(() => {
  return !!(filters.value.deviceId || filters.value.from || filters.value.to);
});

async function fetchSnaps(page = 1) {
  loading.value = true;
  try {
    const params = {
      page,
      per_page: 20,
    };
    if (filters.value.deviceId) params.device_id = filters.value.deviceId;
    if (filters.value.from) params.from = filters.value.from;
    if (filters.value.to) params.to = filters.value.to;

    const res = await apiClient.get('/api/stranger-snaps', { params });
    snaps.value = res.data.data || [];
    pagination.value = {
      current_page: res.data.current_page || 1,
      last_page: res.data.last_page || 1,
      per_page: res.data.per_page || 20,
      total: res.data.total || 0,
    };
  } catch (err) {
    console.error('Failed to fetch stranger snaps:', err);
  } finally {
    loading.value = false;
  }
}

function applyFilters() {
  fetchSnaps(1);
}

function resetFilters() {
  filters.value = {
    deviceId: '',
    from: '',
    to: '',
  };
  fetchSnaps(1);
}

function goToPage(page) {
  if (page >= 1 && page <= pagination.value.last_page) {
    fetchSnaps(page);
  }
}

function openImageModal(snapUrl, sceneUrl, cameraName, capturedAt, alarmAction, deviceId) {
  modal.value = {
    show: true,
    snapUrl,
    sceneUrl,
    cameraName: cameraName || 'Camera Device',
    capturedAt,
    alarmAction: alarmAction || '',
    deviceId: deviceId || '',
  };
}

function openEnrollModalFromSnap() {
  enrollModal.value = {
    show: true,
    previewPhoto: modal.value.snapUrl,
    selectedFile: null,
  };
  enrollForm.value = {
    name: '',
    person_type: 0,
    gender: 0,
    id_card: '',
    tel_num: '',
    address: '',
    birthday: '',
    temp_valid: 0,
    valid_begin: '',
    valid_end: '',
    effect_number: 10000,
  };
}

function resetToSnapPhoto() {
  enrollModal.value.previewPhoto = modal.value.snapUrl;
  enrollModal.value.selectedFile = null;
}

function onEnrollFileSelected(e) {
  const file = e.target.files[0];
  if (file) {
    enrollModal.value.selectedFile = file;
    const reader = new FileReader();
    reader.onload = (event) => {
      enrollModal.value.previewPhoto = event.target.result;
    };
    reader.readAsDataURL(file);
  }
}

async function saveStrangerAsPersonnel() {
  if (!enrollForm.value.name.trim()) {
    notify.warning('Validation Error', 'Please enter a full name for the personnel record');
    return;
  }

  enrollSaving.value = true;
  try {
    const data = new FormData();
    Object.keys(enrollForm.value).forEach(key => {
      if (enrollForm.value[key] !== null && enrollForm.value[key] !== undefined && enrollForm.value[key] !== '') {
        data.append(key, enrollForm.value[key]);
      }
    });

    if (enrollModal.value.selectedFile) {
      data.append('photo', enrollModal.value.selectedFile);
    } else if (enrollModal.value.previewPhoto) {
      if (enrollModal.value.previewPhoto.startsWith('data:image')) {
        data.append('photo_base64', enrollModal.value.previewPhoto);
      } else {
        data.append('photo_url', enrollModal.value.previewPhoto);
      }
    }

    const res = await apiClient.post('/api/personnel', data, {
      headers: { 'Content-Type': 'multipart/form-data' }
    });

    enrollModal.value.show = false;
    modal.value.show = false;

    notify.toast(`Enrolled ${res.data.name} (#${res.data.customize_id}) successfully`, 'success');
  } catch (err) {
    notify.error('Enrollment Failed', err.response?.data?.message || 'Failed to enroll stranger as personnel');
  } finally {
    enrollSaving.value = false;
  }
}

watch(
  () => store.strangerSnaps,
  (newSnaps) => {
    if (!newSnaps || newSnaps.length === 0) return;
    if (pagination.value.current_page !== 1) return;

    const latest = newSnaps[0];
    if (!latest) return;

    if (filters.value.deviceId && String(latest.device_id) !== String(filters.value.deviceId)) return;

    const index = snaps.value.findIndex(
      s => (s.id && String(s.id) === String(latest.id)) ||
           (s.captured_at && latest.captured_at && new Date(s.captured_at).getTime() === new Date(latest.captured_at).getTime() && String(s.device_id) === String(latest.device_id))
    );

    if (index !== -1) {
      snaps.value[index] = { ...snaps.value[index], ...latest };
    } else {
      snaps.value.unshift(latest);
      pagination.value.total = (pagination.value.total || 0) + 1;
      if (snaps.value.length > pagination.value.per_page) {
        snaps.value.pop();
      }
    }
  },
  { deep: true, immediate: true }
);

onMounted(() => {
  fetchSnaps(1);
});
</script>
