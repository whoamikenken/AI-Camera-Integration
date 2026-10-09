# DISPATCH DIRECTIVE — explorer_m4_frontend

## Identity
- Archetype: teamwork_preview_explorer
- Working Directory: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/explorer_m4_frontend
- Parent Conversation ID: 340b2ee2-86ac-4ca7-9f71-8c1542c65adb

## Mission
Investigate the frontend views, stores, batch selection UI, and bulk campaign integration for Milestone M4 (Features #25 and #26).

## Mandatory First Step
Read the following authoritative documents:
1. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md`
2. `/home/wsk-devops2/AI-Camera-Integration/system-evo.md` (Feature 5)
3. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md`
4. `/home/wsk-devops2/AI-Camera-Integration/TEST_READY.md`

## Exploration Areas
1. **Device Management View (`resources/js/views/DeviceManager.vue`)**:
   - Inspect current table layout, device listing, and actions.
   - Design multi-select checkboxes (column in table, select-all checkbox in table header).
   - Design batch action toolbar (visible when 1+ devices selected, showing count):
     * "Reboot Fleet" (triggers `POST /api/devices/bulk-reboot`)
     * "Sync MQTT Config" (triggers modal / `POST /api/devices/bulk-sync-mqtt`)
     * "Clear Selection"
   - Modal confirmation dialog: accessible WCAG 2.1 AA dialog (NO `window.confirm()`).
2. **Personnel Management View (`resources/js/views/PersonnelManager.vue`)**:
   - Inspect current table layout, employee/personnel listing, and actions.
   - Design multi-select checkboxes (row selection, select-all in header).
   - Design batch action toolbar (visible when 1+ personnel selected):
     * "Sync to Cameras" (triggers `POST /api/personnel/bulk-sync`)
     * "Delete Selected" (accessible confirmation modal -> triggers `POST /api/personnel/bulk-delete`)
     * "Clear Selection"
3. **Campaign Progress Tracking & Polling**:
   - Design a reactive campaign tracker or toast/modal that polls `GET /api/bulk-campaigns/{id}` until status is `completed` or `failed`.
   - Progress bar showing percentage (`processed_items / total_items * 100`) and failed items count.
4. **Pinia Stores & API Helpers**:
   - Inspect `resources/js/stores/deviceStore.js` and `resources/js/stores/personnelStore.js` (or API services).
   - Propose methods for `bulkReboot(deviceIds)`, `bulkSyncMqtt(deviceIds, config)`, `bulkSyncPersonnel(personnelIds)`, `bulkDeletePersonnel(personnelIds)`, `fetchCampaignProgress(campaignId)`.
5. **Accessibility (WCAG 2.1 AA)**:
   - Ensure proper `aria-label` for checkboxes ("Select all devices", "Select device {name}").
   - Ensure keyboard accessibility on batch action buttons.

## Output
Write your comprehensive frontend blueprint to:
`/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/explorer_m4_frontend/handoff.md`

When complete, notify parent via `send_message` with summary and path.

## 2026-10-08T18:40:46Z
You are explorer_m4_frontend.
Your working directory is: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/explorer_m4_frontend
Your dispatch directive is at: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/explorer_m4_frontend/DISPATCH.md
Your parent is conversation ID: 340b2ee2-86ac-4ca7-9f71-8c1542c65adb

MANDATORY FIRST STEP: Read the following documents:
1. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/explorer_m4_frontend/DISPATCH.md
2. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md
3. /home/wsk-devops2/AI-Camera-Integration/system-evo.md (Feature 5)
4. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md
5. /home/wsk-devops2/AI-Camera-Integration/TEST_READY.md

Investigate frontend components, batch selection, and bulk campaigns integration:
1. Inspect resources/js/views/DeviceManager.vue and resources/js/views/PersonnelManager.vue.
2. Design multi-select checkboxes and reactive selection state.
3. Design batch action toolbars for DeviceManager.vue (Reboot Fleet, Sync MQTT, Clear) and PersonnelManager.vue (Sync to Cameras, Delete Selected, Clear).
4. Design campaign progress polling and tracking UI (polling GET /api/bulk-campaigns/{id}).
5. Design accessible confirmation modals (WCAG 2.1 AA dialogs, NO native window.confirm()).

Write your report to /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/explorer_m4_frontend/handoff.md.
When finished, send a message to parent (ID: 340b2ee2-86ac-4ca7-9f71-8c1542c65adb) via send_message.
