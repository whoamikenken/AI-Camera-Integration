# Task Assignment: Specification & Requirements Survey

## Identity & Context
- Agent: teamwork_preview_spec_miner
- Working Directory: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/spec_miner_survey_1
- Parent Orchestrator: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator

## Objective
Read and deeply analyze the authoritative requirements:
- /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md
- /home/wsk-devops2/AI-Camera-Integration/tasks.md
- /home/wsk-devops2/AI-Camera-Integration/GEMINI.md

Map out the comprehensive feature inventory across all phases (1 through 12), with:
1. Feature list with IDs, categories, descriptions, and source references
2. Database entities, fields, relationships, and constraints
3. Business rules (shifts, grace periods, attendance punch pairing, overtime, leave deduction, visitor lifecycle & temporary camera face provisioning/expiration)
4. API endpoints required
5. Dependencies between features and recommended milestone decomposition

Write your detailed report to `survey_spec_report.md` and write a self-contained `handoff.md` in your working directory. Notify parent upon completion via `send_message`.

## 2026-09-29T15:48:51Z
From: ef2bd8f1-92a2-4f35-b75e-cfb5ce7f56ae
Priority: MESSAGE_PRIORITY_HIGH
Content:
You are assigned as the Specification Miner for the Survey phase of the Intelligent AI Camera Hub to Attendance and Visitor Management System transformation.

Your working directory is:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/spec_miner_survey_1

Your task is detailed in your DISPATCH.md file. Read:
1. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md
2. /home/wsk-devops2/AI-Camera-Integration/tasks.md
3. /home/wsk-devops2/AI-Camera-Integration/GEMINI.md

Catalog all features across phases 1 through 12, mapping:
- Feature Inventory (Feature ID, Phase, Title, Description, Requirements, Target Endpoints/Models)
- Complete entity schemas & relationship model (Auth, Organizations, Employees, Shifts, Attendance, Leaves, Visitors, Notifications, Settings, Audit)
- Detailed business logic rules (shift resolution, late/early-out/overtime math, punch pairing with device direction, temporary camera face sync/expiry for visitors, leave balance deductions and carryover)
- Dependency ordering and suggested milestone boundaries

Write your full findings to /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/spec_miner_survey_1/survey_spec_report.md
Write a self-contained handoff to /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/spec_miner_survey_1/handoff.md
Send a completion message back to parent when done.

