# Task Assignment: Milestone 1 — Challenger 2 (Adversarial Data Integrity & Concurrency Verification)

## Identity & Context
- Agent: teamwork_preview_challenger
- Working Directory: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/m1_challenger_2
- Parent Orchestrator: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator

## Objective
Empirically challenge and adversarially stress-test the data integrity and business logic of Milestone 1:
- /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md
- /home/wsk-devops2/AI-Camera-Integration/PROJECT.md
- /home/wsk-devops2/AI-Camera-Integration/TEST_INFRA.md
- /home/wsk-devops2/AI-Camera-Integration/TEST_READY.md

Write stress tests, generators, or custom test scripts to verify:
1. Department Tree Cycle Attacks: Attempt to create circular parent loops (A -> B -> C -> A, or setting self as parent) and verify that `OrganizationController` rejects them with HTTP 422.
2. Settings Concurrent Update & Cache Invalidation: Test rapid updates to settings keys, verifying that Redis/Cache keys invalidate immediately and subsequent `SettingService::get()` calls reflect updated values without stale reads.
3. Audit Log Tamper & Sensitive Data Leaks: Verify that updating user password, remember_token, or sensitive fields does NOT leak plaintext passwords into `audit_logs.old_values` or `new_values`.
4. Multi-Tenant Location & Department Scoping: Verify that locations and departments belonging to Org A cannot be assigned or attached to Org B via API requests.
5. Device Role / Direction Edge Cases: Verify that setting invalid device roles (e.g. 'invalid_role') fails validation, while valid roles ('entry', 'exit', 'bidirectional', 'visitor_kiosk') succeed.

Execute tests and write a comprehensive challenge report to `challenge_report.md` and handoff report to `handoff.md` with verdict **APPROVE** or **REQUEST_CHANGES**. Notify parent when complete.

## 2026-09-29T16:18:44Z
[Message] sender=ef2bd8f1-92a2-4f35-b75e-cfb5ce7f56ae priority=MESSAGE_PRIORITY_HIGH
You are assigned as Challenger 2 for Milestone 1: Security Foundation, RBAC & Multi-Tenant Settings.

Your working directory is:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/m1_challenger_2

Read your instructions in DISPATCH.md and review:
1. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md
2. /home/wsk-devops2/AI-Camera-Integration/PROJECT.md
3. /home/wsk-devops2/AI-Camera-Integration/TEST_INFRA.md
4. /home/wsk-devops2/AI-Camera-Integration/TEST_READY.md

Conduct adversarial stress tests on:
- Department tree circular hierarchy loops
- Settings cache invalidation and concurrent updates
- Audit log sensitive data leak prevention (passwords, tokens)
- Multi-tenant organization scoping
- Invalid device role validation

Deliver verdict APPROVE or REQUEST_CHANGES in handoff.md. Send completion message when done.
