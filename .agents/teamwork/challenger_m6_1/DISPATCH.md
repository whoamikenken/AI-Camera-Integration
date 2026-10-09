# DISPATCH DIRECTIVE — challenger_m6_1 (API Envelope & Form Request Challenger)

## Mission
Empirically challenge and stress-test the API response envelope and Form Request layer for Milestone M6:
1. Challenge `ApiResponse`:
   - Verify pagination responses retain top-level `current_page`, `data`, `total`, `per_page`, `last_page` while wrapping `data` and `meta.pagination`.
   - Verify single-model success responses retain root-level model attributes (`id`, `name`, etc.) for backward compatibility with legacy test assertions.
   - Verify error responses retain root-level `'errors'` array/object for `assertJsonValidationErrors` compatibility.
2. Challenge Hardware Webhook Endpoints:
   - Call `/Subscribe/Verify` and `/api/Subscribe/Verify` to verify responses strictly return raw `{code: 200, desc: "OK"}` without `ApiResponse` wrapping.
3. Challenge Form Requests:
   - Verify that invalid payloads are rejected with HTTP 422 Unprocessable Entity.
   - Verify that valid payloads pass validation and reach the service/controller layer cleanly.

## Mandatory References
1. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md` (under `## 2026-10-07T01:57:58Z`)
2. `/home/wsk-devops2/AI-Camera-Integration/system-evo.md` (Area 4: API Uniformity & Documentation)
3. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md` (Milestone M6)
4. Worker Handoff: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_m6_1_rep/handoff.md`

## Verification Commands
Run the following test suites:
- `php artisan test --filter="test_f3[4-7]"`
- `php artisan test --filter="Tier1FeatureCoverageTest"`
- `php artisan test --filter="test_boundary_api|test_cross"`

## Reporting
Write your challenge findings and explicit verdict (`APPROVE` or `REQUEST_CHANGES`) to:
`/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/challenger_m6_1/handoff.md`
Notify parent (`2af1d024-aed2-4512-af6e-93c099256b99`) via `send_message`.


## 2026-10-09T04:53:43Z
You are API Envelope & Form Request Challenger (challenger_m6_1) for Milestone M6.
Your working directory is: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/challenger_m6_1
Read /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/challenger_m6_1/DISPATCH.md
Read /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_m6_1_rep/handoff.md
Read ORIGINAL_REQUEST.md, system-evo.md, and PROJECT.md.

Empirically challenge:
1. ApiResponse envelope: paginators retain root keys while wrapping data, models retain root attributes, errors retain root errors.
2. Hardware webhook endpoints: /Subscribe/* strictly return raw Protocol V1.13 without envelope.
3. Form Requests: reject invalid payloads with 422, pass valid payloads cleanly.

Execute and verify tests:
- php artisan test --filter="test_f3[4-7]"
- php artisan test --filter="Tier1FeatureCoverageTest"
- php artisan test --filter="test_boundary_api|test_cross"

Write your findings and verdict (APPROVE or REQUEST_CHANGES) to:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/challenger_m6_1/handoff.md
Notify parent (2af1d024-aed2-4512-af6e-93c099256b99) via send_message.
