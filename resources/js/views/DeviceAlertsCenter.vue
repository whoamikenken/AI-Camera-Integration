<template>
  <div class="space-y-6">
    <!-- Top Bar & Summary -->
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 bg-white border border-slate-200/80 p-4 rounded-xl shadow-xs">
      <div class="flex items-center gap-3">
        <div class="relative flex h-3.5 w-3.5">
          <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-rose-400 opacity-75"></span>
          <span class="relative inline-flex rounded-full h-3.5 w-3.5 bg-rose-500"></span>
        </div>
        <div>
          <h2 class="text-lg font-bold text-slate-900 flex items-center gap-2">
            AI Safety &amp; Security Alerts
            <span class="text-xs px-2.5 py-0.5 rounded-full font-mono font-bold bg-rose-50 text-rose-700 border border-rose-200">
              {{ stats.unacknowledged || 0 }} Unresolved
            </span>
          </h2>
          <p class="text-xs text-slate-500">Real-time edge AI incident monitoring: PPE infractions, perimeter intrusions, fire/smoke, thermal spikes &amp; hazards</p>
        </div>
      </div>

      <div class="flex items-center gap-2 flex-wrap w-full sm:w-auto justify-end">
        <!-- View Mode Switcher -->
        <div class="bg-slate-100 p-0.5 rounded-lg border border-slate-200 flex items-center">
          <button 
            @click="viewMode = 'grid'" 
            class="px-2.5 py-1 text-xs font-medium rounded-md transition-colors cursor-pointer"
            :class="viewMode === 'grid' ? 'bg-indigo-600 text-white shadow-xs' : 'text-slate-600 hover:text-slate-900'"
            title="Card Incident View"
          >
            🚨 Incident Cards
          </button>
          <button 
            @click="viewMode = 'table'" 
            class="px-2.5 py-1 text-xs font-medium rounded-md transition-colors cursor-pointer"
            :class="viewMode === 'table' ? 'bg-indigo-600 text-white shadow-xs' : 'text-slate-600 hover:text-slate-900'"
            title="Table List View"
          >
            📋 Audit Table
          </button>
        </div>

        <button 
          @click="fetchAlerts" 
          class="px-3 py-1.5 bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 rounded-lg text-xs font-medium flex items-center gap-1.5 transition-colors shadow-xs cursor-pointer"
        >
          <span>🔄</span> Refresh
        </button>
      </div>
    </div>

    <!-- Alert KPIs Banner -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
      <div class="bg-white border border-slate-200/80 rounded-xl p-4 shadow-xs">
        <div class="text-[11px] font-bold text-slate-500 uppercase tracking-wider">Alerts Today</div>
        <div class="text-2xl font-bold text-slate-900 mt-1 font-mono">{{ stats.total_today || 0 }}</div>
        <div class="text-[10px] text-slate-500 mt-0.5 font-medium">All severity tiers</div>
      </div>

      <div class="bg-white border border-rose-200 rounded-xl p-4 shadow-xs bg-rose-50/20">
        <div class="text-[11px] font-bold text-rose-600 uppercase tracking-wider flex items-center justify-between">
          <span>Critical Hazards</span>
          <span class="animate-pulse text-xs">⚠️</span>
        </div>
        <div class="text-2xl font-bold text-rose-600 mt-1 font-mono">{{ stats.critical_today || 0 }}</div>
        <div class="text-[10px] text-rose-600 mt-0.5 font-medium">Fire, Intrusion, Drops</div>
      </div>

      <div class="bg-white border border-amber-200 rounded-xl p-4 shadow-xs bg-amber-50/20">
        <div class="text-[11px] font-bold text-amber-700 uppercase tracking-wider">Warnings &amp; PPE</div>
        <div class="text-2xl font-bold text-amber-700 mt-1 font-mono">{{ stats.warning_today || 0 }}</div>
        <div class="text-[10px] text-amber-700 mt-0.5 font-medium">Helmet, Vest, Post Absence</div>
      </div>

      <div class="bg-white border border-emerald-200 rounded-xl p-4 shadow-xs bg-emerald-50/20">
        <div class="text-[11px] font-bold text-emerald-700 uppercase tracking-wider">Resolved Today</div>
        <div class="text-2xl font-bold text-emerald-700 mt-1 font-mono">{{ stats.resolved_today || 0 }}</div>
        <div class="text-[10px] text-emerald-700 mt-0.5 font-medium">Closed incidents</div>
      </div>
    </div>

    <!-- Category Filter Chips -->
    <div class="bg-white border border-slate-200 p-4 rounded-xl space-y-3 shadow-xs">
      <div class="flex items-center gap-1.5 overflow-x-auto pb-1 text-xs">
        <button 
          v-for="cat in categoryFilters" 
          :key="cat.id"
          @click="selectedCategory = cat.id; fetchAlerts(1)"
          class="px-3 py-1.5 rounded-lg font-semibold transition-all whitespace-nowrap cursor-pointer flex items-center gap-1.5"
          :class="selectedCategory === cat.id 
            ? 'bg-slate-900 text-white shadow-xs' 
            : 'bg-slate-100 text-slate-600 hover:bg-slate-200/80'"
        >
          <span>{{ cat.icon }}</span>
          <span>{{ cat.label }}</span>
        </button>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-4 gap-3 pt-2 border-t border-slate-100">
        <div>
          <label for="alert-filter-severity" class="block text-[11px] font-bold text-slate-500 uppercase mb-1">Severity</label>
          <select 
            id="alert-filter-severity"
            v-model="selectedSeverity" 
            @change="fetchAlerts(1)"
            class="w-full bg-slate-50 border border-slate-300 rounded-lg px-2.5 py-1.5 text-xs text-slate-800 font-medium focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20"
          >
            <option value="">All Severities</option>
            <option value="CRITICAL">🔴 Critical Only</option>
            <option value="WARNING">🟡 Warning Only</option>
            <option value="INFO">🔵 Info Only</option>
          </select>
        </div>

        <div>
          <label for="alert-filter-status" class="block text-[11px] font-bold text-slate-500 uppercase mb-1">Status</label>
          <select 
            id="alert-filter-status"
            v-model="selectedStatus" 
            @change="fetchAlerts(1)"
            class="w-full bg-slate-50 border border-slate-300 rounded-lg px-2.5 py-1.5 text-xs text-slate-800 font-medium focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20"
          >
            <option value="">All Statuses</option>
            <option value="NEW">New (Unacknowledged)</option>
            <option value="ACKNOWLEDGED">Acknowledged</option>
            <option value="RESOLVED">Resolved</option>
            <option value="DISMISSED">Dismissed</option>
          </select>
        </div>

        <div>
          <label for="alert-filter-camera" class="block text-[11px] font-bold text-slate-500 uppercase mb-1">Camera Device</label>
          <select 
            id="alert-filter-camera"
            v-model="selectedDevice" 
            @change="fetchAlerts(1)"
            class="w-full bg-slate-50 border border-slate-300 rounded-lg px-2.5 py-1.5 text-xs text-slate-800 font-medium focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20"
          >
            <option value="">All Cameras</option>
            <option v-for="dev in store.devices" :key="dev.device_id" :value="dev.device_id">
              {{ dev.name }} ({{ dev.device_id }})
            </option>
          </select>
        </div>

        <div class="flex items-end">
          <button 
            @click="resetFilters" 
            class="w-full py-1.5 px-3 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-xs font-semibold transition-colors cursor-pointer"
          >
            Reset Filters
          </button>
        </div>
      </div>
    </div>

    <!-- Alert List (Card View) -->
    <div v-if="viewMode === 'grid'" class="space-y-4">
      <div v-if="loading" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <div v-for="i in 6" :key="i" class="bg-white border border-slate-200 rounded-xl p-4 space-y-3 animate-pulse">
          <div class="h-40 bg-slate-100 rounded-lg"></div>
          <div class="h-4 bg-slate-100 rounded w-3/4"></div>
          <div class="h-3 bg-slate-100 rounded w-1/2"></div>
        </div>
      </div>

      <div v-else-if="alerts.length === 0" class="bg-white border border-slate-200 rounded-xl p-12 text-center">
        <div class="text-4xl mb-3">🛡️</div>
        <div class="text-base font-bold text-slate-800">No Safety Alerts Detected</div>
        <p class="text-xs text-slate-500 mt-1">All edge AI safety, perimeter, and hazard rules are currently clear.</p>
      </div>

      <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <div 
          v-for="alert in alerts" 
          :key="alert.id"
          class="bg-white border rounded-xl overflow-hidden shadow-xs hover:shadow-md transition-all flex flex-col justify-between"
          :class="getAlertBorderClass(alert)"
        >
          <div>
            <!-- Image & Severity Tag -->
            <button 
              class="w-full relative bg-slate-900 h-44 flex items-center justify-center overflow-hidden group cursor-pointer block border-0 p-0 text-left" 
              @click="openDetailModal(alert)"
              :aria-label="`Inspect incident for ${alert.title}`"
            >
              <img 
                v-if="alert.snap_pic_url || alert.scene_pic_url" 
                :src="alert.snap_pic_url || alert.scene_pic_url" 
                alt="Alert Snapshot" 
                class="w-full h-full object-contain group-hover:scale-105 transition-transform duration-300"
              />
              <div v-else class="text-slate-500 text-xs font-mono flex flex-col items-center gap-1">
                <span class="text-2xl">📸</span>
                <span>No Visual Data</span>
              </div>

              <!-- Top Left Severity Badge -->
              <div class="absolute top-2 left-2">
                <span 
                  class="px-2 py-0.5 rounded-full text-[10px] font-extrabold uppercase tracking-wider shadow-xs font-mono"
                  :class="getSeverityBadgeClass(alert.severity)"
                >
                  {{ alert.severity }}
                </span>
              </div>

              <!-- Top Right Status Badge -->
              <div class="absolute top-2 right-2">
                <span 
                  class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider font-mono shadow-xs"
                  :class="getStatusBadgeClass(alert.status)"
                >
                  {{ alert.status }}
                </span>
              </div>

              <div class="absolute inset-0 bg-slate-900/40 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center gap-2">
                <span class="px-3 py-1.5 bg-white text-slate-900 rounded-lg text-xs font-bold shadow-lg">🔍 Inspect Incident</span>
              </div>
            </button>

            <!-- Content Details -->
            <div class="p-4 space-y-2">
              <div class="flex items-start justify-between gap-2">
                <div>
                  <div class="text-xs font-bold text-slate-900 leading-snug">{{ alert.title }}</div>
                  <div class="text-[11px] text-slate-500 mt-0.5 flex items-center gap-1.5">
                    <span>📡 {{ alert.device?.name || alert.device_id }}</span>
                    <span>&bull;</span>
                    <span class="font-mono">{{ formatDateTime(alert.captured_at) }}</span>
                  </div>
                </div>
                <span class="text-lg">{{ getAlertIcon(alert.alert_type) }}</span>
              </div>

              <p v-if="alert.description" class="text-xs text-slate-600 line-clamp-2 bg-slate-50 p-2 rounded-lg border border-slate-100">
                {{ alert.description }}
              </p>
            </div>
          </div>

          <!-- Bottom Action Buttons -->
          <div class="px-4 py-3 bg-slate-50 border-t border-slate-100 flex items-center justify-between gap-2">
            <span class="text-[10px] font-mono text-slate-400">#{{ alert.id }} &bull; {{ alert.alert_type }}</span>
            <div class="flex items-center gap-1.5">
              <button 
                v-if="alert.status === 'NEW'"
                @click="updateAlertStatus(alert, 'ACKNOWLEDGED')" 
                class="px-2.5 py-1 bg-amber-50 hover:bg-amber-100 text-amber-800 border border-amber-300 rounded-md text-[11px] font-bold transition-colors cursor-pointer"
              >
                Acknowledge
              </button>
              <button 
                v-if="alert.status !== 'RESOLVED'"
                @click="updateAlertStatus(alert, 'RESOLVED')" 
                class="px-2.5 py-1 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[11px] font-bold transition-colors cursor-pointer shadow-2xs"
              >
                Resolve
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Alert List (Table View) -->
    <div v-else class="bg-white border border-slate-200/80 rounded-xl overflow-hidden shadow-xs">
      <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse text-xs">
          <thead>
            <tr class="bg-slate-50 border-b border-slate-200 text-[11px] font-bold text-slate-500 uppercase tracking-wider">
              <th scope="col" class="py-3 px-4">Alert Details</th>
              <th scope="col" class="py-3 px-4">Category</th>
              <th scope="col" class="py-3 px-4">Severity</th>
              <th scope="col" class="py-3 px-4">Camera Source</th>
              <th scope="col" class="py-3 px-4">Captured Time</th>
              <th scope="col" class="py-3 px-4">Status</th>
              <th scope="col" class="py-3 px-4 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <template v-if="loading">
              <tr v-for="i in 5" :key="`skel-${i}`" class="animate-pulse">
                <td class="py-3 px-4">
                  <div class="flex items-center gap-2.5">
                    <div class="w-9 h-9 bg-slate-200 rounded-lg"></div>
                    <div class="space-y-1">
                      <div class="h-4 bg-slate-200 rounded w-24"></div>
                      <div class="h-3 bg-slate-200 rounded w-32"></div>
                    </div>
                  </div>
                </td>
                <td class="py-3 px-4"><div class="h-4 bg-slate-200 rounded w-16"></div></td>
                <td class="py-3 px-4"><div class="h-4 bg-slate-200 rounded w-16"></div></td>
                <td class="py-3 px-4"><div class="h-4 bg-slate-200 rounded w-20"></div></td>
                <td class="py-3 px-4"><div class="h-4 bg-slate-200 rounded w-24"></div></td>
                <td class="py-3 px-4"><div class="h-4 bg-slate-200 rounded w-16"></div></td>
                <td class="py-3 px-4 text-right"><div class="h-6 bg-slate-200 rounded w-12 ml-auto"></div></td>
              </tr>
            </template>
            <tr v-else-if="alerts.length === 0">
              <td colspan="7" class="py-8 text-center text-slate-400">No alerts found matching current filters.</td>
            </tr>
            <tr 
              v-for="alert in alerts" 
              :key="alert.id"
              class="hover:bg-slate-50/80 transition-colors"
            >
              <td class="py-3 px-4">
                <div class="flex items-center gap-2.5">
                  <button 
                    v-if="alert.snap_pic_url || alert.scene_pic_url"
                    class="border-0 p-0 bg-transparent cursor-pointer"
                    @click="openDetailModal(alert)"
                    :aria-label="`Inspect incident for ${alert.title}`"
                  >
                    <img 
                      :src="alert.snap_pic_url || alert.scene_pic_url"
                      alt="Thumbnail"
                      class="w-9 h-9 rounded-lg object-cover border border-slate-200 block"
                    />
                  </button>
                  <div>
                    <button 
                      class="font-bold text-slate-900 cursor-pointer hover:text-indigo-600 bg-transparent border-0 p-0 text-left" 
                      @click="openDetailModal(alert)" 
                      :aria-label="`Inspect incident for ${alert.title}`"
                    >
                      {{ alert.title }}
                    </button>
                    <div class="text-[10px] text-slate-500 truncate max-w-xs">{{ alert.description || 'Edge detection event' }}</div>
                  </div>
                </div>
              </td>
              <td class="py-3 px-4 font-mono font-medium text-slate-700">
                <span class="mr-1">{{ getAlertIcon(alert.alert_type) }}</span>
                {{ alert.alert_type }}
              </td>
              <td class="py-3 px-4">
                <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold uppercase font-mono" :class="getSeverityBadgeClass(alert.severity)">
                  {{ alert.severity }}
                </span>
              </td>
              <td class="py-3 px-4 text-slate-700 font-medium">
                {{ alert.device?.name || alert.device_id }}
              </td>
              <td class="py-3 px-4 text-slate-500 font-mono text-[11px]">
                {{ formatDateTime(alert.captured_at) }}
              </td>
              <td class="py-3 px-4">
                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase font-mono" :class="getStatusBadgeClass(alert.status)">
                  {{ alert.status }}
                </span>
              </td>
              <td class="py-3 px-4 text-right space-x-1">
                <button 
                  @click="openDetailModal(alert)" 
                  class="px-2 py-1 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded text-[11px] font-semibold transition-colors cursor-pointer"
                >
                  View
                </button>
                <button 
                  v-if="alert.status !== 'RESOLVED'"
                  @click="updateAlertStatus(alert, 'RESOLVED')" 
                  class="px-2 py-1 bg-emerald-600 hover:bg-emerald-700 text-white rounded text-[11px] font-semibold transition-colors cursor-pointer"
                >
                  Resolve
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Pagination -->
    <div v-if="pagination.total > pagination.per_page" class="flex items-center justify-between bg-white border border-slate-200 p-4 rounded-xl shadow-xs text-xs">
      <div class="text-slate-500">
        Showing <span class="font-bold text-slate-800">{{ pagination.from }}</span> to <span class="font-bold text-slate-800">{{ pagination.to }}</span> of <span class="font-bold text-slate-800">{{ pagination.total }}</span> alerts
      </div>
      <div class="flex items-center gap-1.5">
        <button 
          @click="fetchAlerts(pagination.current_page - 1)" 
          :disabled="pagination.current_page <= 1"
          class="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg font-medium transition-colors disabled:opacity-40 cursor-pointer"
        >
          Previous
        </button>
        <span class="px-2 font-mono font-bold text-slate-700">{{ pagination.current_page }} / {{ pagination.last_page }}</span>
        <button 
          @click="fetchAlerts(pagination.current_page + 1)" 
          :disabled="pagination.current_page >= pagination.last_page"
          class="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg font-medium transition-colors disabled:opacity-40 cursor-pointer"
        >
          Next
        </button>
      </div>
    </div>

    <!-- Detailed Incident Modal -->
    <div v-if="selectedAlertModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs" @keydown.escape="selectedAlertModal = null">
      <div 
        class="bg-white rounded-2xl max-w-3xl w-full max-h-[90vh] overflow-y-auto shadow-2xl border border-slate-200 flex flex-col"
        role="dialog"
        aria-modal="true"
        aria-labelledby="incident-modal-title"
      >
        <!-- Modal Header -->
        <div class="p-4 sm:p-5 border-b border-slate-100 flex items-center justify-between sticky top-0 bg-white z-10">
          <div class="flex items-center gap-3">
            <span class="text-2xl">{{ getAlertIcon(selectedAlertModal.alert_type) }}</span>
            <div>
              <h3 id="incident-modal-title" class="text-base font-bold text-slate-900">{{ selectedAlertModal.title }}</h3>
              <div class="text-xs text-slate-500 font-mono">Incident #{{ selectedAlertModal.id }} &bull; {{ formatDateTime(selectedAlertModal.captured_at) }}</div>
            </div>
          </div>
          <button @click="selectedAlertModal = null" aria-label="Close dialog" class="p-1.5 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-lg cursor-pointer">
            ✕
          </button>
        </div>

        <!-- Modal Body -->
        <div class="p-5 space-y-4 flex-1">
          <!-- Side-by-Side Images -->
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div class="bg-slate-950 rounded-xl p-2 flex flex-col items-center justify-center min-h-[220px]">
              <div class="text-[10px] font-bold text-slate-400 uppercase mb-1">Target Crop / Snapshot</div>
              <img 
                v-if="selectedAlertModal.snap_pic_url" 
                :src="selectedAlertModal.snap_pic_url" 
                alt="Target Crop" 
                class="max-h-56 object-contain rounded"
              />
              <span v-else class="text-slate-600 text-xs font-mono">No Crop Image</span>
            </div>

            <div class="bg-slate-950 rounded-xl p-2 flex flex-col items-center justify-center min-h-[220px]">
              <div class="text-[10px] font-bold text-slate-400 uppercase mb-1">Full Scene Snapshot</div>
              <img 
                v-if="selectedAlertModal.scene_pic_url" 
                :src="selectedAlertModal.scene_pic_url" 
                alt="Full Scene" 
                class="max-h-56 object-contain rounded"
              />
              <span v-else class="text-slate-600 text-xs font-mono">No Scene Image</span>
            </div>
          </div>

          <!-- Metadata Grid -->
          <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 bg-slate-50 p-4 rounded-xl border border-slate-100 text-xs">
            <div>
              <span class="text-slate-500 text-[10px] uppercase font-bold block">Camera Device</span>
              <span class="font-bold text-slate-900">{{ selectedAlertModal.device?.name || selectedAlertModal.device_id }}</span>
            </div>
            <div>
              <span class="text-slate-500 text-[10px] uppercase font-bold block">Category</span>
              <span class="font-mono font-bold text-indigo-700">{{ selectedAlertModal.alert_type }}</span>
            </div>
            <div>
              <span class="text-slate-500 text-[10px] uppercase font-bold block">Severity</span>
              <span class="font-bold font-mono" :class="selectedAlertModal.severity === 'CRITICAL' ? 'text-rose-600' : 'text-amber-600'">
                {{ selectedAlertModal.severity }}
              </span>
            </div>
            <div>
              <span class="text-slate-500 text-[10px] uppercase font-bold block">Protocol Operator</span>
              <span class="font-mono text-slate-700">{{ selectedAlertModal.operator || 'N/A' }}</span>
            </div>
            <div>
              <span class="text-slate-500 text-[10px] uppercase font-bold block">Status</span>
              <span class="font-bold font-mono" :class="getStatusBadgeClass(selectedAlertModal.status)">
                {{ selectedAlertModal.status }}
              </span>
            </div>
            <div>
              <span class="text-slate-500 text-[10px] uppercase font-bold block">Timestamp</span>
              <span class="font-mono text-slate-700">{{ formatDateTime(selectedAlertModal.captured_at) }}</span>
            </div>
          </div>

          <!-- Description / Narrative -->
          <div v-if="selectedAlertModal.description" class="space-y-1">
            <label class="text-[11px] font-bold text-slate-500 uppercase">Incident Summary</label>
            <p class="text-xs text-slate-700 bg-amber-50/60 border border-amber-200 p-3 rounded-xl font-medium">
              {{ selectedAlertModal.description }}
            </p>
          </div>

          <!-- Raw Telemetry Payload -->
          <div v-if="selectedAlertModal.details" class="space-y-1">
            <label class="text-[11px] font-bold text-slate-500 uppercase">Raw Vision Telemetry Payload</label>
            <pre class="bg-slate-900 text-slate-200 p-3 rounded-xl text-[10px] font-mono overflow-x-auto max-h-40">{{ JSON.stringify(selectedAlertModal.details, null, 2) }}</pre>
          </div>
        </div>

        <!-- Modal Footer Actions -->
        <div class="p-4 bg-slate-50 border-t border-slate-100 flex items-center justify-between gap-3 sticky bottom-0">
          <button @click="selectedAlertModal = null" class="px-4 py-2 bg-white border border-slate-200 text-slate-700 rounded-xl text-xs font-semibold hover:bg-slate-100 cursor-pointer">
            Close
          </button>
          <div class="flex items-center gap-2">
            <button 
              v-if="selectedAlertModal.status === 'NEW'"
              @click="updateAlertStatus(selectedAlertModal, 'ACKNOWLEDGED')" 
              class="px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded-xl text-xs font-bold shadow-xs cursor-pointer"
            >
              Acknowledge Incident
            </button>
            <button 
              v-if="selectedAlertModal.status !== 'RESOLVED'"
              @click="updateAlertStatus(selectedAlertModal, 'RESOLVED')" 
              class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold shadow-xs cursor-pointer"
            >
              Mark as Resolved
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { useCameraStore } from '../stores/cameraStore';
import apiClient from '../api/client';
import echo from '../echo';
import notify from '../utils/notify';
import { formatDateTime } from '../utils/date';

