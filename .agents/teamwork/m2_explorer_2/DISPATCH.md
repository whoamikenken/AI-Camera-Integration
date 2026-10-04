## 2026-09-29T22:13:22Z
You are m2_explorer_2 (teamwork_preview_explorer) for Milestone 2: Shift & Schedule Management (Phase 3).
Your working directory is: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/m2_explorer_2/

MANDATORY INPUTS:
1. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md
2. /home/wsk-devops2/AI-Camera-Integration/PROJECT.md
3. /home/wsk-devops2/AI-Camera-Integration/tasks.md (§ Phase 3 — Shift & Schedule Management)
4. /home/wsk-devops2/AI-Camera-Integration/tests/Feature/E2E/Tier1FeatureCoverageTest.php (Section 2, M2 tests)
5. Existing code: database/migrations/, app/Models/Organization.php, routes/api.php

YOUR MISSION:
Investigate and produce a comprehensive technical implementation blueprint for:
1. `shifts` migration & `Shift` model:
   - Fields: `id`, `organization_id`, `name`, `code` (unique per org), `shift_start` (TIME), `shift_end` (TIME), `grace_period_minutes` (default 15), `early_out_threshold_minutes` (default 30), `half_day_threshold_hours` (default 4.0), `min_hours_full_day` (default 8.0), `is_overnight` (boolean), `break_duration_minutes` (default 60), `is_flexible` (boolean), `color` (hex string, e.g. #3b82f6), `is_active` (boolean), timestamps.
   - Helper methods: `durationMinutes()`, `isDayShift()`, `isOvernight()`, `crossesMidnight()`.
   - Seed default shifts (Standard Day Shift 09:00-18:00, Night Shift 22:00-07:00, Flexible Shift).
2. `employee_shift_assignments` migration & `EmployeeShiftAssignment` model:
   - Fields: `id`, `employee_id`, `shift_id`, `effective_from` (DATE), `effective_to` (DATE, nullable), `assigned_days` (JSON array of day integers e.g. [1,2,3,4,5] for Mon-Fri), `created_by` (FK to users), timestamps.
   - Support shift rotation and future-dated assignments.
3. `holidays` migration & `Holiday` model:
   - Fields: `id`, `organization_id`, `name`, `date` (DATE), `type` (enum/string: public, company, optional), `is_recurring` (boolean annual recurrence), `applies_to` (JSON array of department/location IDs or null for all), timestamps.
4. `ShiftController` & `HolidayController` APIs:
   - `GET /api/shifts`, `POST /api/shifts`, `GET /api/shifts/{id}`, `PUT /api/shifts/{id}`, `DELETE /api/shifts/{id}`
   - `POST /api/shifts/bulk-assign` (bulk assign shifts to departments or employees)
   - `GET /api/holidays`, `POST /api/holidays`, `GET /api/holidays/{id}`, `PUT /api/holidays/{id}`, `DELETE /api/holidays/{id}`
   - Wire RBAC permissions (`schedules.view`, `schedules.manage`) in `routes/api.php`.
5. Verification:
   - Ensure all M2 shift and holiday tests in `tests/Feature/E2E/Tier1FeatureCoverageTest.php` pass.

OUTPUT:
Write your complete technical blueprint to:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/m2_explorer_2/handoff.md
(If file write prompts in your teamwork folder timeout, deliver your handoff via send_message directly to parent).
When finished, send a message to parent summarizing your completion.
