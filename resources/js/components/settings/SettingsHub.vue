<template>
  <div class="space-y-6">
    <!-- Hub Top Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-white border border-slate-200/80 p-4 rounded-xl shadow-xs">
      <div>
        <h2 class="text-lg font-bold text-slate-900">System Administration &amp; Settings</h2>
        <p class="text-xs text-slate-500">Manage organization hierarchy, biometric parameters, security policies, and audit logs</p>
      </div>

      <!-- Settings Sub-Navigation Pill Bar -->
      <div role="tablist" aria-label="Settings navigation tabs" class="flex flex-wrap items-center gap-1.5 bg-slate-100 p-1 rounded-xl border border-slate-200">
        <button
          v-for="tab in tabs"
          :key="tab.id"
          type="button"
          role="tab"
          :id="'settings-tab-' + tab.id"
          :aria-selected="activeTab === tab.id ? 'true' : 'false'"
          :aria-controls="'settings-panel-' + tab.id"
          @click="activeTab = tab.id"
          class="px-3.5 py-1.5 text-xs font-semibold rounded-lg transition-all flex items-center gap-1.5 cursor-pointer"
          :class="activeTab === tab.id ? 'bg-white text-indigo-600 shadow-xs font-bold' : 'text-slate-600 hover:text-slate-900'"
        >
          <span>{{ tab.icon }}</span>
          <span>{{ tab.label }}</span>
        </button>
      </div>
    </div>

    <!-- Active Sub-View Component -->
    <div>
      <div
        v-if="activeTab === 'departments'"
        id="settings-panel-departments"
        role="tabpanel"
        aria-labelledby="settings-tab-departments"
        tabindex="0"
      >
        <DepartmentManager />
      </div>
      <div
        v-else-if="activeTab === 'access-groups'"
        id="settings-panel-access-groups"
        role="tabpanel"
        aria-labelledby="settings-tab-access-groups"
        tabindex="0"
      >
        <AccessGroupManager />
      </div>
      <div
        v-else-if="activeTab === 'system'"
        id="settings-panel-system"
        role="tabpanel"
        aria-labelledby="settings-tab-system"
        tabindex="0"
      >
        <SystemSettings />
      </div>
      <div
        v-else-if="activeTab === 'audit'"
        id="settings-panel-audit"
        role="tabpanel"
        aria-labelledby="settings-tab-audit"
        tabindex="0"
      >
        <AuditLogViewer />
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import DepartmentManager from './DepartmentManager.vue';
import AccessGroupManager from './AccessGroupManager.vue';
import SystemSettings from './SystemSettings.vue';
import AuditLogViewer from './AuditLogViewer.vue';

const activeTab = ref('departments');

const tabs = [
  { id: 'departments', label: 'Organization & Departments', icon: '🏢' },
  { id: 'access-groups', label: 'Access Groups & Zones', icon: '🛡️' },
  { id: 'system', label: 'System Parameters', icon: '⚙️' },
  { id: 'audit', label: 'Audit Trail', icon: '📋' },
];
</script>
