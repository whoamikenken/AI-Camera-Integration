<template>
  <div 
    v-if="show" 
    role="dialog"
    aria-modal="true"
    aria-labelledby="employee-form-title"
    @keydown.escape="closeModal"
    class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4"
  >
    <div class="bg-white rounded-2xl shadow-2xl border border-slate-200 max-w-2xl w-full max-h-[90vh] flex flex-col overflow-hidden animate-in fade-in zoom-in-95 duration-200">
      <!-- Modal Header -->
      <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
        <div>
          <h3 id="employee-form-title" class="text-base font-bold text-slate-900">
            {{ isEditing ? 'Edit Employee Profile' : 'Enroll New Employee' }}
          </h3>
          <p class="text-xs text-slate-500">
            {{ isEditing ? 'Update employee personnel data and camera face configuration.' : 'Add a new workforce member and provision biometric face access.' }}
          </p>
        </div>
        <button
          type="button"
          @click="closeModal"
          aria-label="Close employee form modal"
          class="text-slate-400 hover:text-slate-600 p-1.5 rounded-lg hover:bg-slate-100 transition-colors cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
        >
          ✕
        </button>
      </div>

      <!-- Semantic Form enclosing Body & Footer -->
      <form @submit.prevent="handleSubmit" class="flex flex-col flex-1 overflow-hidden">
        <!-- Modal Body (Scrollable) -->
        <div class="px-6 py-5 overflow-y-auto flex-1 space-y-6">
          <!-- Photo & Biometric Section -->
          <div class="flex flex-col sm:flex-row items-center gap-6 p-4 rounded-xl bg-indigo-50/50 border border-indigo-100/80">
            <div class="relative group">
              <div class="w-24 h-24 rounded-2xl bg-white border-2 border-indigo-200 overflow-hidden shadow-xs flex items-center justify-center">
                <img
                  v-if="form.photo_base64 || form.avatar"
                  :src="form.photo_base64 || form.avatar"
                  alt="Biometric face photo"
                  class="w-full h-full object-cover"
                />
                <span v-else class="text-3xl text-slate-300" aria-hidden="true">👤</span>
              </div>
              <button
                v-if="form.photo_base64 || form.avatar"
                type="button"
                @click="clearPhoto"
                aria-label="Remove photo"
                class="absolute -top-2 -right-2 bg-rose-500 text-white rounded-full w-5 h-5 flex items-center justify-center text-xs shadow-xs hover:bg-rose-600 cursor-pointer focus:outline-none focus:ring-2 focus:ring-rose-500"
                title="Remove Photo"
              >
                ✕
              </button>
            </div>

            <div class="flex-1 space-y-2 text-center sm:text-left">
              <div class="text-xs font-bold text-indigo-950">Biometric Face Enrollment</div>
              <p class="text-[11px] text-indigo-700/80">
                Upload a clear frontal face image or capture via webcam for automatic provisioning to edge camera fleet.
              </p>
              <div class="flex flex-wrap items-center justify-center sm:justify-start gap-2 pt-1">
                <label for="emp_photo_upload" class="px-3 py-1.5 bg-white border border-indigo-200 hover:border-indigo-400 text-indigo-700 font-semibold text-xs rounded-xl shadow-xs cursor-pointer transition-colors inline-flex items-center gap-1.5">
                  <span>📁 Upload Photo</span>
                  <input id="emp_photo_upload" type="file" accept="image/*" aria-label="Upload biometric photo file" class="hidden" @change="handleFileUpload" />
                </label>

                <button
                  v-if="hasWebcamSupport"
                  type="button"
                  @click="openWebcam"
                  aria-label="Capture photo from webcam"
                  class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold text-xs rounded-xl shadow-xs transition-colors cursor-pointer inline-flex items-center gap-1.5 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                >
                  <span>📷 Capture Webcam</span>
                </button>
              </div>
            </div>
          </div>

          <!-- Webcam Live Capture Interface -->
          <div v-if="webcamActive" class="p-4 rounded-xl bg-slate-50 border border-slate-200 text-slate-900 flex flex-col items-center gap-3">
            <div class="relative w-64 h-48 bg-slate-950 rounded-xl overflow-hidden border border-slate-800 shadow-md">
              <video ref="videoRef" autoplay playsinline class="w-full h-full object-cover"></video>
              <div class="absolute inset-0 border-2 border-indigo-400/60 rounded-full w-36 h-44 m-auto pointer-events-none"></div>
            </div>
            <div class="flex gap-2">
              <button
                type="button"
                @click="captureFrame"
                aria-label="Capture snapshot from live webcam feed"
                class="px-4 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold rounded-lg cursor-pointer shadow-xs transition-colors focus:outline-none focus:ring-2 focus:ring-emerald-500"
              >
                📸 Capture Snapshot
              </button>
              <button
                type="button"
                @click="stopWebcam"
                aria-label="Cancel webcam capture"
                class="px-3.5 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 text-xs font-semibold rounded-lg cursor-pointer transition-colors focus:outline-none focus:ring-2 focus:ring-slate-500"
              >
                Cancel
              </button>
            </div>
          </div>

          <!-- Basic Identity Fields -->
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label for="emp_code" class="block text-xs font-semibold text-slate-700 mb-1">
                Employee Code <span class="text-rose-500">*</span>
              </label>
              <div class="flex gap-1.5">
                <input
                  id="emp_code"
                  v-model="form.employee_code"
                  type="text"
                  placeholder="e.g. EMP-1001"
                  class="flex-1 px-3 py-2 text-xs border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
                  required
                  aria-required="true"
                />
                <button
                  type="button"
                  @click="generateCode"
                  aria-label="Auto-generate employee code"
                  class="px-2.5 py-2 text-[11px] font-semibold bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl transition-colors cursor-pointer"
                  title="Auto Generate"
                >
                  Auto
                </button>
              </div>
            </div>

            <div>
              <label for="emp_status" class="block text-xs font-semibold text-slate-700 mb-1">
                Employment Status <span class="text-rose-500">*</span>
              </label>
              <select
                id="emp_status"
                v-model="form.employment_status"
                required
                aria-required="true"
                class="w-full px-3 py-2 text-xs border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 bg-white"
              >
                <option value="active">Active (Whitelisted)</option>
                <option value="probation">Probation (Whitelisted)</option>
                <option value="suspended">Suspended (Blacklisted)</option>
                <option value="terminated">Terminated (Blacklisted)</option>
                <option value="resigned">Resigned (Blacklisted)</option>
              </select>
            </div>

            <div>
              <label for="emp_first_name" class="block text-xs font-semibold text-slate-700 mb-1">
                First Name <span class="text-rose-500">*</span>
              </label>
              <input
                id="emp_first_name"
                v-model="form.first_name"
                type="text"
                placeholder="e.g. Sarah"
                class="w-full px-3 py-2 text-xs border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
                required
                aria-required="true"
              />
            </div>

            <div>
              <label for="emp_last_name" class="block text-xs font-semibold text-slate-700 mb-1">
                Last Name
              </label>
              <input
                id="emp_last_name"
                v-model="form.last_name"
                type="text"
                placeholder="e.g. Connor"
                class="w-full px-3 py-2 text-xs border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
              />
            </div>

            <div>
              <label for="emp_work_email" class="block text-xs font-semibold text-slate-700 mb-1">Work Email</label>
              <input
                id="emp_work_email"
                v-model="form.work_email"
                type="email"
                placeholder="sarah.connor@example.com"
                class="w-full px-3 py-2 text-xs border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
              />
            </div>

            <div>
              <label for="emp_phone" class="block text-xs font-semibold text-slate-700 mb-1">Phone Number</label>
              <input
                id="emp_phone"
                v-model="form.phone"
                type="tel"
                placeholder="+1-555-0199"
                class="w-full px-3 py-2 text-xs border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
              />
            </div>
          </div>

          <!-- Organizational Hierarchy -->
          <div class="border-t border-slate-100 pt-4">
            <div class="text-xs font-bold text-slate-800 mb-3">Organization & Placement</div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label for="emp_department" class="block text-xs font-semibold text-slate-700 mb-1">Department</label>
                <select
                  id="emp_department"
                  v-model="form.department_id"
                  class="w-full px-3 py-2 text-xs border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 bg-white"
                >
                  <option value="">-- Unassigned --</option>
                  <option v-for="dept in store.departments" :key="dept.id" :value="dept.id">
                    {{ dept.name }} ({{ dept.code }})
                  </option>
                </select>
              </div>

              <div>
                <label for="emp_designation" class="block text-xs font-semibold text-slate-700 mb-1">Designation / Role</label>
                <select
                  id="emp_designation"
                  v-model="form.designation_id"
                  class="w-full px-3 py-2 text-xs border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 bg-white"
                >
                  <option value="">-- Unassigned --</option>
                  <option v-for="desig in store.designations" :key="desig.id" :value="desig.id">
                    {{ desig.name }} (Level {{ desig.level }})
                  </option>
                </select>
              </div>

              <div>
                <label for="emp_location" class="block text-xs font-semibold text-slate-700 mb-1">Office Site / Location</label>
                <select
                  id="emp_location"
                  v-model="form.location_id"
                  class="w-full px-3 py-2 text-xs border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 bg-white"
                >
                  <option value="">-- Unassigned --</option>
                  <option v-for="loc in store.locations" :key="loc.id" :value="loc.id">
                    {{ loc.name }} ({{ loc.code }})
                  </option>
                </select>
              </div>

              <div>
                <label for="emp_shift" class="block text-xs font-semibold text-slate-700 mb-1">Default Work Shift</label>
                <select
                  id="emp_shift"
                  v-model="form.shift_id"
                  class="w-full px-3 py-2 text-xs border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 bg-white"
                >
                  <option value="">-- Organization Default --</option>
                  <option v-for="shift in store.shifts" :key="shift.id" :value="shift.id">
                    {{ shift.name }} ({{ shift.shift_start }} - {{ shift.shift_end }})
                  </option>
                </select>
              </div>

              <div>
                <label for="emp_employment_type" class="block text-xs font-semibold text-slate-700 mb-1">Employment Type</label>
                <select
                  id="emp_employment_type"
                  v-model="form.employment_type"
                  class="w-full px-3 py-2 text-xs border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 bg-white"
                >
                  <option value="full-time">Full-Time</option>
                  <option value="part-time">Part-Time</option>
                  <option value="contract">Contract</option>
                  <option value="intern">Intern</option>
                  <option value="temporary">Temporary</option>
                </select>
              </div>

              <div>
                <label for="emp_date_of_joining" class="block text-xs font-semibold text-slate-700 mb-1">Date of Joining</label>
                <input
                  id="emp_date_of_joining"
                  v-model="form.date_of_joining"
                  type="date"
                  class="w-full px-3 py-2 text-xs border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
                />
              </div>
            </div>
          </div>

          <!-- Emergency Contact Section -->
          <div class="border-t border-slate-100 pt-4">
            <div class="text-xs font-bold text-slate-800 mb-3">Emergency Contact</div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label for="emp_emergency_name" class="block text-xs font-semibold text-slate-700 mb-1">Contact Name</label>
                <input
                  id="emp_emergency_name"
                  v-model="form.emergency_contact_name"
                  type="text"
                  placeholder="e.g. John Connor"
                  class="w-full px-3 py-2 text-xs border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
                />
              </div>
              <div>
                <label for="emp_emergency_phone" class="block text-xs font-semibold text-slate-700 mb-1">Contact Phone</label>
                <input
                  id="emp_emergency_phone"
                  v-model="form.emergency_contact_phone"
                  type="tel"
                  placeholder="e.g. +1-555-0100"
                  class="w-full px-3 py-2 text-xs border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
                />
              </div>
            </div>
          </div>
        </div>

        <!-- Modal Footer -->
        <div class="px-6 py-4 border-t border-slate-100 flex items-center justify-end gap-3 bg-slate-50/50">
          <button
            type="button"
            @click="closeModal"
            class="px-4 py-2 text-xs font-semibold text-slate-600 hover:text-slate-800 hover:bg-slate-100 rounded-xl transition-colors cursor-pointer"
          >
            Cancel
          </button>
          <button
            type="submit"
            :disabled="store.saving"
            class="px-5 py-2 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 rounded-xl shadow-xs transition-colors cursor-pointer flex items-center gap-1.5 focus:outline-none focus:ring-2 focus:ring-indigo-500"
          >
            <span v-if="store.saving" class="animate-spin inline-block w-3.5 h-3.5 border-2 border-white border-t-transparent rounded-full" aria-hidden="true"></span>
            <span>{{ store.saving ? 'Saving...' : (isEditing ? 'Save Changes' : 'Enroll Employee') }}</span>
          </button>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted, onUnmounted } from 'vue';
