# Dispatch Directive — Challenger 2 (Milestone 2)

## Mission
Stress-test and probe edge cases in Milestone 2 security remediations (SEC-13, SEC-15, SEC-16, SEC-19).

## Authoritative Inputs
- User Request: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md`
- Tasks Spec: `/home/wsk-devops2/AI-Camera-Integration/tasks-security.md` (SEC-13, SEC-15, SEC-16, SEC-19)
- Technical Blueprint: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_survey_2/handoff.md`
- Worker Handoff: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_m2_1/handoff.md`

## Challenge Areas
1. SEC-13: Test MqttListenCommand behavior with various payloads (`VerifyPush`, `StrSnapPush`, `HeartBeat`, `Online`) for unregistered, active, and deactivated devices.
2. SEC-15: Test image MIME type restrictions (JPEG, PNG, WebP vs SVG, XML, HTML, polyglots).
3. SEC-16: Test SSRF evasions (IPv6 representations, encoded characters, DNS resolution targets if applicable) on `photo_path` and `photo_url`.
4. SEC-19: Test reverse proxy trust headers and webhook client IP resolution.

## Deliverables
- Execute verification commands and empirical probes.
- Record evidence and verdict (`APPROVE` or `REJECT`) in `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/challenger_m2_2/handoff.md`.
- Report verdict back via `send_message`.

## 2026-10-07T07:11:48Z
[Message from 71aec755-ccf7-4da2-9035-e66085685b0c]
You are Challenger 2 for Milestone 2 (SEC-13, SEC-15, SEC-16, SEC-19).

Working directory:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/challenger_m2_2

Your detailed dispatch directive:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/challenger_m2_2/DISPATCH.md

Authoritative user request (MUST read first):
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md

Technical Blueprint:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_survey_2/handoff.md

Worker Handoff Report:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_m2_1/handoff.md

Tasks Specification:
/home/wsk-devops2/AI-Camera-Integration/tasks-security.md (SEC-13, SEC-15, SEC-16, SEC-19)

Your task:
Stress-test and probe edge cases in Milestone 2:
- Test MQTT listener with unexpected payloads and device states
- Test image upload MIME type bypass attempts
- Test SSRF variations and bypass attempts on photo_path
- Test webhook proxy headers
Execute verification commands, record evidence and verdict (APPROVE or REJECT) in /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/challenger_m2_2/handoff.md and send message back to orchestrator.
