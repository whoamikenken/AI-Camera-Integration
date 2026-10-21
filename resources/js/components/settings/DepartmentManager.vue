<template>
  <div class="space-y-6">
    <!-- Top Sub-Navigation -->
    <div class="flex items-center justify-between bg-white border border-slate-200/80 p-4 rounded-xl shadow-xs">
      <div>
        <h3 class="text-base font-bold text-slate-900">Organization Hierarchy &amp; Structure</h3>
        <p class="text-xs text-slate-500">Configure organizational units, departments, job designations, and physical locations</p>
      </div>

      <!-- Action Button Based on Active Sub-Tab -->
      <div class="flex items-center gap-2">
        <button
          v-if="subTab === 'departments'"
          @click="openDepartmentModal(null)"
          class="px-3.5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-lg shadow-sm transition-all flex items-center gap-1.5 cursor-pointer"
        >
          <span>➕ Add Department</span>
        </button>
        <button
          v-else-if="subTab === 'designations'"
          @click="openDesignationModal(null)"
          class="px-3.5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-lg shadow-sm transition-all flex items-center gap-1.5 cursor-pointer"
        >
          <span>➕ Add Designation</span>
        </button>
        <button
          v-else-if="subTab === 'locations'"
          @click="openLocationModal(null)"
          class="px-3.5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-lg shadow-sm transition-all flex items-center gap-1.5 cursor-pointer"
        >
          <span>➕ Add Site / Location</span>
        </button>
      </div>
    </div>

    <!-- Segmented Navigation Bar -->
    <div role="tablist" class="flex items-center gap-2 border-b border-slate-200 pb-1">
      <button
        v-for="tab in subTabs"
        :key="tab.id"
        role="tab"
        :aria-selected="subTab === tab.id"
        :aria-controls="'tabpanel-' + tab.id"
        :id="'tab-' + tab.id"
        @click="subTab = tab.id"
        class="px-4 py-2 text-xs font-semibold rounded-t-lg transition-all flex items-center gap-2 border-b-2 cursor-pointer"
        :class="subTab === tab.id ? 'text-indigo-600 border-indigo-600 bg-white shadow-xs' : 'text-slate-500 border-transparent hover:text-slate-800 hover:border-slate-300'"
      >
        <span>{{ tab.icon }}</span>
        <span>{{ tab.label }}</span>
        <span v-if="tab.count !== undefined" class="ml-1 px-1.5 py-0.5 rounded-full text-[10px] bg-slate-100 text-slate-600 font-mono">
          {{ tab.count }}
        </span>
      </button>
    </div>

    <!-- 1. DEPARTMENTS TAB -->
    <div v-if="subTab === 'departments'" role="tabpanel" id="tabpanel-departments" aria-labelledby="tab-departments" class="space-y-4">
      <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-xs">
        <div class="overflow-x-auto">
          <table class="w-full text-left text-xs text-slate-700">
            <thead class="bg-slate-50 text-slate-500 uppercase text-[11px] font-semibold border-b border-slate-200">
              <tr>
                <th class="py-3 px-4">Code</th>
                <th class="py-3 px-4">Department Name</th>
                <th class="py-3 px-4">Parent Department</th>
                <th class="py-3 px-4">Department Head</th>
                <th class="py-3 px-4 text-center">Sub-Depts</th>
                <th class="py-3 px-4 text-right">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-if="loadingDepartments">
                <td colspan="6" class="py-12 text-center text-slate-500">Loading departments...</td>
              </tr>
              <tr v-else-if="departments.length === 0">
                <td colspan="6" class="py-12 text-center text-slate-500">No departments configured yet. Click "Add Department" to start.</td>
              </tr>
              <tr v-for="dept in departments" :key="dept.id" class="hover:bg-slate-50 transition-colors">
                <td class="py-3 px-4 font-mono font-semibold text-slate-900">{{ dept.code || '--' }}</td>
                <td class="py-3 px-4 font-bold text-slate-900">{{ dept.name }}</td>
                <td class="py-3 px-4">
                  <span v-if="dept.parent" class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-slate-100 text-slate-700 border border-slate-200">
                    {{ dept.parent.name }}
                  </span>
                  <span v-else class="text-slate-400 italic text-[11px]">Root Level</span>
                </td>
                <td class="py-3 px-4 text-slate-600">{{ dept.head_name || dept.head?.name || 'Unassigned' }}</td>
                <td class="py-3 px-4 text-center font-mono">{{ dept.children?.length || 0 }}</td>
                <td class="py-3 px-4 text-right space-x-2">
                  <button @click="openDepartmentModal(dept)" class="px-2.5 py-1 text-[11px] bg-white hover:bg-slate-50 text-slate-700 font-medium border border-slate-200 rounded transition-colors shadow-xs cursor-pointer">
                    Edit
                  </button>
                  <button @click="deleteDepartment(dept)" class="px-2.5 py-1 text-[11px] bg-rose-50 hover:bg-rose-100 text-rose-700 font-semibold border border-rose-200 rounded transition-colors shadow-xs cursor-pointer">
                    Delete
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- 2. DESIGNATIONS TAB -->
    <div v-else-if="subTab === 'designations'" role="tabpanel" id="tabpanel-designations" aria-labelledby="tab-designations" class="space-y-4">
      <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-xs">
        <div class="overflow-x-auto">
          <table class="w-full text-left text-xs text-slate-700">
            <thead class="bg-slate-50 text-slate-500 uppercase text-[11px] font-semibold border-b border-slate-200">
              <tr>
                <th class="py-3 px-4">Designation / Title</th>
                <th class="py-3 px-4">Hierarchy Level</th>
                <th class="py-3 px-4">Description</th>
                <th class="py-3 px-4 text-right">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-if="loadingDesignations">
                <td colspan="4" class="py-12 text-center text-slate-500">Loading designations...</td>
              </tr>
              <tr v-else-if="designations.length === 0">
                <td colspan="4" class="py-12 text-center text-slate-500">No job designations found. Click "Add Designation" to create one.</td>
              </tr>
              <tr v-for="desig in designations" :key="desig.id" class="hover:bg-slate-50 transition-colors">
                <td class="py-3 px-4 font-bold text-slate-900">{{ desig.name }}</td>
                <td class="py-3 px-4">
                  <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-indigo-50 text-indigo-700 border border-indigo-200">
                    Level {{ desig.level || 1 }}
                  </span>
                </td>
                <td class="py-3 px-4 text-slate-500">{{ desig.description || '--' }}</td>
                <td class="py-3 px-4 text-right space-x-2">
                  <button @click="openDesignationModal(desig)" class="px-2.5 py-1 text-[11px] bg-white hover:bg-slate-50 text-slate-700 font-medium border border-slate-200 rounded transition-colors shadow-xs cursor-pointer">
                    Edit
                  </button>
                  <button @click="deleteDesignation(desig)" class="px-2.5 py-1 text-[11px] bg-rose-50 hover:bg-rose-100 text-rose-700 font-semibold border border-rose-200 rounded transition-colors shadow-xs cursor-pointer">
                    Delete
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- 3. SITES & LOCATIONS TAB -->
    <div v-else-if="subTab === 'locations'" role="tabpanel" id="tabpanel-locations" aria-labelledby="tab-locations" class="space-y-4">
      <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-xs">
        <div class="overflow-x-auto">
          <table class="w-full text-left text-xs text-slate-700">
            <thead class="bg-slate-50 text-slate-500 uppercase text-[11px] font-semibold border-b border-slate-200">
              <tr>
                <th class="py-3 px-4">Location / Site Name</th>
                <th class="py-3 px-4">Address</th>
                <th class="py-3 px-4">Timezone</th>
                <th class="py-3 px-4">Geo Coordinates</th>
                <th class="py-3 px-4 text-right">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-if="loadingLocations">
                <td colspan="5" class="py-12 text-center text-slate-500">Loading locations...</td>
              </tr>
              <tr v-else-if="locations.length === 0">
                <td colspan="5" class="py-12 text-center text-slate-500">No physical locations configured. Click "Add Site / Location" to add one.</td>
              </tr>
              <tr v-for="loc in locations" :key="loc.id" class="hover:bg-slate-50 transition-colors">
                <td class="py-3 px-4 font-bold text-slate-900">{{ loc.name }}</td>
                <td class="py-3 px-4 text-slate-600">{{ loc.address || '--' }}</td>
                <td class="py-3 px-4 font-mono text-[11px] text-slate-600">{{ loc.timezone || 'Asia/Manila' }}</td>
                <td class="py-3 px-4 font-mono text-[11px] text-slate-500">{{ loc.coordinates || '--' }}</td>
                <td class="py-3 px-4 text-right space-x-2">
                  <button @click="openLocationModal(loc)" class="px-2.5 py-1 text-[11px] bg-white hover:bg-slate-50 text-slate-700 font-medium border border-slate-200 rounded transition-colors shadow-xs cursor-pointer">
                    Edit
                  </button>
                  <button @click="deleteLocation(loc)" class="px-2.5 py-1 text-[11px] bg-rose-50 hover:bg-rose-100 text-rose-700 font-semibold border border-rose-200 rounded transition-colors shadow-xs cursor-pointer">
                    Delete
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- 4. ORGANIZATION PROFILE TAB -->
    <div v-if="subTab === 'organization'" class="bg-white border border-slate-200 rounded-xl p-6 shadow-xs max-w-3xl space-y-6">
      <h4 class="text-sm font-bold text-slate-900 border-b border-slate-100 pb-3">Organization Identity &amp; Information</h4>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs font-semibold text-slate-700">Company / Organization Name</label>
          <input v-model="orgForm.name" type="text" class="w-full mt-1 bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500" />
        </div>
        <div>
          <label class="block text-xs font-semibold text-slate-700">Organization Code</label>
          <input v-model="orgForm.code" type="text" class="w-full mt-1 bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500" />
        </div>
        <div class="sm:col-span-2">
          <label class="block text-xs font-semibold text-slate-700">Headquarters Address</label>
          <textarea v-model="orgForm.address" rows="2" class="w-full mt-1 bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"></textarea>
        </div>
        <div>
          <label class="block text-xs font-semibold text-slate-700">Primary Timezone</label>
          <select v-model="orgForm.timezone" class="w-full mt-1 bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
            <option value="Asia/Manila">Asia/Manila (PHT, UTC+8)</option>
            <option value="UTC">UTC (UTC+0)</option>
            <option value="Asia/Singapore">Asia/Singapore (SGT, UTC+8)</option>
            <option value="America/New_York">America/New_York (EST, UTC-5)</option>
          </select>
        </div>
      </div>
      <div class="pt-4 border-t border-slate-100 flex justify-end">
        <button @click="saveOrganization" :disabled="savingOrg" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-lg shadow-sm transition-all cursor-pointer disabled:opacity-50">
          {{ savingOrg ? 'Saving...' : 'Save Organization Profile' }}
        </button>
      </div>
    </div>

    <!-- MODAL: Department Create/Edit -->
    <div v-if="showDeptModal" role="dialog" aria-modal="true" aria-labelledby="dept-modal-title" tabindex="-1" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4" @keydown.escape="showDeptModal = false">
      <div class="bg-white rounded-2xl border border-slate-200 max-w-md w-full p-6 shadow-2xl space-y-4">
        <h3 id="dept-modal-title" class="text-base font-bold text-slate-900">{{ editingDeptId ? 'Edit Department' : 'Create New Department' }}</h3>
        <div class="space-y-3">
          <div>
            <label for="dept-name" class="block text-xs font-semibold text-slate-700">Department Name *</label>
            <input id="dept-name" v-model="deptForm.name" type="text" placeholder="e.g. Information Technology" class="w-full mt-1 bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500" />
          </div>
          <div>
            <label for="dept-code" class="block text-xs font-semibold text-slate-700">Department Code *</label>
            <input id="dept-code" v-model="deptForm.code" type="text" placeholder="e.g. IT-ENG" class="w-full mt-1 bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-mono focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500" />
          </div>
          <div>
            <label for="dept-parent" class="block text-xs font-semibold text-slate-700">Parent Department</label>
            <select id="dept-parent" v-model="deptForm.parent_id" class="w-full mt-1 bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-700 focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
              <option :value="null">None (Top-Level Department)</option>
              <option v-for="d in parentOptions" :key="d.id" :value="d.id">{{ d.name }} ({{ d.code }})</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-semibold text-slate-700">Head of Department (Staff ID)</label>
            <input v-model="deptForm.head_id" type="number" placeholder="Optional Employee/Staff ID" class="w-full mt-1 bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-mono focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500" />
          </div>
        </div>
        <div class="pt-4 border-t border-slate-100 flex items-center justify-end gap-2">
          <button @click="showDeptModal = false" class="px-3.5 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-medium rounded-lg cursor-pointer">Cancel</button>
          <button @click="saveDepartment" :disabled="savingDept" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-lg shadow-sm cursor-pointer disabled:opacity-50">
            {{ savingDept ? 'Saving...' : 'Save Department' }}
          </button>
        </div>
      </div>
    </div>

    <!-- MODAL: Designation Create/Edit -->
    <div v-if="showDesigModal" role="dialog" aria-modal="true" aria-labelledby="desig-modal-title" tabindex="-1" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4" @keydown.escape="showDesigModal = false">
      <div class="bg-white rounded-2xl border border-slate-200 max-w-md w-full p-6 shadow-2xl space-y-4">
        <h3 id="desig-modal-title" class="text-base font-bold text-slate-900">{{ editingDesigId ? 'Edit Designation' : 'Create Job Title' }}</h3>
        <div class="space-y-3">
          <div>
            <label for="desig-name" class="block text-xs font-semibold text-slate-700">Designation / Title Name *</label>
            <input id="desig-name" v-model="desigForm.name" type="text" placeholder="e.g. Lead Software Engineer" class="w-full mt-1 bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500" />
          </div>
          <div>
            <label class="block text-xs font-semibold text-slate-700">Hierarchy Level (1 = Highest)</label>
            <select v-model.number="desigForm.level" class="w-full mt-1 bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-700">
              <option :value="1">Level 1 - Executive / Director</option>
              <option :value="2">Level 2 - Manager / Dept Head</option>
              <option :value="3">Level 3 - Senior / Specialist</option>
              <option :value="4">Level 4 - Associate / Officer</option>
              <option :value="5">Level 5 - Entry / Support</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-semibold text-slate-700">Description</label>
            <input v-model="desigForm.description" type="text" placeholder="Job responsibilities summary" class="w-full mt-1 bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900" />
          </div>
        </div>
        <div class="pt-4 border-t border-slate-100 flex items-center justify-end gap-2">
          <button @click="showDesigModal = false" class="px-3.5 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-medium rounded-lg cursor-pointer">Cancel</button>
          <button @click="saveDesignation" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-lg shadow-sm cursor-pointer">Save Title</button>
        </div>
      </div>
    </div>

    <!-- MODAL: Location Create/Edit -->
    <div v-if="showLocModal" role="dialog" aria-modal="true" aria-labelledby="loc-modal-title" tabindex="-1" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4" @keydown.escape="showLocModal = false">
      <div class="bg-white rounded-2xl border border-slate-200 max-w-md w-full p-6 shadow-2xl space-y-4">
        <h3 id="loc-modal-title" class="text-base font-bold text-slate-900">{{ editingLocId ? 'Edit Location' : 'Create Location / Site' }}</h3>
        <div class="space-y-3">
          <div>
            <label for="loc-name" class="block text-xs font-semibold text-slate-700">Site / Location Name *</label>
            <input id="loc-name" v-model="locForm.name" type="text" placeholder="e.g. Main Lobby Gate 1" class="w-full mt-1 bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900" />
          </div>
          <div>
            <label for="loc-address" class="block text-xs font-semibold text-slate-700">Physical Address</label>
            <input id="loc-address" v-model="locForm.address" type="text" placeholder="Building, Street, City" class="w-full mt-1 bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900" />
          </div>
          <div>
            <label for="loc-timezone" class="block text-xs font-semibold text-slate-700">Timezone</label>
            <input id="loc-timezone" v-model="locForm.timezone" type="text" placeholder="Asia/Manila" class="w-full mt-1 bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-mono" />
          </div>
          <div>
            <label for="loc-coords" class="block text-xs font-semibold text-slate-700">Geo Coordinates (Lat, Long)</label>
            <input id="loc-coords" v-model="locForm.coordinates" type="text" placeholder="14.5547, 121.0244" class="w-full mt-1 bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-mono" />
          </div>
        </div>
        <div class="pt-4 border-t border-slate-100 flex items-center justify-end gap-2">
          <button @click="showLocModal = false" class="px-3.5 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-medium rounded-lg cursor-pointer">Cancel</button>
          <button @click="saveLocation" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-lg shadow-sm cursor-pointer">Save Location</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import apiClient from '../../api/client';