const store = useCameraStore();

const viewMode = ref('grid');
const loading = ref(false);
const alerts = ref([]);
const stats = ref({
  total_today: 0,
  critical_today: 0,
  warning_today: 0,
  unacknowledged: 0,
  resolved_today: 0,
});

const selectedCategory = ref('ALL');
const selectedSeverity = ref('');
const selectedStatus = ref('');
const selectedDevice = ref('');
const selectedAlertModal = ref(null);

const pagination = ref({
  current_page: 1,
  last_page: 1,
  per_page: 15,
  total: 0,
  from: 0,
  to: 0,
});

const categoryFilters = [
  { id: 'ALL', label: 'All Incidents', icon: '🚨' },
  { id: 'PPE_VIOLATION', label: 'PPE & Safety Helmet', icon: '👷' },
  { id: 'TRIPWIRE_INCURSION', label: 'Tripwire Incursion', icon: '🚧' },
  { id: 'AREA_INTRUSION', label: 'Restricted Area', icon: '⛔' },
  { id: 'FIRE_SMOKE', label: 'Fire & Smoke', icon: '🔥' },
  { id: 'TEMPERATURE_HIGH', label: 'Thermal & Spark', icon: '⚡' },
  { id: 'LEAVE_POST', label: 'Duty Post Absence', icon: '🛡️' },
  { id: 'PARABOLIC_DROP', label: 'Falling Objects', icon: '🏢' },
  { id: 'KITCHEN_HYGIENE', label: 'Kitchen Hygiene', icon: '🍽️' },
  { id: 'SAFETY_RIDE', label: 'Safe Riding', icon: '🚲' },
];

