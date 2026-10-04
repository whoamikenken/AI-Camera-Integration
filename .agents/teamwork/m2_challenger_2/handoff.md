# Milestone 2: Adversarial Shift Scheduling & Calendar Stress Testing — Challenge Report

## Authoritative Verdict: REQUEST_CHANGES

---

## 1. Observation

### Test Execution Command & Verbatim Output
Command executed:
```bash
php artisan test tests/Feature/AdversarialShiftAndHolidayTest.php
```

Output:
```json
{"tool":"phpunit","result":"failed","tests":27,"passed":24,"assertions":94,"duration_ms":1981,"failed":3,"failures":[{"test":"Tests\\Feature\\AdversarialShiftAndHolidayTest::test_adversarial_defect_flexible_shift_persisted_in_db_ignores_min_hours_full_day","file":"/home/wsk-devops2/AI-Camera-Integration/tests/Feature/AdversarialShiftAndHolidayTest.php","line":242,"message":"DEFECT: Persisted flexible shift durationMinutes() ignores min_hours_full_day because shift_start is non-null.\nFailed asserting that 540 is identical to 360."},{"test":"Tests\\Feature\\AdversarialShiftAndHolidayTest::test_adversarial_defect_shift_resolution_fails_on_exact_effective_from_date","file":"/home/wsk-devops2/AI-Camera-Integration/tests/Feature/AdversarialShiftAndHolidayTest.php","line":391,"message":"DEFECT: currentShift() fails on exact effective_from date because of missing whereDate() comparison.\nFailed asserting that 1 is identical to 2."},{"test":"Tests\\Feature\\AdversarialShiftAndHolidayTest::test_adversarial_defect_short_day_abbreviations_fail_in_current_shift_and_is_rest_day","file":"/home/wsk-devops2/AI-Camera-Integration/tests/Feature/AdversarialShiftAndHolidayTest.php","line":562,"message":"DEFECT: isRestDay() fails on short day abbreviation [\"Mon\"] and treats Monday as a Rest Day.\nFailed asserting that true is false."}]}
```

### Observed Defect 1: Shift Resolution Fails on Exact `effective_from` Date
- **Location**: `app/Models/Employee.php:143`, `app/Models/Employee.php:218`, `app/Models/EmployeeShiftAssignment.php:63`.
- **Code Snippet**:
  In `Employee::currentShift()`:
  ```php
  143: ->where('effective_from', '<=', $dateStr)
  144: ->where(function ($q) use ($dateStr) {
  145:     $q->whereNull('effective_to')
  146:       ->orWhere('effective_to', '>=', $dateStr);
  147: })
  ```
  In `Employee::isRestDay()`:
  ```php
  218: ->where('effective_from', '<=', $dateStr)
  ```
  In `EmployeeShiftAssignment::scopeActiveOn()`:
  ```php
  63: return $query->where('effective_from', '<=', $dateStr)
  ```
- **Direct Empirical Verification**:
  Direct SQL inspection reveals:
  ```json
  [{"raw_comp":false,"date_comp":true}]
  ```
  When `effective_from` is cast to `date` in `EmployeeShiftAssignment`, Eloquent stores `'2026-06-01 00:00:00'`. Comparing `'2026-06-01 00:00:00' <= '2026-06-01'` evaluates to `false` in string comparison due to character length and space at index 10. Consequently, on the exact effective date of an assignment, `currentShift()` does not match the active assignment and falls back to default shift.

### Observed Defect 2: Missing `$dayShort` in `Employee::currentShift()` and `Employee::isRestDay()`
- **Location**: `app/Models/Employee.php:161-163`, `app/Models/Employee.php:238-240`.
- **Code Snippet**:
  In `EmployeeShiftAssignment::appliesToDay()`:
  ```php
  36: $dayName = strtolower($carbon->format('l')); // 'monday'
  37: $dayShort = strtolower($carbon->format('D')); // 'mon'
  ...
  49: if ($dl === $dayName || $dl === $dayShort) { return true; }
  ```
  In `Employee::currentShift()`:
  ```php
  136: $dayName = strtolower($carbon->format('l'));
  137: $dayIso = $carbon->dayOfWeekIso;
  138: $dayNum = $carbon->dayOfWeek;
  ...
  162: if (in_array($dayName, $days, true) || in_array($dayIso, $days, true) || in_array($dayNum, $days, true)) {
  ```
  In `Employee::isRestDay()`:
  ```php
  238: if (is_string($d) && strtolower($d) === $dayName) {
  239:     return false; // Scheduled work day
  240: }
  ```
- **Direct Empirical Verification**:
  When `assigned_days` contains `['Mon', 'Wed', 'Fri']`:
  - `EmployeeShiftAssignment::appliesToDay('2026-10-05')` returns `true`.
  - `Employee::currentShift('2026-10-05')` fails to match the assignment because `'mon'` is not in `['monday']`.
  - `Employee::isRestDay('2026-10-05')` returns `true` (falsely classifying Monday as a rest day).