import { useEmployeeStore } from '../../stores/employeeStore';

const props = defineProps({
  show: Boolean,
  employee: Object,
});

const emit = defineEmits(['close', 'saved']);
const store = useEmployeeStore();

const isEditing = computed(() => !!props.employee?.id);
const hasWebcamSupport = ref(!!navigator.mediaDevices?.getUserMedia);
const webcamActive = ref(false);
const videoRef = ref(null);
let mediaStream = null;

const form = reactive({
  employee_code: '',
  first_name: '',
  last_name: '',
  work_email: '',
  personal_email: '',
  phone: '',
  avatar: '',
  photo_base64: '',
  employment_type: 'full-time',
  employment_status: 'active',
  department_id: '',
  designation_id: '',
  location_id: '',
  shift_id: '',
  reporting_manager_id: '',
  date_of_joining: new Date().toISOString().slice(0, 10),
  emergency_contact_name: '',
  emergency_contact_phone: '',
});

watch(() => props.employee, (val) => {
  if (val) {
    Object.assign(form, {
      employee_code: val.employee_code || '',
      first_name: val.first_name || '',
      last_name: val.last_name || '',
      work_email: val.work_email || '',
      personal_email: val.personal_email || '',
      phone: val.phone || '',
      avatar: val.avatar || '',
      photo_base64: val.personnel?.photo_base64 || '',
      employment_type: val.employment_type || 'full-time',
      employment_status: val.employment_status || 'active',
      department_id: val.department_id || '',
      designation_id: val.designation_id || '',
      location_id: val.location_id || '',
      shift_id: val.shift_id || '',
      reporting_manager_id: val.reporting_manager_id || '',
      date_of_joining: val.date_of_joining ? val.date_of_joining.slice(0, 10) : '',
      emergency_contact_name: val.emergency_contact_name || '',
      emergency_contact_phone: val.emergency_contact_phone || '',
    });
  } else {
    resetForm();
  }
}, { immediate: true });