function getAlertIcon(type) {
  const map = {
    'PPE_VIOLATION': '👷',
    'TRIPWIRE_INCURSION': '🚧',
    'AREA_INTRUSION': '⛔',
    'FIRE_SMOKE': '🔥',
    'TEMPERATURE_HIGH': '⚡',
    'LEAVE_POST': '🛡️',
    'PARABOLIC_DROP': '🏢',
    'KITCHEN_HYGIENE': '🍽️',
    'SAFETY_RIDE': '🚲',
    'PLATE_RECOGNITION': '🚗',
    'OVERCROWDING': '👥',
  };
  return map[type] || '🚨';
}

function getAlertBorderClass(alert) {
  if (alert.severity === 'CRITICAL') return 'border-rose-300 ring-1 ring-rose-200';
  if (alert.severity === 'WARNING') return 'border-amber-200';
  return 'border-slate-200';
}

function getSeverityBadgeClass(sev) {
  if (sev === 'CRITICAL') return 'bg-rose-600 text-white';
  if (sev === 'WARNING') return 'bg-amber-500 text-white';
  return 'bg-blue-600 text-white';
}

function getStatusBadgeClass(status) {
  if (status === 'NEW') return 'bg-rose-100 text-rose-800 border border-rose-200';
  if (status === 'ACKNOWLEDGED') return 'bg-amber-100 text-amber-800 border border-amber-200';
  if (status === 'RESOLVED') return 'bg-emerald-100 text-emerald-800 border border-emerald-200';
  return 'bg-slate-100 text-slate-700';
}

