# DISPATCH DIRECTIVE — Remediation Explorer 2 (M3 Iteration 2: Visitor KPI & Filters)

## Identity & Role
- **Agent**: `teamwork_preview_explorer_m3_iter2_2`
- **Archetype**: `teamwork_preview_explorer`
- **Role**: Visitor KPI Stats & UI Filter Explorer for Milestone M3
- **Working Directory**: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_m3_iter2_2`
- **Parent Conversation ID**: `340b2ee2-86ac-4ca7-9f71-8c1542c65adb`

## Mandatory Reference Documents
1. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md` (authoritative user request)
2. `/home/wsk-devops2/AI-Camera-Integration/system-evo.md` (Feature 2)
3. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md`
4. Reviewer 2 Handoff: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_reviewer_m3_11_2/handoff.md`

## Problem to Investigate
In `resources/js/stores/visitorStore.js` and `resources/js/components/visitors/VisitorDashboard.vue`:
1. `computeVisitorStats()` only counts items in `this.visits` (the current paginated page of 15 items), causing KPI cards (`Expected Today`, `Currently On-Site`, `Overstay Alert`, `Checked Out`) to fluctuate when pagination changes.
2. In `VisitorDashboard.vue`, the status filter dropdown lacks a `no_show` option, and `no_show` lacks an explicit status badge style.

## Investigation Objective
1. Inspect `app/Http/Controllers/VisitorController.php` (specifically `listVisits` or `stats` endpoint, if any).
2. Check if `listVisits` can include aggregate counts in response metadata (e.g., `meta.stats` or similar) or if a stats endpoint exists.
3. Inspect `resources/js/stores/visitorStore.js` and `resources/js/components/visitors/VisitorDashboard.vue` (and `resources/js/views/VisitorDashboard.vue`).
4. Design the exact solution to:
   - Provide accurate facility-wide KPI statistics.
   - Add `<option value="no_show">No Show</option>` and badge style in `VisitorDashboard.vue`.
5. Ensure zero regressions in existing tests and that `npm run build` succeeds cleanly.

Write report to `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_m3_iter2_2/handoff.md` and notify parent via `send_message`.


## 2026-10-08T06:57:39Z
You are teamwork_preview_explorer_m3_iter2_2.
Your working directory is: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_m3_iter2_2
Your dispatch directive is at: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_m3_iter2_2/DISPATCH.md
Your parent is conversation ID: 340b2ee2-86ac-4ca7-9f71-8c1542c65adb

MANDATORY FIRST STEP: Read the following documents:
1. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md
2. /home/wsk-devops2/AI-Camera-Integration/system-evo.md
3. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md
4. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_reviewer_m3_11_2/handoff.md

Investigate client-side pagination skew in visitorStore.computeVisitorStats() and missing no_show filter/badge in VisitorDashboard.vue.
Design an aggregate statistics solution and filter/badge improvements.

Write your report to /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_m3_iter2_2/handoff.md and notify parent via send_message.