### Observed Defect 3: Persisted Flexible Shifts Ignore `min_hours_full_day`
- **Location**: `app/Models/Shift.php:79-82`, `database/migrations/2026_09_30_000013_create_shifts_table.php:16-17`, `app/Http/Controllers/ShiftController.php:54-55`.
- **Code Snippet**:
  In `Shift::durationMinutes()`:
  ```php
  79: if ($this->is_flexible && (!$this->shift_start || !$this->shift_end)) {
  80:     $totalMin = (int) (($this->min_hours_full_day ?? 8.0) * 60);
  81:     return $netOfBreak ? max(0, $totalMin - ($this->break_duration_minutes ?? 0)) : $totalMin;
  82: }
  ```
- **Direct Empirical Verification**:
  Migration `2026_09_30_000013_create_shifts_table.php` defines:
  `$table->time('shift_start')->default('09:00:00');`
  `$table->time('shift_end')->default('18:00:00');`
  `ShiftController::store` validates `'shift_start' => 'required'`, `'shift_end' => 'required'`.
  Because `shift_start` and `shift_end` are never null in database records, `(!$this->shift_start || !$this->shift_end)` evaluates to `false` for every persisted flexible shift. `durationMinutes()` falls through and calculates 540 minutes (start to end difference) instead of `min_hours_full_day * 60` (360 minutes for a 6.0 hour shift).

### Robust Features Observed (24 Passing Tests)
1. **Overnight Shift Calculations Across Midnight Boundary**:
   - Graveyard shift (22:00 to 07:00): Gross duration = 540 minutes, Net duration (60m break) = 480 minutes. `crossesMidnight()` = true, `isOvernight()` = true, `isDayShift()` = false.
   - Fractional overnight shift (23:45 to 08:15): Gross duration = 510 minutes, Net duration (45m break) = 465 minutes.
   - Implicit midnight shift (20:30 to 04:30 with `is_overnight = false`): auto-detects cross-midnight condition; gross duration = 480 minutes, net = 450 minutes (never negative).
2. **Break Duration Boundary & Negative Protection**:
   - When break duration (300 min) exceeds total shift duration (240 min), `durationMinutes(true)` clamps to 0 and never returns negative values.
3. **Shift Validation Guardrails**:
   - `POST /api/shifts` with identical start and end times for non-overnight shift is rejected with HTTP 422.
   - `POST /api/shifts` with identical start and end times for overnight shift (`is_overnight: true`) is accepted with HTTP 201 representing a 24-hour shift (1440 minutes).
   - Malformed time strings (`25:00`, `17:60`, non-numeric) are rejected with HTTP 422.
4. **Shift Assignment Rotation Capping**:
   - Assigning a new shift starting on date $D$ to an employee with an open-ended assignment (`effective_to = null`) successfully updates the previous assignment's `effective_to` to $D - 1\text{ day}$.
   - Nested date ranges (e.g. temporary summer peak shift overlay) prioritize the higher `effective_from` assignment during the window and revert to the underlying annual shift after the overlay expires.
5. **Days of Week Filtering**:
   - ISO codes (`[1, 2, 3, 4, 5]`) and full string names (`['Saturday', 'Sunday']`) correctly delineate scheduled working days from rest days.
6. **Bulk Shift Assignment Scoping**:
   - `POST /api/shifts/bulk-assign` correctly scopes to target departments and updates rotation capping for departmental employees only without altering employees in other departments.
7. **Holiday Calendar Edge Cases**:
   - Leap day recurring holiday (`2024-02-29`, `is_recurring: true`) matches on 2024-02-29 and 2028-02-29 (leap years). It evaluates to `false` on non-leap years (`2025-02-28`, `2025-03-01`, `2026-02-28`).
   - Standard recurring holiday (`2024-12-25`) recurs across subsequent years.
   - Department scoping (`applies_to` as structured dictionary or raw list), location scoping, and cross-organization isolation are strictly enforced.
8. **RBAC Authorization**:
   - Unauthenticated requests to `POST /api/shifts` and `POST /api/holidays` receive HTTP 401.
   - Unprivileged users (role `employee`) attempting `POST`, `PUT`, `DELETE`, or assignment actions on `/api/shifts`, `/api/holidays`, or `/api/employees/{id}/assign-shift` receive HTTP 403.
   - Privileged HR Managers with `schedules.manage` receive HTTP 201.

---

## 2. Logic Chain

1. **Premise**: In access control and attendance scheduling systems, date boundary conditions and schedule contracts must be precise. If a shift assignment starts on `2026-06-01`, an employee arriving on `2026-06-01` must be evaluated against that shift.
2. **Inference for Defect 1**:
   - `app/Models/Employee.php:143` runs `where('effective_from', '<=', $dateStr)`.
   - In SQLite environments (and any string-based comparison), date fields cast with timestamp format `'YYYY-MM-DD HH:MM:SS'` evaluate `'2026-06-01 00:00:00' <= '2026-06-01'` to `false`.
   - Because this comparison returns `false`, `Employee::currentShift('2026-06-01')` fails to retrieve the assigned shift, and falls back to default.
   - Changing the query to `whereDate('effective_from', '<=', $dateStr)` causes SQL date truncation/casting, correctly evaluating to `true`.