async function fetchAlertStats() {
  try {
    const res = await apiClient.get('/api/device-alerts/stats');
    if (res.data) {
      stats.value = res.data;
    }
  } catch (err) {
    console.error('Failed to fetch alert stats:', err);
  }
}

async function fetchAlerts(page = 1) {
  loading.value = true;
  try {
    const params = {
      page,
      per_page: pagination.value.per_page,
    };

    if (selectedCategory.value && selectedCategory.value !== 'ALL') {
      params.alert_type = selectedCategory.value;
    }
    if (selectedSeverity.value) {
      params.severity = selectedSeverity.value;
    }
    if (selectedStatus.value) {
      params.status = selectedStatus.value;
    }
    if (selectedDevice.value) {
      params.device_id = selectedDevice.value;
    }

    const res = await apiClient.get('/api/device-alerts', { params });
    if (res.data) {
      alerts.value = res.data.data || [];
      pagination.value = {
        current_page: res.data.current_page,
        last_page: res.data.last_page,
        per_page: res.data.per_page,
        total: res.data.total,
        from: res.data.from,
        to: res.data.to,
      };
    }
  } catch (err) {
    notify.error('Fetch Failed', err.response?.data?.message || err.message);
  } finally {
    loading.value = false;
  }
}

function resetFilters() {
  selectedCategory.value = 'ALL';
  selectedSeverity.value = '';
  selectedStatus.value = '';
  selectedDevice.value = '';
  fetchAlerts(1);
}

