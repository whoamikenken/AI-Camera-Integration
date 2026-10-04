## 2026-09-29T22:13:21Z
You are m2_explorer_1 (teamwork_preview_explorer) for Milestone 2: Employee Management & Biometric Linkage (Phase 2).
Your working directory is: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/m2_explorer_1/

MANDATORY INPUTS:
1. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md
2. /home/wsk-devops2/AI-Camera-Integration/PROJECT.md
3. /home/wsk-devops2/AI-Camera-Integration/tasks.md (§ Phase 2 — Employee Management)
4. /home/wsk-devops2/AI-Camera-Integration/tests/Feature/E2E/Tier1FeatureCoverageTest.php (Section 2, M2 tests)
5. Existing database models & migrations: app/Models/Personnel.php, app/Models/User.php, app/Models/Department.php, app/Models/Designation.php, database/migrations/

YOUR MISSION:
Investigate and produce a comprehensive technical implementation blueprint for:
1. `employees` database migration & `Employee` Eloquent model:
   - Fields: `id`, `personnel_id` (1-to-1 FK to personnel.id for biometric face linkage), `employee_code` (unique), `first_name`, `last_name`, `email`/`work_email`, `personal_email`, `phone`, `avatar`, `organization_id`, `department_id`, `designation_id`, `location_id`, `reporting_manager_id` (self-referencing FK), `user_id` (nullable FK to users for self-service), `employment_type` (full-time, part-time, contract, intern), `employment_status` (active, on-leave, suspended, terminated, resigned), `date_of_joining`, `date_of_leaving`, `emergency_contact_name`, `emergency_contact_phone`, `shift_id` (nullable FK to shifts), soft-deletes (`deleted_at`), timestamps.
   - 1-to-1 Biometric Bridge: Creating an employee with face image creates/links `personnel` entity, triggering `PersonnelObserver` and `SyncPersonnelJob` on queue `camera-sync`. Updating or deleting employee cascades to personnel sync.
   - Model relationships: `personnel()`, `department()`, `designation()`, `organization()`, `location()`, `manager()`, `directReports()`, `user()`, `shift()`, `shiftAssignments()`.
   - Contract methods needed for M3: `currentShift(Carbon $date)`, `isHoliday(Carbon $date)`, `isRestDay(Carbon $date)`.
2. `EmployeeController` REST API & Routes:
   - `GET /api/employees` (search, pagination, filters by department, designation, status, organization)
   - `POST /api/employees` (create employee, automatic biometric personnel linkage, validation)
   - `GET /api/employees/{id}` (full detail with relations)
   - `PUT /api/employees/{id}` (update details, photo, department/designation changes)
   - `DELETE /api/employees/{id}` (soft-delete, preservation of historical punches)
   - `GET /api/employees/{id}/attendance-summary`
   - `POST /api/employees/{id}/assign-shift`
   - `POST /api/employees/import` (CSV bulk onboarding)
   - `GET /api/employees/export` (CSV export)
3. RBAC Permissions:
   - Wire permissions (`employees.view`, `employees.manage`, `employees.delete`) with `CheckPermission` middleware in `routes/api.php`.
4. Verification & Testing:
   - Blueprint must ensure passing all M2 tests in `tests/Feature/E2E/Tier1FeatureCoverageTest.php`.

OUTPUT:
Write your complete technical blueprint to:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/m2_explorer_1/handoff.md
(If file write prompts in your teamwork folder timeout, deliver your handoff via send_message directly to parent).
When finished, send a message to parent summarizing your completion.
