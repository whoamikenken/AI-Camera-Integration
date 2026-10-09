# DISPATCH DIRECTIVE — explorer_m6_frontend

## Identity
- **Agent:** `explorer_m6_frontend`
- **Role:** Frontend Explorer (Milestone M6: Vue 3 Composables & View Refactoring)
- **Working Directory:** `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/explorer_m6_frontend`
- **Parent Conversation ID:** `340b2ee2-86ac-4ca7-9f71-8c1542c65adb`

---

## Authoritative Inputs to Read
1. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md` (under Section `## 2026-10-07T01:57:58Z`)
2. `/home/wsk-devops2/AI-Camera-Integration/system-evo.md` (Area 5: Frontend Composables & Reactive Architecture)
3. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md` (Milestone M6: Features #38, #39, #40, #41)
4. `/home/wsk-devops2/AI-Camera-Integration/TEST_READY.md` (Milestone 6 section)

---

## Frontend Investigation Scope
Investigate existing frontend components, composables, and reactive stores:
1. **Composable `resources/js/composables/usePaginatedResource.js`**:
   - Inspect existing pagination patterns in `DeviceManager.vue`, `PersonnelManager.vue`, `VisitorDashboard.vue`, `ShiftManager.vue`.
   - Design `usePaginatedResource(fetchFn, options)` with reactive `items`, `pagination`, `loading`, `error`, `searchQuery` (debounced 300ms), `page`, `perPage`, `sort`, `filter`, `refresh`.
   - Support universal response parsing (handling `{ data: [...], meta: {...} }`, `{ data: { data: [...], current_page: ... } }`, or raw array).
2. **Composable `resources/js/composables/useLiveTelemetryStream.js`**:
   - Inspect Echo listeners and WebSocket feeds in `LiveDashboard.vue` and `AccessLogViewer.vue`.
   - Design composable connecting to private Echo channel (`telemetry`, `access-logs`, `device-alerts`).
   - Include optional audio chime alert on verification / stranger detection.
   - Buffer incoming events and update reactive list.
3. **Composable `resources/js/composables/useBiometricCapture.js`**:
   - Inspect webcam capture modal in `PersonnelManager.vue`.
   - Design composable managing `mediaDevices.getUserMedia`, canvas extraction with 1:1 square aspect ratio crop, validation of minimum dimensions (e.g. 200x200), and base64 export.
4. **View Refactoring (Feature 41)**:
   - Identify which views will be refactored to consume these composables (`DeviceManager.vue`, `PersonnelManager.vue`, `VisitorDashboard.vue`, `ShiftManager.vue`, etc.).
   - Verify build compatibility (`npm run build`).

Write your report to `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/explorer_m6_frontend/handoff.md` and notify parent via `send_message`.

## 2026-10-09T00:37:34Z
[Message] timestamp=2026-10-09T00:37:34Z sender=340b2ee2-86ac-4ca7-9f71-8c1542c65adb priority=MESSAGE_PRIORITY_HIGH
content=You are explorer_m6_frontend.
Your working directory is: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/explorer_m6_frontend
Your dispatch directive is at: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/explorer_m6_frontend/DISPATCH.md
Your parent is conversation ID: 340b2ee2-86ac-4ca7-9f71-8c1542c65adb

MANDATORY FIRST STEP: Read the following documents:
1. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md (under Section ## 2026-10-07T01:57:58Z)
2. /home/wsk-devops2/AI-Camera-Integration/system-evo.md (Area 5: Frontend Composables & Reactive Architecture)
3. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md (Milestone M6: Features #38, #39, #40, #41)
4. /home/wsk-devops2/AI-Camera-Integration/TEST_READY.md
