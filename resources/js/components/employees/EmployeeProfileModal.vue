<template>
  <div 
    v-if="show && employee" 
    role="dialog"
    aria-modal="true"
    aria-labelledby="employee-profile-title"
    @keydown.escape="$emit('close')"
    class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4"
  >
    <div class="bg-white rounded-2xl shadow-2xl border border-slate-200 max-w-2xl w-full max-h-[90vh] flex flex-col overflow-hidden animate-in fade-in zoom-in-95 duration-200">
      <!-- Profile Header Banner -->
      <div class="p-6 bg-gradient-to-r from-slate-900 to-indigo-950 text-white relative">
        <button
          type="button"
          @click="$emit('close')"
          aria-label="Close employee profile"
          class="absolute top-4 right-4 text-slate-400 hover:text-white p-1 rounded-lg hover:bg-white/10 transition-colors cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-400"
        >
          ✕
        </button>

        <div class="flex items-center gap-5">
          <!-- Avatar Frame -->
          <div class="w-20 h-20 rounded-2xl bg-white/10 border-2 border-white/20 overflow-hidden shadow-inner flex items-center justify-center shrink-0">
            <img
              v-if="employee.avatar || employee.personnel?.photo_base64"
              :src="employee.avatar || employee.personnel?.photo_base64"
              alt="Employee Profile"
              class="w-full h-full object-cover"
            />
            <span v-else class="text-3xl text-white/50" aria-hidden="true">👤</span>
          </div>

          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2.5">
              <h2 id="employee-profile-title" class="text-lg font-bold tracking-tight truncate">
                {{ employee.first_name }} {{ employee.last_name }}
              </h2>
              <span
                class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider border"
                :class="statusBadgeClass(employee.employment_status)"
              >
                {{ employee.employment_status || 'active' }}
              </span>
            </div>

            <div class="text-xs text-indigo-200 mt-0.5 font-mono">
              {{ employee.employee_code }} &bull;
              <span>{{ employee.designation?.name || 'No Designation' }}</span>
            </div>

            <div class="text-[11px] text-slate-300 mt-1 flex flex-wrap gap-x-4 gap-y-1">
              <span>🏢 {{ employee.department?.name || 'Unassigned Dept' }}</span>
              <span>📍 {{ employee.location?.name || 'HQ Campus' }}</span>
              <span>💼 {{ employee.employment_type || 'Full-Time' }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Segmented Navigation Tabs -->
      <div 
        role="tablist" 
        aria-label="Employee profile details"
        class="border-b border-slate-200 bg-slate-50/70 px-6 flex items-center gap-4 text-xs font-semibold overflow-x-auto"
      >
        <button
          v-for="tab in tabs"
          :key="tab.id"
          role="tab"
          :id="`profile-tab-${tab.id}`"
          :aria-controls="`profile-panel-${tab.id}`"
          :aria-selected="activeTab === tab.id"
          @click="activeTab = tab.id"
          class="py-3 border-b-2 transition-all cursor-pointer whitespace-nowrap focus:outline-none focus:ring-2 focus:ring-indigo-500"
          :class="activeTab === tab.id ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-slate-500 hover:text-slate-700'"
        >
          {{ tab.label }}
        </button>
      </div>

      <!-- Tab Content Area -->
      <div class="p-6 overflow-y-auto flex-1 space-y-4 text-xs">
        <!-- 1. Personal Info Tab -->
        <div 
          v-if="activeTab === 'personal'" 
          role="tabpanel"
          id="profile-panel-personal"
          aria-labelledby="profile-tab-personal"
          class="grid grid-cols-2 gap-4"
        >
          <div class="bg-slate-50 p-3.5 rounded-xl border border-slate-100">
            <div class="text-[11px] text-slate-500 font-medium">Work Email</div>
            <div class="font-semibold text-slate-900 mt-0.5">{{ employee.work_email || '—' }}</div>
          </div>

          <div class="bg-slate-50 p-3.5 rounded-xl border border-slate-100">
            <div class="text-[11px] text-slate-500 font-medium">Personal Email</div>
            <div class="font-semibold text-slate-900 mt-0.5">{{ employee.personal_email || '—' }}</div>
          </div>

          <div class="bg-slate-50 p-3.5 rounded-xl border border-slate-100">
            <div class="text-[11px] text-slate-500 font-medium">Phone Number</div>
            <div class="font-semibold text-slate-900 mt-0.5">{{ employee.phone || '—' }}</div>
          </div>

          <div class="bg-slate-50 p-3.5 rounded-xl border border-slate-100">
            <div class="text-[11px] text-slate-500 font-medium">Date of Joining</div>
            <div class="font-semibold text-slate-900 mt-0.5">{{ employee.date_of_joining ? employee.date_of_joining.slice(0, 10) : '—' }}</div>
          </div>

          <div class="col-span-2 bg-slate-50 p-3.5 rounded-xl border border-slate-100">
            <div class="text-[11px] text-slate-500 font-medium">Emergency Contact</div>
            <div class="font-semibold text-slate-900 mt-0.5">
              {{ employee.emergency_contact_name || 'No contact listed' }}
              <span v-if="employee.emergency_contact_phone" class="text-slate-500 font-normal">({{ employee.emergency_contact_phone }})</span>
            </div>
          </div>
        </div>

        <!-- 2. Employment Details Tab -->
        <div 
          v-else-if="activeTab === 'employment'" 
          role="tabpanel"
          id="profile-panel-employment"
          aria-labelledby="profile-tab-employment"
          class="grid grid-cols-2 gap-4"
        >
          <div class="bg-slate-50 p-3.5 rounded-xl border border-slate-100">
            <div class="text-[11px] text-slate-500 font-medium">Department</div>
            <div class="font-semibold text-slate-900 mt-0.5">{{ employee.department?.name || 'Unassigned' }}</div>
          </div>

          <div class="bg-slate-50 p-3.5 rounded-xl border border-slate-100">
            <div class="text-[11px] text-slate-500 font-medium">Designation &amp; Level</div>
            <div class="font-semibold text-slate-900 mt-0.5">
              {{ employee.designation?.name || 'Unassigned' }}
              <span v-if="employee.designation?.level" class="text-indigo-600 font-normal">(Level {{ employee.designation.level }})</span>
            </div>
          </div>

          <div class="bg-slate-50 p-3.5 rounded-xl border border-slate-100">
            <div class="text-[11px] text-slate-500 font-medium">Primary Office Location</div>
            <div class="font-semibold text-slate-900 mt-0.5">{{ employee.location?.name || 'Default Campus' }}</div>
          </div>

          <div class="bg-slate-50 p-3.5 rounded-xl border border-slate-100">
            <div class="text-[11px] text-slate-500 font-medium">Reporting Manager</div>
            <div class="font-semibold text-slate-900 mt-0.5">
              {{ employee.manager ? `${employee.manager.first_name} ${employee.manager.last_name}` : 'Direct Report to Board / Executive' }}
            </div>
          </div>
        </div>

        <!-- 3. Shift Schedule Tab -->
        <div 
          v-else-if="activeTab === 'schedule'" 
          role="tabpanel"
          id="profile-panel-schedule"
          aria-labelledby="profile-tab-schedule"
          class="space-y-4"
        >
          <div class="bg-indigo-50/60 p-4 rounded-xl border border-indigo-100 flex items-center justify-between">
            <div>
              <div class="text-[11px] font-bold text-indigo-900 uppercase tracking-wider">Primary Roster Shift</div>
              <div class="text-sm font-bold text-slate-900 mt-0.5">
                {{ employee.shift?.name || 'Standard Day Shift (Default)' }}
              </div>
              <div class="text-xs text-indigo-700 mt-1">
                Hours: <code class="font-bold">{{ employee.shift?.shift_start || '09:00:00' }}</code> to
                <code class="font-bold">{{ employee.shift?.shift_end || '18:00:00' }}</code>
                <span v-if="employee.shift?.is_overnight" class="ml-2 px-1.5 py-0.5 bg-purple-100 text-purple-700 font-bold rounded text-[10px]">Overnight 🌙</span>
              </div>
            </div>
            <button
              @click="$emit('assign-shift', employee)"
              class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold text-xs rounded-xl shadow-xs cursor-pointer transition-colors"
            >
              Change Shift
            </button>
          </div>

          <div class="grid grid-cols-3 gap-3 text-center">
            <div class="p-3 bg-slate-50 rounded-xl border border-slate-100">
              <div class="text-[10px] text-slate-500 uppercase font-semibold">Grace Period</div>
              <div class="text-base font-bold text-slate-800 mt-1">{{ employee.shift?.grace_period_minutes || 15 }} min</div>
            </div>
            <div class="p-3 bg-slate-50 rounded-xl border border-slate-100">
              <div class="text-[10px] text-slate-500 uppercase font-semibold">Early Out Tolerance</div>
              <div class="text-base font-bold text-slate-800 mt-1">{{ employee.shift?.early_out_threshold_minutes || 30 }} min</div>
            </div>
            <div class="p-3 bg-slate-50 rounded-xl border border-slate-100">
              <div class="text-[10px] text-slate-500 uppercase font-semibold">Break Deduction</div>
              <div class="text-base font-bold text-slate-800 mt-1">{{ employee.shift?.break_duration_minutes || 60 }} min</div>
            </div>
          </div>
        </div>

        <!-- 4. Camera Face Biometrics Tab -->
        <div 
          v-else-if="activeTab === 'biometrics'" 
          role="tabpanel"
          id="profile-panel-biometrics"
          aria-labelledby="profile-tab-biometrics"
          class="space-y-4"
        >
          <div class="flex items-center gap-5 p-4 rounded-xl bg-slate-50 border border-slate-100">
            <div class="w-24 h-24 rounded-2xl bg-white border border-slate-200 overflow-hidden shadow-xs flex items-center justify-center shrink-0">
              <img
                v-if="employee.personnel?.photo_base64 || employee.avatar"
                :src="employee.personnel?.photo_base64 || employee.avatar"
                alt="Enrolled Facial Template"
                class="w-full h-full object-cover"
              />
              <span v-else class="text-4xl text-slate-300">👤</span>
            </div>

            <div class="flex-1 space-y-1">
              <div class="flex items-center gap-2">
                <span class="text-xs font-bold text-slate-900">Camera Face Library Status:</span>
                <span
                  v-if="employee.personnel_id"
                  class="px-2 py-0.5 bg-emerald-100 text-emerald-800 font-bold rounded-full text-[10px]"
                >
                  ✓ Enrolled &amp; Linked
                </span>
                <span v-else class="px-2 py-0.5 bg-amber-100 text-amber-800 font-bold rounded-full text-[10px]">
                  ⚠️ Non-Biometric Staff
                </span>
              </div>

              <div class="text-[11px] text-slate-600 font-mono">
                Linked Personnel ID: <code class="font-bold text-indigo-600">{{ employee.personnel_id || 'None' }}</code> &bull;
                Camera Customize ID: <code class="font-bold text-indigo-600">{{ employee.personnel?.customize_id || 'None' }}</code>
              </div>

              <div class="text-[11px] text-slate-500">
                Admission Status: <span class="font-bold" :class="employee.personnel?.person_type === 1 ? 'text-rose-600' : 'text-emerald-600'">
                  {{ employee.personnel?.person_type === 1 ? 'Blacklisted / Denied' : 'Whitelisted / Allowed' }}
                </span>
              </div>

              <div class="pt-2">
                <button
                  v-if="employee.personnel_id"
                  type="button"
                  @click="syncToCamera"
                  :disabled="syncing"
                  class="px-3 py-1.5 bg-slate-900 hover:bg-slate-800 disabled:opacity-50 text-white font-semibold text-[11px] rounded-lg shadow-xs cursor-pointer inline-flex items-center gap-1.5 transition-colors"
                >
                  <span>⚡</span>
                  <span>{{ syncing ? 'Pushing to Fleet...' : 'Re-sync Face to Camera Fleet' }}</span>
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Modal Footer -->
      <div class="px-6 py-4 border-t border-slate-100 flex items-center justify-between bg-slate-50/50">
        <span class="text-[11px] text-slate-400">ID: {{ employee.id }} &bull; Registered {{ employee.created_at ? employee.created_at.slice(0, 10) : '' }}</span>
        <div class="flex items-center gap-2">
          <button
            @click="$emit('edit', employee)"
            class="px-4 py-2 text-xs font-semibold text-slate-700 bg-white border border-slate-200 hover:bg-slate-50 rounded-xl transition-colors cursor-pointer shadow-xs"
          >
            Edit Profile
          </button>
          <button
            @click="$emit('close')"
            class="px-4 py-2 text-xs font-bold text-white bg-slate-900 hover:bg-slate-800 rounded-xl transition-colors cursor-pointer shadow-xs"
          >
            Close
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import apiClient from '../../api/client';
import notify from '../../utils/notify';

const props = defineProps({
  show: Boolean,
  employee: Object,
});

const emit = defineEmits(['close', 'edit', 'assign-shift']);

const activeTab = ref('personal');
const syncing = ref(false);

function handleGlobalKeydown(e) {
  if (e.key === 'Escape' && props.show) {
    emit('close');
  }
}

onMounted(() => {
  window.addEventListener('keydown', handleGlobalKeydown);
});

onUnmounted(() => {
  window.removeEventListener('keydown', handleGlobalKeydown);
});

const tabs = [
  { id: 'personal', label: '👤 Personal Info' },
  { id: 'employment', label: '🏢 Employment & Org' },
  { id: 'schedule', label: '⏱️ Shift Schedule' },
  { id: 'biometrics', label: '📷 Camera Face Biometrics' },
];

function statusBadgeClass(status) {
  switch (status) {
    case 'active':
      return 'bg-emerald-500/20 text-emerald-300 border-emerald-400/30';
    case 'probation':
      return 'bg-blue-500/20 text-blue-300 border-blue-400/30';
    case 'suspended':
      return 'bg-amber-500/20 text-amber-300 border-amber-400/30';
    case 'terminated':
    case 'resigned':
      return 'bg-rose-500/20 text-rose-300 border-rose-400/30';
    default:
      return 'bg-slate-500/20 text-slate-300 border-slate-400/30';
  }
}

async function syncToCamera() {
  if (!props.employee?.personnel_id) return;
  syncing.value = true;
  try {
    await apiClient.post(`/personnel/${props.employee.personnel_id}/sync-now`);
    notify.success('Camera Sync Triggered', 'Facial template dispatched to camera fleet queue.');
  } catch (err) {
    notify.error('Sync Error', err.response?.data?.message || 'Could not push to cameras.');
  } finally {
    syncing.value = false;
  }
}
</script>
