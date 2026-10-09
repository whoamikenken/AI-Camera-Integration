<template>
  <div class="space-y-6">
    <!-- Top Header Bar -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 bg-white border border-slate-200/80 p-4 rounded-xl shadow-xs">
      <div>
        <h3 class="text-base font-bold text-slate-900 flex items-center gap-2">
          <span aria-hidden="true">🛡️</span>
          <span>Access Control Groups &amp; Security Zones</span>
        </h3>
        <p class="text-xs text-slate-500 mt-0.5">
          Configure security perimeters, assign edge cameras, and scope biometric dispatching to departments and personnel
        </p>
      </div>

      <div class="flex items-center gap-2">
        <button
          @click="openModal(null)"
          class="px-3.5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-lg shadow-sm transition-all flex items-center gap-1.5 cursor-pointer"
          aria-label="Create new access group"
        >
          <span aria-hidden="true">➕</span>
          <span>Create Access Group</span>
        </button>
      </div>
    </div>

    <!-- Filters and Search Toolbar -->
    <div class="bg-white border border-slate-200/80 p-3 rounded-xl shadow-xs flex flex-col sm:flex-row gap-3 items-center justify-between">
      <div class="flex flex-1 w-full sm:w-auto items-center gap-2">
        <div class="relative flex-1 max-w-sm">
          <input
            v-model="searchQuery"
            @input="filterGroups"
            type="text"
            placeholder="Search by zone name or code..."
            class="w-full bg-slate-50 border border-slate-200 rounded-lg pl-8 pr-3 py-1.5 text-xs text-slate-900 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
            aria-label="Search access groups by name or code"
          />
          <span class="absolute left-2.5 top-2 text-slate-400 text-xs" aria-hidden="true">🔍</span>
        </div>

        <select
          v-model="statusFilter"
          @change="filterGroups"
          class="bg-slate-50 border border-slate-200 rounded-lg px-2.5 py-1.5 text-xs text-slate-700 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 cursor-pointer"
          aria-label="Filter access groups by status"
        >
          <option value="all">All Statuses</option>
          <option value="active">Active Only</option>
          <option value="inactive">Inactive Only</option>
        </select>
      </div>

      <div class="flex items-center gap-2 w-full sm:w-auto justify-end">
        <button
          @click="fetchGroups"
          :disabled="loading"
          class="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-medium rounded-lg transition-colors flex items-center gap-1.5 cursor-pointer disabled:opacity-50"
          aria-label="Refresh access groups list"
        >
          <span :class="{ 'animate-spin': loading }" aria-hidden="true">🔄</span>
          <span>Refresh</span>
        </button>
      </div>
    </div>

    <!-- Access Groups Table -->
    <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-xs">
      <div class="overflow-x-auto">
        <table class="w-full text-left text-xs text-slate-700">
          <thead class="bg-slate-50 text-slate-500 uppercase text-[11px] font-semibold border-b border-slate-200">
            <tr>
              <th scope="col" class="py-3 px-4">Zone Code</th>
              <th scope="col" class="py-3 px-4">Group Name</th>
              <th scope="col" class="py-3 px-4">Description</th>
              <th scope="col" class="py-3 px-4 text-center">Cameras</th>
              <th scope="col" class="py-3 px-4 text-center">Departments</th>
              <th scope="col" class="py-3 px-4 text-center">Personnel</th>
              <th scope="col" class="py-3 px-4 text-center">Status</th>
              <th scope="col" class="py-3 px-4 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <!-- 5-Row Skeleton Loader -->
            <template v-if="loading">
              <tr v-for="n in 5" :key="'skeleton-' + n" class="animate-pulse motion-reduce:animate-none">
                <td class="py-3.5 px-4"><div class="h-4 bg-slate-200 rounded w-20"></div></td>
                <td class="py-3.5 px-4"><div class="h-4 bg-slate-200 rounded w-36"></div></td>
                <td class="py-3.5 px-4"><div class="h-4 bg-slate-200 rounded w-48"></div></td>
                <td class="py-3.5 px-4 text-center"><div class="h-4 bg-slate-200 rounded w-8 mx-auto"></div></td>
                <td class="py-3.5 px-4 text-center"><div class="h-4 bg-slate-200 rounded w-8 mx-auto"></div></td>
                <td class="py-3.5 px-4 text-center"><div class="h-4 bg-slate-200 rounded w-8 mx-auto"></div></td>
                <td class="py-3.5 px-4 text-center"><div class="h-4 bg-slate-200 rounded w-14 mx-auto"></div></td>
                <td class="py-3.5 px-4 text-right"><div class="h-4 bg-slate-200 rounded w-28 ml-auto"></div></td>
              </tr>
            </template>

            <!-- Empty State -->
            <tr v-else-if="filteredGroups.length === 0">
              <td colspan="8" class="py-12 text-center text-slate-500">
                <div class="flex flex-col items-center justify-center gap-2">
                  <span class="text-2xl" aria-hidden="true">🛡️</span>
                  <span class="font-medium text-slate-700">No access groups found</span>
                  <span class="text-slate-400 text-xs">Create your first access control zone to start scoping camera deployments</span>
                </div>
              </td>
            </tr>

            <!-- Data Rows -->
            <tr v-for="group in filteredGroups" :key="group.id" class="hover:bg-slate-50 transition-colors">
              <td class="py-3 px-4 font-mono font-semibold text-slate-900">
                <span class="px-2 py-0.5 rounded bg-slate-100 text-slate-800 border border-slate-200">
                  {{ group.code }}
                </span>
              </td>
              <td class="py-3 px-4 font-bold text-slate-900">{{ group.name }}</td>
              <td class="py-3 px-4 text-slate-600 max-w-xs truncate">{{ group.description || '--' }}</td>
              <td class="py-3 px-4 text-center">
                <span class="px-2 py-0.5 rounded-full text-[11px] font-mono font-semibold bg-blue-50 text-blue-700 border border-blue-200">
                  {{ group.devices_count ?? group.devices?.length ?? 0 }}
                </span>
              </td>
              <td class="py-3 px-4 text-center">
                <span class="px-2 py-0.5 rounded-full text-[11px] font-mono font-semibold bg-purple-50 text-purple-700 border border-purple-200">
                  {{ group.departments_count ?? group.departments?.length ?? 0 }}
                </span>
              </td>
              <td class="py-3 px-4 text-center">
                <span class="px-2 py-0.5 rounded-full text-[11px] font-mono font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                  {{ group.personnel_count ?? group.personnel?.length ?? 0 }}
                </span>
              </td>
              <td class="py-3 px-4 text-center">
                <span
                  class="px-2 py-0.5 rounded-full text-[10px] font-semibold border"
                  :class="group.is_active ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-slate-100 text-slate-500 border-slate-200'"
                >
                  {{ group.is_active ? 'Active' : 'Inactive' }}
                </span>
              </td>
              <td class="py-3 px-4 text-right space-x-1.5 whitespace-nowrap">
                <button
                  @click="syncZone(group)"
                  :disabled="syncingId === group.id"
                  class="px-2.5 py-1 text-[11px] bg-indigo-50 hover:bg-indigo-100 text-indigo-700 font-semibold border border-indigo-200 rounded transition-colors shadow-xs cursor-pointer disabled:opacity-50"
                  aria-label="Synchronize personnel roster to group cameras"
                >
                  <span v-if="syncingId === group.id">Syncing...</span>
                  <span v-else>🔄 Sync Zone</span>
                </button>
                <button
                  @click="openModal(group)"
                  class="px-2.5 py-1 text-[11px] bg-white hover:bg-slate-50 text-slate-700 font-medium border border-slate-200 rounded transition-colors shadow-xs cursor-pointer"
                  aria-label="Edit access group"
                >
                  Edit
                </button>
                <button
                  @click="deleteGroup(group)"
                  class="px-2.5 py-1 text-[11px] bg-rose-50 hover:bg-rose-100 text-rose-700 font-semibold border border-rose-200 rounded transition-colors shadow-xs cursor-pointer"
                  aria-label="Delete access group"
                >
                  Delete
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- CREATE / EDIT MODAL -->
    <div
      v-if="showModal"
      role="dialog"
      aria-modal="true"
      aria-labelledby="group-modal-title"
      tabindex="-1"
      class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4 overflow-y-auto"
      @keydown.escape="showModal = false"
    >
      <div class="bg-white rounded-2xl border border-slate-200 max-w-xl w-full p-6 shadow-2xl space-y-4 my-8">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100">
          <h3 id="group-modal-title" class="text-base font-bold text-slate-900 flex items-center gap-2">
            <span aria-hidden="true">🛡️</span>
            <span>{{ editingGroupId ? 'Edit Access Control Group' : 'Create Access Control Group' }}</span>
          </h3>
          <button
            @click="showModal = false"
            class="text-slate-400 hover:text-slate-600 text-lg p-1 cursor-pointer"
            aria-label="Close dialog"
          >
            ✕
          </button>
        </div>

        <div class="space-y-4">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label for="group-name" class="block text-xs font-semibold text-slate-700">Group / Zone Name *</label>
              <input
                id="group-name"
                v-model="form.name"
                type="text"
                placeholder="e.g. Data Center Secure Zone"
                class="w-full mt-1 bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
              />
            </div>

            <div>
              <label for="group-code" class="block text-xs font-semibold text-slate-700">Zone Code *</label>
              <input
                id="group-code"
                v-model="form.code"
                type="text"
                placeholder="e.g. ZONE-DC-01"
                class="w-full mt-1 bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
              />
            </div>
          </div>

          <div>
            <label for="group-description" class="block text-xs font-semibold text-slate-700">Description</label>
            <textarea
              id="group-description"
              v-model="form.description"
              rows="2"
              placeholder="Physical perimeter boundaries, security requirements..."
              class="w-full mt-1 bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
            ></textarea>
          </div>

          <div class="flex items-center gap-2">
            <input
              id="group-is-active"
              v-model="form.is_active"
              type="checkbox"
              class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 h-4 w-4"
            />
            <label for="group-is-active" class="text-xs font-semibold text-slate-700 cursor-pointer">
              Active Zone (dispatches facial records to linked hardware)
            </label>
          </div>

          <!-- Section: Camera Hardware Assignment -->
          <div class="space-y-1.5 pt-2 border-t border-slate-100">
            <label class="block text-xs font-semibold text-slate-700 flex items-center justify-between">
              <span>Attached Hardware Cameras ({{ form.device_ids.length }} selected)</span>
              <span class="text-[11px] text-slate-400 font-normal">Check cameras in this zone</span>
            </label>
            <div class="max-h-32 overflow-y-auto border border-slate-200 rounded-lg p-2 space-y-1 bg-slate-50">
              <div v-if="availableDevices.length === 0" class="text-xs text-slate-400 p-2 text-center">
                No active devices available
              </div>
              <label
                v-for="device in availableDevices"
                :key="device.id"
                class="flex items-center gap-2 p-1 rounded hover:bg-white text-xs cursor-pointer"
              >
                <input
                  type="checkbox"
                  :value="device.id"
                  v-model="form.device_ids"
                  class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 h-3.5 w-3.5"
                />
                <span class="font-medium text-slate-800">{{ device.name }}</span>
                <span class="font-mono text-[10px] text-slate-500">({{ device.device_id }})</span>
              </label>
            </div>
          </div>

          <!-- Section: Department Assignment -->
          <div class="space-y-1.5 pt-2 border-t border-slate-100">
            <label class="block text-xs font-semibold text-slate-700 flex items-center justify-between">
              <span>Authorized Departments ({{ form.department_ids.length }} selected)</span>
              <span class="text-[11px] text-slate-400 font-normal">All members auto-granted access</span>
            </label>
            <div class="max-h-32 overflow-y-auto border border-slate-200 rounded-lg p-2 space-y-1 bg-slate-50">
              <div v-if="availableDepartments.length === 0" class="text-xs text-slate-400 p-2 text-center">
                No departments available
              </div>
              <label
                v-for="dept in availableDepartments"
                :key="dept.id"
                class="flex items-center gap-2 p-1 rounded hover:bg-white text-xs cursor-pointer"
              >
                <input
                  type="checkbox"
                  :value="dept.id"
                  v-model="form.department_ids"
                  class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 h-3.5 w-3.5"
                />
                <span class="font-medium text-slate-800">{{ dept.name }}</span>
                <span class="font-mono text-[10px] text-slate-500">({{ dept.code }})</span>
              </label>
            </div>
          </div>

          <!-- Section: Direct Personnel Assignment -->
          <div class="space-y-1.5 pt-2 border-t border-slate-100">
            <label class="block text-xs font-semibold text-slate-700 flex items-center justify-between">
              <span>Direct Individual Personnel ({{ form.personnel_ids.length }} selected)</span>
              <span class="text-[11px] text-slate-400 font-normal">Specific personnel grants</span>
            </label>
            <div class="max-h-32 overflow-y-auto border border-slate-200 rounded-lg p-2 space-y-1 bg-slate-50">
              <div v-if="availablePersonnel.length === 0" class="text-xs text-slate-400 p-2 text-center">
                No personnel records available
              </div>
              <label
                v-for="person in availablePersonnel"
                :key="person.id"
                class="flex items-center gap-2 p-1 rounded hover:bg-white text-xs cursor-pointer"
              >
                <input
                  type="checkbox"
                  :value="person.id"
                  v-model="form.personnel_ids"
                  class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 h-3.5 w-3.5"
                />
                <span class="font-medium text-slate-800">{{ person.name }}</span>
                <span class="font-mono text-[10px] text-slate-500">(#{{ person.customize_id }})</span>
              </label>
            </div>
          </div>
        </div>

        <div class="pt-4 border-t border-slate-100 flex items-center justify-end gap-2">
          <button
            @click="showModal = false"
            class="px-3.5 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-medium rounded-lg cursor-pointer"
          >
            Cancel
          </button>
          <button
            @click="saveGroup"
            :disabled="saving"
            class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-lg shadow-sm transition-all cursor-pointer disabled:opacity-50"
          >
            {{ saving ? 'Saving...' : (editingGroupId ? 'Update Group' : 'Create Group') }}
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

// State
const groups = ref([]);
const availableDevices = ref([]);
const availableDepartments = ref([]);
const availablePersonnel = ref([]);

const loading = ref(false);
const saving = ref(false);
const syncingId = ref(null);

const searchQuery = ref('');
const statusFilter = ref('all');

// Modal State
const showModal = ref(false);
const editingGroupId = ref(null);
const form = ref({
  name: '',
  code: '',
  description: '',
  is_active: true,
  device_ids: [],
  department_ids: [],
  personnel_ids: [],
});

// Computed filtering
const filteredGroups = computed(() => {
  return groups.value.filter((g) => {
    const matchesSearch =
      !searchQuery.value ||
      g.name.toLowerCase().includes(searchQuery.value.toLowerCase()) ||
      g.code.toLowerCase().includes(searchQuery.value.toLowerCase()) ||
      (g.description && g.description.toLowerCase().includes(searchQuery.value.toLowerCase()));

    const matchesStatus =
      statusFilter.value === 'all' ||
      (statusFilter.value === 'active' && g.is_active) ||
      (statusFilter.value === 'inactive' && !g.is_active);

    return matchesSearch && matchesStatus;
  });
});

// Fetch functions
const fetchGroups = async () => {
  loading.value = true;
  try {
    const res = await apiClient.get('/access-groups?all=true');
    groups.value = res.data.data || res.data || [];
  } catch (e) {
    console.error('Failed to load access groups', e);
  } finally {
    loading.value = false;
  }
};

const fetchOptions = async () => {
  try {
    const [devRes, deptRes, perRes] = await Promise.allSettled([
      apiClient.get('/devices?all=true'),
      apiClient.get('/departments?all=true'),
      apiClient.get('/personnel?all=true'),
    ]);

    if (devRes.status === 'fulfilled') {
      availableDevices.value = devRes.value.data.data || devRes.value.data || [];
    }
    if (deptRes.status === 'fulfilled') {
      availableDepartments.value = deptRes.value.data.data || deptRes.value.data || [];
    }
    if (perRes.status === 'fulfilled') {
      availablePersonnel.value = perRes.value.data.data || perRes.value.data || [];
    }
  } catch (e) {
    console.error('Failed to load related options', e);
  }
};

const filterGroups = () => {
  // Handled by reactive computed filteredGroups
};

const openModal = async (group) => {
  await fetchOptions();
  if (group) {
    editingGroupId.value = group.id;
    try {
      const res = await apiClient.get(`/access-groups/${group.id}`);
      const data = res.data.data || res.data;
      form.value = {
        name: data.name,
        code: data.code,
        description: data.description || '',
        is_active: Boolean(data.is_active),
        device_ids: (data.devices || []).map((d) => d.id),
        department_ids: (data.departments || []).map((d) => d.id),
        personnel_ids: (data.personnel || []).map((p) => p.id),
      };
    } catch {
      form.value = {
        name: group.name,
        code: group.code,
        description: group.description || '',
        is_active: Boolean(group.is_active),
        device_ids: (group.devices || []).map((d) => d.id),
        department_ids: (group.departments || []).map((d) => d.id),
        personnel_ids: (group.personnel || []).map((p) => p.id),
      };
    }
  } else {
    editingGroupId.value = null;
    form.value = {
      name: '',
      code: '',
      description: '',
      is_active: true,
      device_ids: [],
      department_ids: [],
      personnel_ids: [],
    };
  }
  showModal.value = true;
};

const saveGroup = async () => {
  if (!form.value.name.trim() || !form.value.code.trim()) {
    notify.warning('Validation Warning', 'Both Group Name and Zone Code are required fields.');
    return;
  }

  saving.value = true;
  try {
    if (editingGroupId.value) {
      await apiClient.put(`/access-groups/${editingGroupId.value}`, form.value);
      notify.success('Success', 'Access control group updated successfully.');
    } else {
      await apiClient.post('/access-groups', form.value);
      notify.success('Success', 'Access control group created successfully.');
    }
    showModal.value = false;
    await fetchGroups();
  } catch (e) {
    console.error('Failed to save access group', e);
  } finally {
    saving.value = false;
  }
};

const deleteGroup = async (group) => {
  const confirmed = await notify.confirm(
    'Delete Access Group',
    `Are you sure you want to delete zone "${group.name}" (${group.code})? This will unbind all hardware turnstiles and personnel.`,
    'Yes, Delete'
  );

  if (!confirmed) return;

  try {
    await apiClient.delete(`/access-groups/${group.id}`);
    notify.success('Deleted', `Access group "${group.name}" was removed.`);
    await fetchGroups();
  } catch (e) {
    console.error('Failed to delete access group', e);
  }
};

const syncZone = async (group) => {
  const confirmed = await notify.confirm(
    'Synchronize Security Zone',
    `Dispatch complete facial roster for zone "${group.name}" across all linked camera devices?`,
    'Yes, Synchronize'
  );

  if (!confirmed) return;

  syncingId.value = group.id;
  try {
    const res = await apiClient.post(`/access-groups/${group.id}/sync-now`);
    const count = res.data.dispatched_count ?? res.data.personnel_count ?? 0;
    notify.success('Sync Dispatched', `Zone synchronization dispatched (${count} job(s) queued).`);
  } catch (e) {
    console.error('Failed to sync zone', e);
  } finally {
    syncingId.value = null;
  }
};

onMounted(() => {
  fetchGroups();
});
</script>
