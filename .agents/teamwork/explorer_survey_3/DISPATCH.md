# DISPATCH — Explorer Survey 3: API Uniformity, Form Requests, Scramble OpenAPI & Frontend Composables

## Objective
Investigate and map the full specification, current codebase implementation, and gap analysis for:
- **R7: API Response Uniformity & Form Requests** (Area 4 in `system-evo.md`):
  - Inspect `routes/api.php` and existing controllers in `app/Http/Controllers/`.
  - Check how controllers currently return responses (raw models, paginators, inline validation).
  - Design unified API response envelopes (`success`, `data`, `meta`, `message`/`errors`) without breaking existing endpoint paths (`/api/*`).
  - Identify heavy controllers needing Form Request extraction (`EmployeeController`, `DeviceController`, `VisitorController`, `LeaveController`, `ShiftController`, etc.).
  - Check how OpenAPI documentation is set up or how Dedoc Scramble (`dedoc/scramble`) can be installed and configured in `composer.json` / `config/scramble.php` to serve `/docs/api`.
- **R8: Frontend Composable Architecture** (Area 5 in `system-evo.md`):
  - Inspect existing Vue views in `resources/js/components/` and `resources/js/views/`:
    `LiveTelemetry.vue`, `AccessLogsHistory.vue`, `DeviceAlertsCenter.vue`, `StrangerSnapsMonitor.vue`, `EmployeeFormModal.vue`, `VisitorCheckInWizard.vue`, `DeviceManager.vue`, `PersonnelManager.vue`.
  - Design reusable composables in `resources/js/composables/`:
    1. `usePaginatedResource(endpoint, initialFilters)`
    2. `useLiveTelemetryStream(channelName, eventHandlers)`
    3. `useBiometricCapture()`
  - Document how views should be refactored to consume these composables.
  - Verify package.json, TypeScript/Vite setup, and build pipelines.

## Authoritative Inputs
- `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md` (read header ## 2026-10-07T01:57:58Z)
- `/home/wsk-devops2/AI-Camera-Integration/system-evo.md`
- Existing codebase in `routes/api.php`, `app/Http/Controllers/`, `resources/js/`, `composer.json`, `package.json`.

## Output Requirements
Write your detailed report to:
`/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/explorer_survey_3/analysis.md`
and write your handoff to:
`/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/explorer_survey_3/handoff.md`.
Enumerate all required features, concrete files to create/modify, existing code patterns, dependencies, and risk areas.
Communicate completion back to orchestrator via `send_message`.
