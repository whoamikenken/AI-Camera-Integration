# Task Assignment: Milestone 1 — Organization Hierarchy, Settings & Audit Trail Exploration

## Identity & Context
- Agent: teamwork_preview_explorer
- Working Directory: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/m1_explorer_2
- Parent Orchestrator: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator

## Objective
Investigate and design the implementation blueprint for Features 5, 7, & 8 of Milestone 1:
- /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md
- /home/wsk-devops2/AI-Camera-Integration/PROJECT.md
- /home/wsk-devops2/AI-Camera-Integration/tasks.md §1.3, §10.1, §10.2

Investigate:
1. Organization Models & Migrations: `organizations`, `locations`/`sites`, `departments` (with `parent_id` for tree hierarchy and `head_id` FK to employees), `designations`/`job_titles`.
2. Safe Device Extension: Migration to add nullable `organization_id`, `location_id`, and `device_role` (`entry`, `exit`, `bidirectional`, `visitor_kiosk`) to `devices` table without breaking existing device records.
3. Global System Settings: `settings` migration & model with key-value pairs, organization scoping, and type casting (`attendance.auto_process`, `attendance.late_grace_minutes`, `visitor.require_photo`, `visitor.enroll_face_to_camera`, etc.).
4. Comprehensive Audit Trail: `audit_logs` migration & polymorphic model (`auditable_type`, `auditable_id`, `user_id`, `action`, `old_values`, `new_values`, `ip_address`, `created_at`).
5. Controllers & APIs: `OrganizationController` (org, locations, departments, designations CRUD), `SettingController` (get/update settings, audit logs browser).

Write your detailed design and recommendation to `m1_org_settings_design.md` and a self-contained `handoff.md`. Notify parent when complete.

## 2026-09-29T15:57:00Z
You are assigned as Explorer 2 for Milestone 1: Organization Hierarchy, Settings & Audit Trail.

Your working directory is:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/m1_explorer_2

Your detailed instructions are in your DISPATCH.md file. Read:
1. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md
2. /home/wsk-devops2/AI-Camera-Integration/PROJECT.md
3. /home/wsk-devops2/AI-Camera-Integration/tasks.md §1.3, §10.1, §10.2

Investigate and produce the implementation blueprint for:
- Organization hierarchy: organizations, locations, departments (with parent_id & head_id), designations
- Safe device extension: adding nullable organization_id, location_id, device_role to devices table
- Global system settings: key-value table with type casting
- Audit trail: polymorphic audit_logs model and logging service
- OrganizationController and SettingController

Write findings to /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/m1_explorer_2/m1_org_settings_design.md
Write a self-contained handoff to /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/m1_explorer_2/handoff.md
Send a completion message to parent when done.