import notify from '../../utils/notify';

const subTab = ref('departments');

const departments = ref([]);
const designations = ref([]);
const locations = ref([]);
const orgForm = ref({ name: 'Pinnacle Technologies Inc.', code: 'PINNACLE-HQ', address: '', timezone: 'Asia/Manila' });

const loadingDepartments = ref(false);
const loadingDesignations = ref(false);
const loadingLocations = ref(false);
const savingDept = ref(false);
const savingOrg = ref(false);

const subTabs = computed(() => [
  { id: 'departments', label: 'Departments', icon: '🏬', count: departments.value.length },
  { id: 'designations', label: 'Job Titles & Designations', icon: '💼', count: designations.value.length },
  { id: 'locations', label: 'Sites & Locations', icon: '📍', count: locations.value.length },
  { id: 'organization', label: 'Organization Profile', icon: '🏢' },
]);

// Department Modal State
const showDeptModal = ref(false);
const editingDeptId = ref(null);
const deptForm = ref({ name: '', code: '', parent_id: null, head_id: null });

const parentOptions = computed(() => {
  return departments.value.filter(d => !editingDeptId.value || d.id !== editingDeptId.value);
});

// Designation Modal State
const showDesigModal = ref(false);
const editingDesigId = ref(null);
const desigForm = ref({ name: '', level: 3, description: '' });

