<template>
  <div class="space-y-6">
    <!-- Header with Action Buttons -->
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 bg-slate-900/80 border border-slate-800 p-4 rounded-xl backdrop-blur-md">
      <div>
        <h2 class="text-lg font-semibold text-slate-100">Personnel & Face Library</h2>
        <p class="text-xs text-slate-400">Manage whitelisted employees, blacklisted individuals, schedules, and biometric templates</p>
      </div>

      <div class="flex items-center gap-3">
        <button 
          @click="openCreateModal"
          class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold rounded-lg shadow-lg shadow-indigo-600/20 transition-all flex items-center gap-2"
        >
          <span>➕ Enroll New Person</span>
        </button>
      </div>
    </div>

    <!-- Filter & Search Bar -->
    <div class="flex flex-col sm:flex-row items-center gap-3 bg-slate-900/60 border border-slate-800 p-3 rounded-xl">
      <div class="relative flex-1 w-full">
        <input 
          v-model="search" 
          @input="fetchPersonnel"
          type="text" 
          placeholder="Search by name, ID number, phone, or custom ID..."
          class="w-full bg-slate-800/80 border border-slate-700 rounded-lg pl-9 pr-4 py-2 text-xs text-slate-200 placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
        />
        <span class="absolute left-3 top-2.5 text-slate-500 text-xs">🔍</span>
      </div>

      <select v-model="personTypeFilter" @change="fetchPersonnel" class="w-full sm:w-44 bg-slate-800/80 border border-slate-700 text-xs rounded-lg px-3 py-2 text-slate-200">
        <option value="">All Categories</option>
        <option value="0">Whitelist (Allow)</option>
        <option value="1">Blacklist (Block)</option>
      </select>

      <select v-model="validityFilter" @change="fetchPersonnel" class="w-full sm:w-44 bg-slate-800/80 border border-slate-700 text-xs rounded-lg px-3 py-2 text-slate-200">
        <option value="">All Validity</option>
        <option value="0">Permanent</option>
        <option value="1">Temporary Schedule</option>
      </select>
    </div>

    <!-- Personnel Data Table -->
    <div class="bg-slate-900/90 border border-slate-800 rounded-xl overflow-hidden shadow-xl">
      <div class="overflow-x-auto">
        <table class="w-full text-left text-xs text-slate-300">
          <thead class="bg-slate-800/60 text-slate-400 uppercase text-[11px] font-semibold border-b border-slate-800">
            <tr>
              <th class="py-3 px-4">Photo</th>
              <th class="py-3 px-4">Custom ID</th>
              <th class="py-3 px-4">Name</th>
              <th class="py-3 px-4">Category</th>
              <th class="py-3 px-4">ID / Phone</th>
              <th class="py-3 px-4">Schedule</th>
              <th class="py-3 px-4 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-800/60">
            <tr v-if="loading">
              <td colspan="7" class="py-12 text-center text-slate-500">Loading personnel records...</td>
            </tr>
            <tr v-else-if="records.length === 0">
              <td colspan="7" class="py-12 text-center text-slate-500">No personnel records found. Click "Enroll New Person" to add one.</td>
            </tr>
            <tr v-for="person in records" :key="person.id" class="hover:bg-slate-800/30 transition-colors">
              <td class="py-3 px-4">
                <div class="w-10 h-10 rounded-full bg-slate-800 border border-slate-700 overflow-hidden shrink-0">
                  <img v-if="person.photo_path || person.photo_base64" :src="person.photo_path ? `/storage/${person.photo_path}` : person.photo_base64" class="w-full h-full object-cover" />
                  <div v-else class="w-full h-full flex items-center justify-center text-slate-500 text-[10px]">No Pic</div>
                </div>
              </td>
              <td class="py-3 px-4 font-mono text-slate-300 font-medium">#{{ person.customize_id }}</td>
              <td class="py-3 px-4 font-semibold text-slate-100">{{ person.name }}</td>
              <td class="py-3 px-4">
                <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase tracking-wider" :class="person.person_type === 0 ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-rose-500/10 text-rose-400 border border-rose-500/20'">
                  {{ person.person_type === 0 ? 'Whitelist' : 'Blacklist' }}
                </span>
              </td>
              <td class="py-3 px-4 text-slate-400">
                <div>{{ person.id_card || '--' }}</div>
                <div class="text-[11px] text-slate-500">{{ person.tel_num || '--' }}</div>
              </td>
              <td class="py-3 px-4">
                <span v-if="person.temp_valid === 0" class="text-slate-400">Permanent</span>
                <span v-else class="text-amber-400 text-[11px]">
                  Temp ({{ formatDate(person.valid_begin) }} ~ {{ formatDate(person.valid_end) }})
                </span>
              </td>
              <td class="py-3 px-4 text-right space-x-2">
                <button @click="triggerSync(person)" :disabled="syncingId === person.id" class="px-2.5 py-1 text-[11px] bg-slate-800 hover:bg-slate-700 text-indigo-400 border border-slate-700 rounded transition-colors" title="Sync to cameras">
                  {{ syncingId === person.id ? 'Syncing...' : '⚡ Sync' }}
                </button>
                <button @click="openEditModal(person)" class="px-2.5 py-1 text-[11px] bg-slate-800 hover:bg-slate-700 text-slate-300 border border-slate-700 rounded transition-colors">
                  Edit
                </button>
                <button @click="deletePerson(person)" class="px-2.5 py-1 text-[11px] bg-rose-950/40 hover:bg-rose-900/60 text-rose-300 border border-rose-800/40 rounded transition-colors">
                  Delete
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div v-if="pagination.total > pagination.per_page" class="p-3 border-t border-slate-800 flex items-center justify-between text-xs text-slate-400">
        <div>Showing {{ pagination.from }} to {{ pagination.to }} of {{ pagination.total }} records</div>
        <div class="flex items-center gap-1">
          <button @click="changePage(pagination.current_page - 1)" :disabled="pagination.current_page === 1" class="px-3 py-1 bg-slate-800 rounded border border-slate-700 disabled:opacity-50">Prev</button>
          <span class="px-2">{{ pagination.current_page }} / {{ pagination.last_page }}</span>
          <button @click="changePage(pagination.current_page + 1)" :disabled="pagination.current_page === pagination.last_page" class="px-3 py-1 bg-slate-800 rounded border border-slate-700 disabled:opacity-50">Next</button>
        </div>
      </div>
    </div>

    <!-- Create / Edit Modal -->
    <div v-if="modal.show" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm" @click.self="modal.show = false">
      <div class="bg-slate-900 border border-slate-700 rounded-2xl max-w-2xl w-full p-6 space-y-4 max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
          <h3 class="text-base font-semibold text-slate-100">{{ modal.isEdit ? 'Edit Person Record' : 'Enroll New Person & Face' }}</h3>
          <button @click="modal.show = false" class="text-slate-400 hover:text-white text-lg font-bold">&times;</button>
        </div>

        <form @submit.prevent="savePersonnel" class="space-y-4">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <!-- Full Name -->
            <div>
              <label class="block text-xs font-medium text-slate-300 mb-1">Full Name *</label>
              <input v-model="form.name" required type="text" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-xs text-slate-100 focus:ring-1 focus:ring-indigo-500" />
            </div>

            <!-- Person Type (Whitelist / Blacklist) -->
            <div>
              <label class="block text-xs font-medium text-slate-300 mb-1">Category *</label>
              <select v-model="form.person_type" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-xs text-slate-100">
                <option :value="0">Whitelist (Allowed Access)</option>
                <option :value="1">Blacklist (Denied / Alarm)</option>
              </select>
            </div>

            <!-- ID Card -->
            <div>
              <label class="block text-xs font-medium text-slate-300 mb-1">National ID / Badge Number</label>
              <input v-model="form.id_card" type="text" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-xs text-slate-100" />
            </div>

            <!-- Phone Number -->
            <div>
              <label class="block text-xs font-medium text-slate-300 mb-1">Phone Number</label>
              <input v-model="form.tel_num" type="text" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-xs text-slate-100" />
            </div>

            <!-- Gender & Birthday -->
            <div>
              <label class="block text-xs font-medium text-slate-300 mb-1">Gender</label>
              <select v-model="form.gender" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-xs text-slate-100">
                <option :value="0">Male</option>
                <option :value="1">Female</option>
              </select>
            </div>

            <div>
              <label class="block text-xs font-medium text-slate-300 mb-1">Birthday</label>
              <input v-model="form.birthday" type="date" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-xs text-slate-100" />
            </div>
          </div>

          <!-- Schedule & Validity -->
          <div class="bg-slate-800/40 border border-slate-800 rounded-xl p-4 space-y-3">
            <div class="flex items-center justify-between">
              <label class="text-xs font-semibold text-slate-300">Access Schedule & Validity</label>
              <div class="flex items-center gap-4 text-xs">
                <label class="flex items-center gap-1.5 cursor-pointer">
                  <input type="radio" :value="0" v-model="form.temp_valid" class="text-indigo-600" />
                  <span>Permanent</span>
                </label>
                <label class="flex items-center gap-1.5 cursor-pointer">
                  <input type="radio" :value="1" v-model="form.temp_valid" class="text-indigo-600" />
                  <span>Temporary Period</span>
                </label>
              </div>
            </div>

            <div v-if="form.temp_valid === 1" class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2">
              <div>
                <label class="block text-[11px] text-slate-400 mb-1">Valid Start Time</label>
                <input v-model="form.valid_begin" type="datetime-local" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-100" />
              </div>
              <div>
                <label class="block text-[11px] text-slate-400 mb-1">Valid End Time</label>
                <input v-model="form.valid_end" type="datetime-local" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-100" />
              </div>
            </div>
          </div>

          <!-- Face Photo Upload & Preview -->
          <div class="space-y-2">
            <label class="block text-xs font-medium text-slate-300">Biometric Face Image *</label>
            <div class="flex items-center gap-4">
              <div class="w-20 h-20 rounded-xl bg-slate-800 border border-slate-700 overflow-hidden flex items-center justify-center shrink-0">
                <img v-if="previewPhoto" :src="previewPhoto" class="w-full h-full object-cover" />
                <span v-else class="text-2xl text-slate-600">👤</span>
              </div>
              <div class="flex-1">
                <input type="file" accept="image/*" @change="onFileSelected" class="text-xs text-slate-400 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-indigo-600 file:text-white hover:file:bg-indigo-500 cursor-pointer" />
                <p class="text-[11px] text-slate-500 mt-1">Recommended: Clear frontal facial photo (&lt; 2MB). Auto-encoded to Base64 for edge device synchronization.</p>
              </div>
            </div>
          </div>

          <div class="flex justify-end gap-3 pt-3 border-t border-slate-800">
            <button type="button" @click="modal.show = false" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-medium rounded-lg">Cancel</button>
            <button type="submit" :disabled="saving" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold rounded-lg shadow-lg shadow-indigo-600/20 disabled:opacity-50">
              {{ saving ? 'Saving & Enrolling...' : (modal.isEdit ? 'Update Personnel' : 'Enroll Personnel') }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import notify from '../utils/notify';
import axios from 'axios';

const records = ref([]);
const loading = ref(false);
const saving = ref(false);
const syncingId = ref(null);
const search = ref('');
const personTypeFilter = ref('');
const validityFilter = ref('');

const pagination = ref({
  current_page: 1,
  last_page: 1,
  total: 0,
  per_page: 15,
  from: 0,
  to: 0,
});

const modal = ref({
  show: false,
  isEdit: false,
  id: null,
});

const previewPhoto = ref(null);
const selectedFile = ref(null);

const form = ref({
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

async function fetchPersonnel(page = 1) {
  loading.value = true;
  try {
    const params = {
      page,
      search: search.value,
      person_type: personTypeFilter.value,
      temp_valid: validityFilter.value,
    };
    const res = await axios.get('/api/personnel', { params });
    records.value = res.data.data;
    pagination.value = {
      current_page: res.data.current_page,
      last_page: res.data.last_page,
      total: res.data.total,
      per_page: res.data.per_page,
      from: res.data.from,
      to: res.data.to,
    };
  } catch (err) {
    console.error('Failed to fetch personnel:', err);
  } finally {
    loading.value = false;
  }
}

function changePage(page) {
  if (page >= 1 && page <= pagination.value.last_page) {
    fetchPersonnel(page);
  }
}

function formatDate(dateStr) {
  if (!dateStr) return '';
  return new Date(dateStr).toLocaleDateString();
}

function openCreateModal() {
  modal.value = { show: true, isEdit: false, id: null };
  previewPhoto.value = null;
  selectedFile.value = null;
  form.value = {
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

function openEditModal(person) {
  modal.value = { show: true, isEdit: true, id: person.id };
  previewPhoto.value = person.photo_path ? `/storage/${person.photo_path}` : person.photo_base64;
  selectedFile.value = null;
  form.value = {
    name: person.name,
    person_type: person.person_type,
    gender: person.gender,
    id_card: person.id_card || '',
    tel_num: person.tel_num || '',
    address: person.address || '',
    birthday: person.birthday || '',
    temp_valid: person.temp_valid,
    valid_begin: person.valid_begin ? person.valid_begin.substring(0, 16) : '',
    valid_end: person.valid_end ? person.valid_end.substring(0, 16) : '',
    effect_number: person.effect_number || 10000,
  };
}

function onFileSelected(e) {
  const file = e.target.files[0];
  if (file) {
    selectedFile.value = file;
    const reader = new FileReader();
    reader.onload = (event) => {
      previewPhoto.value = event.target.result;
    };
    reader.readAsDataURL(file);
  }
}

async function savePersonnel() {
  saving.value = true;
  try {
    const data = new FormData();
    Object.keys(form.value).forEach(key => {
      if (form.value[key] !== null && form.value[key] !== undefined) {
        data.append(key, form.value[key]);
      }
    });

    if (selectedFile.value) {
      data.append('photo', selectedFile.value);
    } else if (previewPhoto.value && previewPhoto.value.startsWith('data:image')) {
      data.append('photo_base64', previewPhoto.value);
    }

    if (modal.value.isEdit) {
      await axios.post(`/api/personnel/${modal.value.id}`, data, {
        headers: { 'Content-Type': 'multipart/form-data' }
      });
    } else {
      await axios.post('/api/personnel', data, {
        headers: { 'Content-Type': 'multipart/form-data' }
      });
    }

    modal.value.show = false;
    fetchPersonnel(pagination.value.current_page);
    notify.toast(modal.value.isEdit ? 'Personnel updated successfully' : 'Personnel enrolled & queued for sync', 'success');
  } catch (err) {
    notify.error('Save Failed', err.response?.data?.message || 'Failed to save personnel record');
  } finally {
    saving.value = false;
  }
}

async function deletePerson(person) {
  const confirmed = await notify.confirm(
    `Delete ${person.name}?`,
    `This will permanently remove ${person.name} (#${person.customize_id}) from the database and wipe their face credentials from all edge camera units.`,
    'Yes, Delete Person',
    'Cancel',
    true
  );

  if (!confirmed) return;

  try {
    await axios.delete(`/api/personnel/${person.id}`);
    fetchPersonnel(pagination.value.current_page);
    notify.toast(`Removed ${person.name} successfully`, 'success');
  } catch (err) {
    notify.error('Delete Failed', err.response?.data?.message || 'Failed to delete personnel record');
  }
}

async function triggerSync(person) {
  syncingId.value = person.id;
  try {
    await axios.post(`/api/personnel/${person.id}/sync-now`);
    notify.success('Sync Task Queued', `Face credentials and access rules for ${person.name} are queued on the camera-sync Redis worker.`);
  } catch (err) {
    notify.error('Sync Failed', 'Failed to dispatch camera sync job');
  } finally {
    syncingId.value = null;
  }
}

onMounted(() => {
  fetchPersonnel();
});
</script>
