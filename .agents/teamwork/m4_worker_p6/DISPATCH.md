# Worker M4 Dispatch Directive: Frontend Real-Time & Attendance Sync (Tasks 6.12 – 6.13)

## Objective
Implement Phase 6 Tasks 6.12 and 6.13 for the Intelligent AI Camera Hub.

## Reference Documents (Must Read First)
- `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md`
- `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_7/DISPATCH.md`
- `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_7/SCOPE.md`
- `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/p6_explorer_survey_3/survey_frontend_test_report.md`

## Exclusive File Ownership
- `resources/js/views/DeviceAlertsCenter.vue`
- `resources/js/stores/attendanceStore.js`

## Tasks
1. **Task 6.12**: Fix Private Echo Channel Mismatch in `resources/js/views/DeviceAlertsCenter.vue:731-745`
   - Use `echo.private('device-alerts')` instead of `echo.channel('device-alerts')` on both mount and unmount.
   - Pass handler references `handleLiveAlertReceived` and `handleLiveAlertUpdated` to `stopListening` to prevent detaching global listeners in `App.vue`.
2. **Task 6.13**: Correct Metric Binding in `resources/js/stores/attendanceStore.js:63-89` from Server Summary
   - Map `data.summary` directly into `this.stats` (with fallback to `data.stats` or `computeLocalStats()`).
   - Sync pagination from `data.records` (`this.pagination = { current_page, last_page, per_page, total }`).
   - Ensure workforce KPIs reflect the entire workforce rather than the 50-row paginated slice.

## Integrity Warning
DO NOT CHEAT. All implementations must be genuine. DO NOT hardcode test results, create dummy/facade implementations, or circumvent the intended task. A teamwork_preview_auditor will independently verify your work. Integrity violations WILL be detected and your work WILL be rejected.

## Verification
- Run `npm run build` to confirm Vite builds cleanly with zero errors.


## 2026-10-07T06:13:47Z
[Message] timestamp=2026-10-07T06:13:47Z sender=23671789-e817-4ea3-bad7-13b4ce2ecd46 priority=MESSAGE_PRIORITY_HIGH content=You are Worker M4 (Frontend Real-Time & Store Specialist) for Phase 6 Performance Optimization in AI-Camera-Integration.

Your working directory is:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/m4_worker_p6

Read these documents first before beginning:
1. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md
2. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/m4_worker_p6/DISPATCH.md
3. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_7/SCOPE.md
4. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/p6_explorer_survey_3/survey_frontend_test_report.md

Exclusive Write Ownership (DO NOT modify files outside this list):
- resources/js/views/DeviceAlertsCenter.vue
- resources/js/stores/attendanceStore.js

Your Tasks:
1. Task 6.12: Fix Echo Channel Type Mismatch in `resources/js/views/DeviceAlertsCenter.vue`
   - In `resources/js/views/DeviceAlertsCenter.vue` (around lines 731-745):
     Change `echo.channel('device-alerts')` to `echo.private('device-alerts')` on both mount (`onMounted`) and unmount (`onUnmounted`).
     When stopping listening in `onUnmounted`, pass the handler callbacks (`handleLiveAlertReceived` and `handleLiveAlertUpdated`) so unmounting `DeviceAlertsCenter` does not detach the global listeners registered in `App.vue`.
2. Task 6.13: Correct Metric Binding in `resources/js/stores/attendanceStore.js` from Server Summary
   - In `resources/js/stores/attendanceStore.js` (`fetchDailyAttendance`, lines 63-89):
     Extract `const summary = data.summary || data.stats;`
     If `summary` exists, populate `this.stats` with:
     `total_employees`: `Number(summary.total ?? summary.total_employees ?? 0)`
     `present`: `Number(summary.present ?? 0)`
     `absent`: `Number(summary.absent ?? 0)`
     `late`: `Number(summary.late ?? 0)`
     `on_leave`: `Number(summary.on_leave ?? 0)`
     `early_out`: `Number(summary.early_out ?? 0)`
     `half_day`: `Number(summary.half_day ?? 0)`
     `holiday`: `Number(summary.holiday ?? 0)`
     `attendance_rate`: `total > 0 ? Math.round((present / total) * 100) : 0`
     If no summary/stats from server, fall back to `this.computeLocalStats()`.
     Also sync `this.pagination` from `data.records` if present: `{ current_page, last_page, per_page, total }`.

MANDATORY INTEGRITY WARNING:
DO NOT CHEAT. All implementations must be genuine. DO NOT hardcode test results, create dummy/facade implementations, or circumvent the intended task. A teamwork_preview_auditor will independently verify your work. Integrity violations WILL be detected and your work WILL be rejected.

Verification:
- Run `npm run build` to verify Vite compiles cleanly with zero errors.

Deliverables:
- Maintain `progress.md` in your working directory with `Last visited: [timestamp]` heartbeat.
- Write a detailed `handoff.md` in `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/m4_worker_p6/handoff.md` with Observation, Logic Chain, Files Modified, Verification Results, and Conclusion.
- Send a completion message via `send_message` to the parent orchestrator with your results and file path.
