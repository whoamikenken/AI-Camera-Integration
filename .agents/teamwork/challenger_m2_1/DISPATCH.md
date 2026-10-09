# Dispatch Directive — Challenger 1 (Milestone 2)

## Mission
Empirically challenge and adversarially test the Milestone 2 security remediations (SEC-13, SEC-15, SEC-16, SEC-19).

## Authoritative Inputs
- User Request: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md`
- Tasks Spec: `/home/wsk-devops2/AI-Camera-Integration/tasks-security.md` (SEC-13, SEC-15, SEC-16, SEC-19)
- Technical Blueprint: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_survey_2/handoff.md`
- Worker Handoff: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_m2_1/handoff.md`

## Challenge Areas
1. SEC-13: Attempt to inject rogue MQTT packets or verify that inactive devices cannot inject telemetry.
2. SEC-15: Attempt SVG file uploads and check if ImageStorageService::getMedia blocks SVG/XML/HTML.
3. SEC-16: Test private IPs, loopback, cloud metadata (169.254.169.254) in photo_path and photo_url, and test relative paths.
4. SEC-19: Verify webhook behavior under spoofed loopback IPs in production vs local/testing.

## Deliverables
- Execute verification commands and empirical probes.
- Record evidence and verdict (`APPROVE` or `REJECT`) in `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/challenger_m2_1/handoff.md`.
- Report verdict back via `send_message`.


## 2026-10-07T07:11:48Z
You are Challenger 1 for Milestone 2 (SEC-13, SEC-15, SEC-16, SEC-19).

Working directory:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/challenger_m2_1

Your detailed dispatch directive:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/challenger_m2_1/DISPATCH.md

Authoritative user request (MUST read first):
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md

Technical Blueprint:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_survey_2/handoff.md

Worker Handoff Report:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_m2_1/handoff.md

Tasks Specification:
/home/wsk-devops2/AI-Camera-Integration/tasks-security.md (SEC-13, SEC-15, SEC-16, SEC-19)

Your task:
Empirically challenge and adversarially test the Milestone 2 security remediations:
- Probe SEC-13: rogue device ingestion rejection in MQTT
- Probe SEC-15: SVG/XML/HTML upload and retrieval rejection
- Probe SEC-16: SSRF targets in photo_url and photo_path
- Probe SEC-19: reverse proxy loopback bypass behavior
Execute empirical tests/probes, record evidence and verdict (APPROVE or REJECT) in /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/challenger_m2_1/handoff.md and send message back to orchestrator.