function resetForm() {
  Object.assign(form, {
    employee_code: 'EMP-' + Math.floor(1000 + Math.random() * 9000),
    first_name: '',
    last_name: '',
    work_email: '',
    personal_email: '',
    phone: '',
    avatar: '',
    photo_base64: '',
    employment_type: 'full-time',
    employment_status: 'active',
    department_id: '',
    designation_id: '',
    location_id: '',
    shift_id: '',
    reporting_manager_id: '',
    date_of_joining: new Date().toISOString().slice(0, 10),
    emergency_contact_name: '',
    emergency_contact_phone: '',
  });
}

function generateCode() {
  form.employee_code = 'EMP-' + Math.floor(1000 + Math.random() * 9000);
}

function handleFileUpload(e) {
  const file = e.target.files?.[0];
  if (!file) return;

  const reader = new FileReader();
  reader.onload = (event) => {
    form.photo_base64 = event.target?.result;
    form.avatar = event.target?.result;
  };
  reader.readAsDataURL(file);
}

function clearPhoto() {
  form.photo_base64 = '';
  form.avatar = '';
}

async function openWebcam() {
  try {
    mediaStream = await navigator.mediaDevices.getUserMedia({ video: { width: 640, height: 480 } });
    webcamActive.value = true;
    setTimeout(() => {
      if (videoRef.value) {
        videoRef.value.srcObject = mediaStream;
      }
    }, 100);
  } catch (err) {
    console.warn('Webcam access failed:', err);
    alert('Could not access webcam. Please check browser permissions.');
  }
}