function openDetailModal(alert) {
  selectedAlertModal.value = alert;
}

async function updateAlertStatus(alert, newStatus) {
  try {
    const res = await apiClient.patch(`/api/device-alerts/${alert.id}/status`, { status: newStatus });
    if (res.data) {
      alert.status = res.data.status;
      alert.resolved_at = res.data.resolved_at;
      notify.success('Status Updated', `Alert #${alert.id} marked as ${newStatus}`);
      await fetchAlertStats();
      if (selectedAlertModal.value && selectedAlertModal.value.id === alert.id) {
        selectedAlertModal.value.status = newStatus;
      }
    }
  } catch (err) {
    notify.error('Update Failed', err.response?.data?.message || err.message);
  }
}

function handleLiveAlertReceived(e) {
  alerts.value.unshift(e);
  if (alerts.value.length > 50) {
    alerts.value.pop();
  }
  stats.value.total_today = (stats.value.total_today || 0) + 1;
  if (e.severity === 'CRITICAL') {
    stats.value.critical_today = (stats.value.critical_today || 0) + 1;
  } else if (e.severity === 'WARNING') {
    stats.value.warning_today = (stats.value.warning_today || 0) + 1;
  }
  if (e.status === 'NEW' || !e.status) {
    stats.value.unacknowledged = (stats.value.unacknowledged || 0) + 1;
  }
  if (e.severity === 'CRITICAL') {
    notify.error('CRITICAL ALARM', `${e.title} detected on ${e.device_name}`);
  } else {
    notify.warning('AI Alert', `${e.title} on ${e.device_name}`);
  }
}