3. **Inference for Defect 2**:
   - `EmployeeShiftAssignment::appliesToDay()` was authored to accept 3-letter abbreviations (`'Mon'`, `'Tue'`, `'Wed'`).
   - `Employee::currentShift()` and `Employee::isRestDay()` did not replicate `$dayShort` logic, only checking `$dayName` (`'monday'`) and numeric IDs.
   - Passing `['Mon', 'Wed', 'Fri']` causes `appliesToDay()` to return `true`, while `currentShift()` fails to match and `isRestDay()` flags Monday as a rest day.
4. **Inference for Defect 3**:
   - The contract for flexible shifts (`is_flexible = true`) dictates that working hours are governed by `min_hours_full_day` rather than rigid start and end times.
   - In `Shift::durationMinutes()`, the flexible branch requires `(!$this->shift_start || !$this->shift_end)`.
   - Because the database migration provides non-null default times (`09:00:00` and `18:00:00`) and the API requires both parameters, persisted flexible shifts never satisfy this condition.
   - `durationMinutes()` computes the start-to-end difference, rendering `min_hours_full_day` inert for persisted flexible shifts.
5. **Conclusion**:
   - While 24 out of 27 adversarial tests pass and confirm robust overnight calculations, break deductions, leap-year recurrence, and RBAC enforcement, the 3 defects directly threaten the integrity of Milestone 3's biometric attendance processing engine. Therefore, `REQUEST_CHANGES` is the required verdict.

---

## 3. Caveats

- **Database Engine Variance**:
  - Defect 1 directly manifests in SQLite test environments and database drivers performing string comparisons on datetime columns. In PostgreSQL with strict `DATE` column casting, date comparison behavior depends on whether the parameter is passed as a string or bound as a typed date. Using `whereDate` eliminates dialect variance across all engines.
- **Review-Only Constraint**:
  - Per empirical challenger constraints, no production code fixes were applied. The defects are reproduced via the committed test file `tests/Feature/AdversarialShiftAndHolidayTest.php`.

---

## 4. Conclusion

**Authoritative Verdict**: `REQUEST_CHANGES`

Three concrete defects were discovered and empirically reproduced:
1. **Defect 1 (CRITICAL)**: `Employee::currentShift()`, `Employee::isRestDay()`, and `EmployeeShiftAssignment::scopeActiveOn()` fail to resolve shifts on the exact `effective_from` date due to missing `whereDate()`.
2. **Defect 2 (HIGH)**: `Employee::currentShift()` and `Employee::isRestDay()` omit `$dayShort` (`'Mon'`, `'Tue'`, etc.), causing short day code assignments to fail resolution and treat scheduled workdays as rest days.
3. **Defect 3 (MEDIUM)**: `Shift::durationMinutes()` ignores `min_hours_full_day` on persisted flexible shifts because non-null database defaults bypass the flexible calculation branch.

Remediations required from the implementation worker:
1. In `app/Models/Employee.php` (lines 143, 146, 218, 221) and `app/Models/EmployeeShiftAssignment.php` (lines 63, 66), change `where('effective_from', '<=', ...)` and `orWhere('effective_to', '>=', ...)` to `whereDate(...)`.
2. In `app/Models/Employee.php` (lines 136-164 and 215-241), extract `$dayShort = strtolower($carbon->format('D'))` and check `$dayShort` alongside `$dayName`.
3. In `app/Models/Shift.php` (lines 79-82), update `durationMinutes()` so that `if ($this->is_flexible)` directly computes `(int) (($this->min_hours_full_day ?? 8.0) * 60)` regardless of dummy start/end times.

---

## 5. Verification Method

To independently verify this report and reproduce all 3 defects:

```bash
php artisan test tests/Feature/AdversarialShiftAndHolidayTest.php
```

Expected result:
- Tests: 27 total
- Passed: 24 passed (94 assertions)
- Failed: 3 failed:
  1. `test_adversarial_defect_flexible_shift_persisted_in_db_ignores_min_hours_full_day`
  2. `test_adversarial_defect_shift_resolution_fails_on_exact_effective_from_date`
  3. `test_adversarial_defect_short_day_abbreviations_fail_in_current_shift_and_is_rest_day`

To verify individual defect reproduction:
```bash
# Defect 1:
php artisan test tests/Feature/AdversarialShiftAndHolidayTest.php --filter=test_adversarial_defect_shift_resolution_fails_on_exact_effective_from_date

# Defect 2:
php artisan test tests/Feature/AdversarialShiftAndHolidayTest.php --filter=test_adversarial_defect_short_day_abbreviations_fail_in_current_shift_and_is_rest_day

# Defect 3:
php artisan test tests/Feature/AdversarialShiftAndHolidayTest.php --filter=test_adversarial_defect_flexible_shift_persisted_in_db_ignores_min_hours_full_day
```
