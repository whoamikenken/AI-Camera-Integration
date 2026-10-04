<template>
  <div class="space-y-6 max-w-5xl">
    <!-- Header with Action -->
    <div class="flex items-center justify-between bg-white border border-slate-200/80 p-4 rounded-xl shadow-xs">
      <div>
        <h3 class="text-base font-bold text-slate-900">Global System Parameters &amp; Policies</h3>
        <p class="text-xs text-slate-500">Fine-tune attendance calculation engines, visitor security rules, and alert channels</p>
      </div>

      <div class="flex items-center gap-2">
        <button
          @click="fetchSettings"
          :disabled="loading"
          class="px-3 py-2 bg-white hover:bg-slate-50 text-slate-700 text-xs font-medium border border-slate-200 rounded-lg shadow-xs transition-colors cursor-pointer"
        >
          🔄 Refresh
        </button>
        <button
          @click="saveAllSettings"
          :disabled="saving || !hasChanges"
          class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-lg shadow-sm transition-all flex items-center gap-1.5 cursor-pointer disabled:opacity-50"
        >
          <span v-if="saving" class="w-3.5 h-3.5 border-2 border-white border-t-transparent rounded-full animate-spin"></span>
          <span>{{ saving ? 'Saving Changes...' : 'Save Settings' }}</span>
        </button>
      </div>
    </div>

    <!-- Group 1: Attendance Engine -->
    <div class="bg-white border border-slate-200 rounded-xl p-6 shadow-xs space-y-5">
      <div class="border-b border-slate-100 pb-3">
        <h4 class="text-sm font-bold text-slate-900 flex items-center gap-2">
          <span>⏱️</span> Attendance Engine Parameters
        </h4>
        <p class="text-[11px] text-slate-500">Defines how camera biometric scans are paired into daily attendance records</p>
      </div>

      <div class="space-y-4">
        <!-- Toggle: Auto Process -->
        <div class="flex items-center justify-between py-2 border-b border-slate-100">
          <div>
            <div class="text-xs font-bold text-slate-800">Auto-Process Real-Time Camera Telemetry</div>
            <div class="text-[11px] text-slate-500">Automatically pair entry/exit scans and update employee attendance in real-time</div>
          </div>
          <button
            type="button"
            role="switch"
            :aria-checked="settings['attendance.auto_process']"
            aria-label="attendance auto process"
            @click="settings['attendance.auto_process'] = !settings['attendance.auto_process']"
            class="relative inline-flex h-5 w-10 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none"
            :class="settings['attendance.auto_process'] ? 'bg-indigo-600' : 'bg-slate-300'"
          >
            <span
              class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
              :class="settings['attendance.auto_process'] ? 'translate-x-5' : 'translate-x-0'"
            />
          </button>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 pt-2">
          <div>
            <label for="late_grace" class="block text-xs font-semibold text-slate-700">Late Grace Period (Minutes)</label>
            <input
              id="late_grace"
              v-model.number="settings['attendance.late_grace_minutes']"
              type="number"
              min="0"
              class="w-full mt-1 bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs font-mono text-slate-900 focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
            />
            <span class="text-[10px] text-slate-400">Minutes past shift start before marked late</span>
          </div>

          <div>
            <label for="overtime_threshold" class="block text-xs font-semibold text-slate-700">Overtime Threshold (Minutes)</label>
            <input
              id="overtime_threshold"
              v-model.number="settings['attendance.overtime_threshold_minutes']"
              type="number"
              min="0"
              class="w-full mt-1 bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs font-mono text-slate-900 focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
            />
            <span class="text-[10px] text-slate-400">Minutes past shift end before OT accrues</span>
          </div>

          <div>
            <label for="half_day_threshold" class="block text-xs font-semibold text-slate-700">Half-Day Threshold (Hours)</label>
            <input
              id="half_day_threshold"
              v-model.number="settings['attendance.half_day_threshold_hours']"
              type="number"
              step="0.5"
              min="1"
              class="w-full mt-1 bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs font-mono text-slate-900 focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
            />
            <span class="text-[10px] text-slate-400">Less hours worked counts as half day</span>
          </div>
        </div>

        <!-- Weekend Days Checkbox Group -->
        <div class="pt-3">
          <label class="block text-xs font-semibold text-slate-700 mb-1.5">Standard Weekend / Rest Days</label>
          <div class="flex items-center gap-3 text-xs">
            <label v-for="day in daysOfWeek" :key="day.val" class="flex items-center gap-1.5 text-slate-700 cursor-pointer">
              <input
                type="checkbox"
                :value="day.val"
                v-model="weekendDays"
                class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 h-3.5 w-3.5 cursor-pointer"
              />
              <span>{{ day.name }}</span>
            </label>
          </div>
        </div>
      </div>
    </div>

    <!-- Group 2: Visitor Rules -->
    <div class="bg-white border border-slate-200 rounded-xl p-6 shadow-xs space-y-5">
      <div class="border-b border-slate-100 pb-3">
        <h4 class="text-sm font-bold text-slate-900 flex items-center gap-2">
          <span>👥</span> Visitor Management &amp; Security Policies
        </h4>
        <p class="text-[11px] text-slate-500">Configure receptionist check-in rules and temporary camera face provisioning</p>
      </div>

      <div class="space-y-4">
        <!-- Toggle: Require Photo -->
        <div class="flex items-center justify-between py-2 border-b border-slate-100">
          <div>
            <div class="text-xs font-bold text-slate-800">Mandatory Webcam Face Photo on Check-In</div>
            <div class="text-[11px] text-slate-500">Enforce capturing visitor photo before issuing visitor badge</div>
          </div>
          <button
            type="button"
            role="switch"
            :aria-checked="settings['visitor.require_photo']"
            aria-label="visitor require photo"
            @click="settings['visitor.require_photo'] = !settings['visitor.require_photo']"
            class="relative inline-flex h-5 w-10 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out"
            :class="settings['visitor.require_photo'] ? 'bg-indigo-600' : 'bg-slate-300'"
          >
            <span class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out" :class="settings['visitor.require_photo'] ? 'translate-x-5' : 'translate-x-0'" />
          </button>
        </div>

        <!-- Toggle: Require NDA -->
        <div class="flex items-center justify-between py-2 border-b border-slate-100">
          <div>
            <div class="text-xs font-bold text-slate-800">Mandatory NDA Agreement Sign-off</div>
            <div class="text-[11px] text-slate-500">Require visitor acknowledgment of corporate Non-Disclosure Agreement</div>
          </div>
          <button
            type="button"
            role="switch"
            :aria-checked="settings['visitor.require_nda']"
            aria-label="visitor require nda"
            @click="settings['visitor.require_nda'] = !settings['visitor.require_nda']"
            class="relative inline-flex h-5 w-10 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out"
            :class="settings['visitor.require_nda'] ? 'bg-indigo-600' : 'bg-slate-300'"
          >
            <span class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out" :class="settings['visitor.require_nda'] ? 'translate-x-5' : 'translate-x-0'" />
          </button>
        </div>

        <!-- Toggle: Enroll Face to Camera -->
        <div class="flex items-center justify-between py-2 border-b border-slate-100">
          <div>
            <div class="text-xs font-bold text-slate-800">Auto-Enroll Biometric Face to Edge Cameras</div>
            <div class="text-[11px] text-slate-500">Automatically push temporary face credentials to camera hardware on check-in and revoke on checkout</div>
          </div>
          <button
            type="button"
            role="switch"
            :aria-checked="settings['visitor.enroll_face_to_camera']"
            aria-label="visitor enroll face to camera"
            @click="settings['visitor.enroll_face_to_camera'] = !settings['visitor.enroll_face_to_camera']"
            class="relative inline-flex h-5 w-10 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out"
            :class="settings['visitor.enroll_face_to_camera'] ? 'bg-indigo-600' : 'bg-slate-300'"
          >
            <span class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out" :class="settings['visitor.enroll_face_to_camera'] ? 'translate-x-5' : 'translate-x-0'" />
          </button>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2">
          <div>
            <label for="auto_checkout_time" class="block text-xs font-semibold text-slate-700">Nightly Auto-Checkout Cutoff Time</label>
            <input
              id="auto_checkout_time"
              v-model="settings['visitor.auto_checkout_time']"
              type="text"
              placeholder="23:59:59"
              class="w-full mt-1 bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs font-mono text-slate-900 focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
            />
            <span class="text-[10px] text-slate-400">Visits open past this hour are automatically marked departed</span>
          </div>

          <div>
            <label for="max_visit_duration" class="block text-xs font-semibold text-slate-700">Maximum Allowed Visit Duration (Hours)</label>
            <input
              id="max_visit_duration"
              v-model.number="settings['visitor.max_visit_duration_hours']"
              type="number"
              min="1"
              max="24"
              class="w-full mt-1 bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs font-mono text-slate-900 focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
            />
            <span class="text-[10px] text-slate-400">Triggers an alert on the receptionist dashboard if exceeded</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Group 3: Notifications -->
    <div class="bg-white border border-slate-200 rounded-xl p-6 shadow-xs space-y-5">
      <div class="border-b border-slate-100 pb-3">
        <h4 class="text-sm font-bold text-slate-900 flex items-center gap-2">
          <span>🔔</span> Notifications &amp; System Telemetry
        </h4>
        <p class="text-[11px] text-slate-500">Configure outbound channels and audio feedback for security events</p>
      </div>

      <div class="space-y-4">
        <!-- Toggle: Email -->
        <div class="flex items-center justify-between py-2 border-b border-slate-100">
          <div>
            <div class="text-xs font-bold text-slate-800">Email Notifications</div>
            <div class="text-[11px] text-slate-500">Deliver host notifications, leave approval requests, and security summaries via SMTP</div>
          </div>
          <button
            type="button"
            role="switch"
            :aria-checked="settings['notification.email_enabled']"
            aria-label="notification email enabled"
            @click="settings['notification.email_enabled'] = !settings['notification.email_enabled']"
            class="relative inline-flex h-5 w-10 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out"
            :class="settings['notification.email_enabled'] ? 'bg-indigo-600' : 'bg-slate-300'"
          >
            <span class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out" :class="settings['notification.email_enabled'] ? 'translate-x-5' : 'translate-x-0'" />
          </button>
        </div>

        <!-- Toggle: SMS -->
        <div class="flex items-center justify-between py-2 border-b border-slate-100">
          <div>
            <div class="text-xs font-bold text-slate-800">SMS Gateway Dispatch</div>
            <div class="text-[11px] text-slate-500">Send urgent security alerts and visitor arrival SMS notifications</div>
          </div>
          <button
            type="button"
            role="switch"
            :aria-checked="settings['notification.sms_enabled']"
            aria-label="notification sms enabled"
            @click="settings['notification.sms_enabled'] = !settings['notification.sms_enabled']"
            class="relative inline-flex h-5 w-10 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out"
            :class="settings['notification.sms_enabled'] ? 'bg-indigo-600' : 'bg-slate-300'"
          >
            <span class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out" :class="settings['notification.sms_enabled'] ? 'translate-x-5' : 'translate-x-0'" />
          </button>
        </div>

        <!-- Toggle: Sound on Rejection -->
        <div class="flex items-center justify-between py-2">
          <div>
            <div class="text-xs font-bold text-slate-800">Audible Alarm on Stranger &amp; Rejected Scans</div>
            <div class="text-[11px] text-slate-500">Play synthetic Web Audio tone on live monitor whenever an unauthorized scan occurs</div>
          </div>
          <button
            type="button"
            role="switch"
            :aria-checked="settings['notification.stranger_alert_sound']"
            aria-label="notification stranger alert sound"
            @click="settings['notification.stranger_alert_sound'] = !settings['notification.stranger_alert_sound']"
            class="relative inline-flex h-5 w-10 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out"
            :class="settings['notification.stranger_alert_sound'] ? 'bg-indigo-600' : 'bg-slate-300'"
          >
            <span class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out" :class="settings['notification.stranger_alert_sound'] ? 'translate-x-5' : 'translate-x-0'" />
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import apiClient from '../../api/client';
import notify from '../../utils/notify';