// Location Modal State
const showLocModal = ref(false);
const editingLocId = ref(null);
const locForm = ref({ name: '', address: '', timezone: 'Asia/Manila', coordinates: '' });

// API Operations
const fetchDepartments = async () => {
  loadingDepartments.value = true;
  try {
    const res = await apiClient.get('/departments');
    departments.value = res.data.data || res.data || [];
  } catch (e) {
    console.error('Failed to load departments', e);
  } finally {
    loadingDepartments.value = false;
  }
};

const fetchDesignations = async () => {
  loadingDesignations.value = true;
  try {
    const res = await apiClient.get('/designations');
    designations.value = res.data.data || res.data || [];
  } catch (e) {
    console.error('Failed to load designations', e);
  } finally {
    loadingDesignations.value = false;
  }
};

const fetchLocations = async () => {
  loadingLocations.value = true;
  try {
    const res = await apiClient.get('/locations');
    locations.value = res.data.data || res.data || [];
  } catch (e) {
    console.error('Failed to load locations', e);
  } finally {
    loadingLocations.value = false;
  }
};

const fetchOrganization = async () => {
  try {
    const res = await apiClient.get('/organizations');
    const org = Array.isArray(res.data.data) ? res.data.data[0] : (res.data.data || res.data);
    if (org) {
      orgForm.value = {
        id: org.id,
        name: org.name || 'Pinnacle Technologies Inc.',
        code: org.code || 'PINNACLE-HQ',
        address: org.address || '',
        timezone: org.timezone || 'Asia/Manila',
      };
    }
  } catch (e) {
    console.warn('Organization endpoint not yet populated', e);
  }
};

