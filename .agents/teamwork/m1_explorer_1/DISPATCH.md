# Task Assignment: Milestone 1 — Auth & RBAC Architecture Exploration

## Identity & Context
- Agent: teamwork_preview_explorer
- Working Directory: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/m1_explorer_1
- Parent Orchestrator: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator

## Objective
Investigate and design the implementation blueprint for Features 1 & 2 of Milestone 1:
- /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md
- /home/wsk-devops2/AI-Camera-Integration/PROJECT.md
- /home/wsk-devops2/AI-Camera-Integration/tasks.md §1.1, §1.2

Investigate:
1. User Authentication: Laravel Sanctum setup vs session/token, migration for users/tokens, `AuthController` (login, logout, profile, tokens), password hashing, rate limiting.
2. RBAC Data Models & Migrations: `roles`, `permissions`, `role_permission`, `user_role` tables.
3. Seeding Strategy: Default roles (`super-admin`, `admin`, `hr-manager`, `security`, `receptionist`, `manager`, `employee`) and standard permission groups (`attendance.*`, `visitors.*`, `employees.*`, `devices.*`, `reports.*`, `settings.*`).
4. Route Guarding: `CheckPermission` middleware, attaching to routes, handling camera webhook exemptions (`/api/Subscribe/*` and `/action/*` must remain unauthenticated).
5. User model extension: Relationships (`roles`, `permissions`, `hasRole()`, `hasPermission()`).

Write your detailed design and fix recommendation to `m1_auth_rbac_design.md` and a self-contained `handoff.md`. Notify parent when complete.

## 2026-09-29T15:57:00Z
You are assigned as Explorer 1 for Milestone 1: Security Foundation & RBAC.

Your working directory is:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/m1_explorer_1

Your detailed instructions are in your DISPATCH.md file. Read:
1. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md
2. /home/wsk-devops2/AI-Camera-Integration/PROJECT.md
3. /home/wsk-devops2/AI-Camera-Integration/tasks.md §1.1, §1.2

Investigate and produce the implementation blueprint for:
- Laravel Sanctum/session auth, User model, tokens, login/logout/profile API
- RBAC migrations (roles, permissions, role_permission, user_role)
- Seeding for 7 default roles and all permission groups
- CheckPermission middleware and route protection (preserving camera webhook exemptions)

Write findings to /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/m1_explorer_1/m1_auth_rbac_design.md
Write a self-contained handoff to /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/m1_explorer_1/handoff.md
Send a completion message to parent when done.