const loading = ref(false);
const saving = ref(false);

const daysOfWeek = [
  { val: 0, name: 'Sun' },
  { val: 1, name: 'Mon' },
  { val: 2, name: 'Tue' },
  { val: 3, name: 'Wed' },
  { val: 4, name: 'Thu' },
  { val: 5, name: 'Fri' },
  { val: 6, name: 'Sat' },
];

const defaultSettings = {
  'attendance.auto_process': true,
  'attendance.late_grace_minutes': 15,
  'attendance.overtime_threshold_minutes': 30,
  'attendance.half_day_threshold_hours': 4.0,
  'attendance.weekend_days': [0, 6],
  'visitor.require_photo': true,
  'visitor.require_nda': false,
  'visitor.enroll_face_to_camera': true,
  'visitor.auto_checkout_time': '23:59:59',
  'visitor.max_visit_duration_hours': 8,
  'notification.email_enabled': false,
  'notification.sms_enabled': false,
  'notification.stranger_alert_sound': true,
};

const settings = ref({ ...defaultSettings });
const originalSettings = ref({ ...defaultSettings });
const weekendDays = ref([0, 6]);

const hasChanges = computed(() => {
  return JSON.stringify(settings.value) !== JSON.stringify(originalSettings.value);
});

const fetchSettings = async () => {
  loading.value = true;
  try {
    const res = await apiClient.get('/settings');
    const data = res.data.data || res.data || {};

    const merged = { ...defaultSettings };
    if (typeof data === 'object') {
      Object.keys(data).forEach((group) => {
        if (Array.isArray(data[group])) {
          data[group].forEach((item) => {
            if (item.key) merged[item.key] = item.value;
          });
        } else if (data[group]?.value !== undefined) {
          merged[group] = data[group].value;
        } else {
          merged[group] = data[group];
        }
      });
    }

    settings.value = merged;
    originalSettings.value = JSON.parse(JSON.stringify(merged));
    if (Array.isArray(merged['attendance.weekend_days'])) {
      weekendDays.value = merged['attendance.weekend_days'];
    }
  } catch (err) {
    console.warn('Settings fetch error (using defaults):', err);
  } finally {
    loading.value = false;
  }
};

const saveAllSettings = async () => {
  saving.value = true;
  settings.value['attendance.weekend_days'] = weekendDays.value;

  try {
    await apiClient.put('/settings/bulk', { settings: settings.value });
    originalSettings.value = JSON.parse(JSON.stringify(settings.value));
    notify.toast('System settings updated successfully.');
  } catch (err) {
    // Handled in client interceptor
  } finally {
    saving.value = false;
  }
};

onMounted(() => {
  fetchSettings();
});
</script>