function captureFrame() {
  if (!videoRef.value) return;
  const canvas = document.createElement('canvas');
  canvas.width = 480;
  canvas.height = 480;
  const ctx = canvas.getContext('2d');
  ctx.drawImage(videoRef.value, 80, 0, 480, 480, 0, 0, 480, 480);
  const dataUrl = canvas.toDataURL('image/jpeg', 0.9);
  form.photo_base64 = dataUrl;
  form.avatar = dataUrl;
  stopWebcam();
}

function stopWebcam() {
  if (mediaStream) {
    mediaStream.getTracks().forEach(track => track.stop());
    mediaStream = null;
  }
  webcamActive.value = false;
}

async function handleSubmit() {
  if (!form.employee_code || !form.first_name) {
    alert('Please enter Employee Code and First Name.');
    return;
  }

  const payload = { ...form };
  if (isEditing.value) {
    await store.updateEmployee(props.employee.id, payload);
  } else {
    await store.createEmployee(payload);
  }

  emit('saved');
  closeModal();
}

function closeModal() {
  stopWebcam();
  emit('close');
}

function handleGlobalKeydown(e) {
  if (e.key === 'Escape' && props.show) {
    closeModal();
  }
}

onMounted(() => {
  window.addEventListener('keydown', handleGlobalKeydown);
});

onUnmounted(() => {
  stopWebcam();
  window.removeEventListener('keydown', handleGlobalKeydown);
});
</script>