// Department Actions
const openDepartmentModal = (dept) => {
  if (dept) {
    editingDeptId.value = dept.id;
    deptForm.value = { name: dept.name, code: dept.code, parent_id: dept.parent_id || null, head_id: dept.head_id || null };
  } else {
    editingDeptId.value = null;
    deptForm.value = { name: '', code: '', parent_id: null, head_id: null };
  }
  showDeptModal.value = true;
};

const saveDepartment = async () => {
  if (!deptForm.value.name || !deptForm.value.code) {
    notify.error('Required Fields', 'Department name and code are required.');
    return;
  }
  savingDept.value = true;
  try {
    if (editingDeptId.value) {
      await apiClient.put(`/departments/${editingDeptId.value}`, deptForm.value);
      notify.toast('Department updated successfully.');
    } else {
      await apiClient.post('/departments', deptForm.value);
      notify.toast('Department created successfully.');
    }
    showDeptModal.value = false;
    await fetchDepartments();
  } finally {
    savingDept.value = false;
  }
};

const deleteDepartment = async (dept) => {
  const confirmed = await notify.confirm(
    'Delete Department',
    `Are you sure you want to delete department "${dept.name}"? This action cannot be undone.`,
    'Yes, Delete',
    'Cancel',
    true
  );
  if (!confirmed) return;
  try {
    await apiClient.delete(`/departments/${dept.id}`);
    notify.toast('Department removed.');
    await fetchDepartments();
  } catch (e) {
    // Handled in client interceptor
  }
};