function handleLiveAlertUpdated(e) {
  const index = alerts.value.findIndex(a => String(a.id) === String(e.id));
  if (index !== -1) {
    alerts.value[index] = { ...alerts.value[index], ...e };
  }
  if (selectedAlertModal.value && String(selectedAlertModal.value.id) === String(e.id)) {
    selectedAlertModal.value = { ...selectedAlertModal.value, ...e };
  }
  if (e.status === 'RESOLVED') {
    stats.value.resolved_today = (stats.value.resolved_today || 0) + 1;
    if (e.previous_status === 'NEW') {
      stats.value.unacknowledged = Math.max(0, (stats.value.unacknowledged || 0) - 1);
    }
  } else if (e.status === 'ACKNOWLEDGED' && e.previous_status === 'NEW') {
    stats.value.unacknowledged = Math.max(0, (stats.value.unacknowledged || 0) - 1);
  }
}

onMounted(() => {
  fetchAlertStats();
  fetchAlerts(1);

  // Real-time echo subscription for DeviceAlertReceived and DeviceAlertUpdated
  echo.private('device-alerts')
    .listen('.DeviceAlertReceived', handleLiveAlertReceived)
    .listen('DeviceAlertReceived', handleLiveAlertReceived)
    .listen('.DeviceAlertUpdated', handleLiveAlertUpdated)
    .listen('DeviceAlertUpdated', handleLiveAlertUpdated);
});

onUnmounted(() => {
  echo.private('device-alerts')
    .stopListening('.DeviceAlertReceived', handleLiveAlertReceived)
    .stopListening('DeviceAlertReceived', handleLiveAlertReceived)
    .stopListening('.DeviceAlertUpdated', handleLiveAlertUpdated)
    .stopListening('DeviceAlertUpdated', handleLiveAlertUpdated);
});
</script>
