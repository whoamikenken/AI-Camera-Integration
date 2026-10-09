# DISPATCH DIRECTIVE — explorer_m6_backend

## Identity
- **Agent:** `explorer_m6_backend`
- **Role:** Backend Explorer (Milestone M6: API Uniformity, Form Requests & OpenAPI Documentation)
- **Working Directory:** `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/explorer_m6_backend`
- **Parent Conversation ID:** `340b2ee2-86ac-4ca7-9f71-8c1542c65adb`

---

## Authoritative Inputs to Read
1. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md` (under Section `## 2026-10-07T01:57:58Z`)
2. `/home/wsk-devops2/AI-Camera-Integration/system-evo.md` (Area 4: API Uniformity & Documentation)
3. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md` (Milestone M6: Features #34, #35, #36, #37)
4. `/home/wsk-devops2/AI-Camera-Integration/TEST_READY.md` (Milestone 6 section)

---

## Backend Investigation Scope
Investigate existing backend controllers, responses, form requests, and documentation:
1. **API Response Envelope (`app/Http/Responses/ApiResponse.php` or helper)**:
   - Inspect existing controller responses across controllers (`DeviceController`, `PersonnelController`, `VisitorController`, `LeaveController`, `ShiftController`, etc.).
   - Design `ApiResponse::success($data, $message, $code, $meta)` and `ApiResponse::error($message, $code, $errors)`.
   - Ensure backward compatibility so existing feature and unit tests expecting specific top-level or pagination fields continue to pass seamlessly.
2. **Hardware Webhook Exemption**:
   - Inspect `/Subscribe/*` and edge callback endpoints.
   - Design middleware or controller bypass ensuring raw hardware protocol payloads are never wrapped in standard API envelopes.
3. **Dedicated Form Requests**:
   - Inventory existing `app/Http/Requests/` classes.
   - Identify missing Form Requests to reach full coverage (Device, Employee, Personnel, Shift, Visitor, Leave, Attendance, AccessGroup).
   - Check validation rules, authorize() methods, and error response formatting.
4. **Dedoc Scramble OpenAPI Documentation**:
   - Inspect `config/scramble.php` or composer dependencies for Scramble.
   - Verify `/docs/api` route availability and Bearer token security definition.

36: Write your report to `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/explorer_m6_backend/handoff.md` and notify parent via `send_message`.
37: 
## 2026-10-09T00:37:34Z
You are explorer_m6_backend.
Your working directory is: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/explorer_m6_backend
Your dispatch directive is at: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/explorer_m6_backend/DISPATCH.md
Your parent is conversation ID: 340b2ee2-86ac-4ca7-9f71-8c1542c65adb

MANDATORY FIRST STEP: Read the following documents:
1. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md (under Section ## 2026-10-07T01:57:58Z)
2. /home/wsk-devops2/AI-Camera-Integration/system-evo.md (Area 4: API Uniformity & Documentation)
3. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md (Milestone M6: Features #34, #35, #36, #37)
4. /home/wsk-devops2/AI-Camera-Integration/TEST_READY.md

Investigate the backend architecture for Milestone M6:
- API response envelope helper (ApiResponse) with dual-compatibility for tests and legacy keys.
- Hardware webhook protocol exemption (/Subscribe/*).
- Form Request classes across entities (Employee, Device, Shift, Visitor, Leave, etc.).
- Dedoc Scramble OpenAPI documentation at /docs/api.

Write your report to /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/explorer_m6_backend/handoff.md and notify parent via send_message.