// Designation Actions
const openDesignationModal = (desig) => {
  if (desig) {
    editingDesigId.value = desig.id;
    desigForm.value = { name: desig.name, level: desig.level || 3, description: desig.description || '' };
  } else {
    editingDesigId.value = null;
    desigForm.value = { name: '', level: 3, description: '' };
  }
  showDesigModal.value = true;
};

const saveDesignation = async () => {
  if (!desigForm.value.name) return;
  try {
    if (editingDesigId.value) {
      await apiClient.put(`/designations/${editingDesigId.value}`, desigForm.value);
      notify.toast('Designation updated.');
    } else {
      await apiClient.post('/designations', desigForm.value);
      notify.toast('Designation created.');
    }
    showDesigModal.value = false;
    await fetchDesignations();
  } catch (e) {}
};

const deleteDesignation = async (desig) => {
  const confirmed = await notify.confirm('Delete Designation', `Delete "${desig.name}"?`, 'Delete', 'Cancel', true);
  if (!confirmed) return;
  await apiClient.delete(`/designations/${desig.id}`);
  notify.toast('Designation removed.');
  await fetchDesignations();
};

// Location Actions
const openLocationModal = (loc) => {
  if (loc) {
    editingLocId.value = loc.id;
    locForm.value = { name: loc.name, address: loc.address || '', timezone: loc.timezone || 'Asia/Manila', coordinates: loc.coordinates || '' };
  } else {
    editingLocId.value = null;
    locForm.value = { name: '', address: '', timezone: 'Asia/Manila', coordinates: '' };
  }
  showLocModal.value = true;
};

const saveLocation = async () => {
  if (!locForm.value.name) return;
  try {
    if (editingLocId.value) {
      await apiClient.put(`/locations/${editingLocId.value}`, locForm.value);
      notify.toast('Location updated.');
    } else {
      await apiClient.post('/locations', locForm.value);
      notify.toast('Location created.');
    }
    showLocModal.value = false;
    await fetchLocations();
  } catch (e) {}
};

const deleteLocation = async (loc) => {
  const confirmed = await notify.confirm('Delete Location', `Delete "${loc.name}"?`, 'Delete', 'Cancel', true);
  if (!confirmed) return;
  await apiClient.delete(`/locations/${loc.id}`);
  notify.toast('Location removed.');
  await fetchLocations();
};

const saveOrganization = async () => {
  savingOrg.value = true;
  try {
    const id = orgForm.value.id || 1;
    await apiClient.put(`/organizations/${id}`, orgForm.value);
    notify.toast('Organization profile updated.');
  } catch (e) {
  } finally {
    savingOrg.value = false;
  }
};

onMounted(() => {
  fetchDepartments();
  fetchDesignations();
  fetchLocations();
  fetchOrganization();
});
</script>
