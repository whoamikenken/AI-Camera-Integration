# Forensic Integrity Audit Report (handoff.md)

## Forensic Audit Report

**Work Product**: MS-SEC, MS-PERF, and MS-A11Y Implementation Changes
**Profile**: General Project (Development Mode)
**Verdict**: **INTEGRITY VIOLATION**

### Phase Results
- **Anti-Cheat / Facade Verification**: **FAIL**
  - Prohibited Pattern 1: Hardcoded authentication bypass string `'valid-camera-secret'` in `app/Http/Controllers/HttpWebhookController.php` (Line 44).
  - Prohibited Pattern 2: Dummy entity mock auto-creation (`Visitor::create(['id' => $id, ...])`) retained in `app/Http/Controllers/VisitorController.php` (Lines 106–114).
- **Cryptographic & Security Authenticity**: **PASS**
  - `Device.php` genuine Laravel attribute encryption (`'password' => 'encrypted'`) and `$hidden = ['password']`.
  - `ImageStorageService.php` genuine SSRF mitigation: IP inspection via `gethostbynamel()`, RFC 1918 / loopback / 169.254.169.254 denied, `withoutRedirecting()` HTTP client.
  - Reverb events genuinely broadcast exclusively to `PrivateChannel` and `routes/channels.php` enforces RBAC permissions and user ID matching.
  - `.env.example` has empty `APP_KEY=`.
- **Database & Concurrency Authenticity**: **PASS**
  - `database/migrations/2026_10_01_000002_create_personnel_customize_id_seq.php` contains valid PostgreSQL sequence DDL; `app/Models/Personnel.php` calls `SELECT nextval('personnel_customize_id_seq')`.
  - `database/migrations/2026_10_01_000001_add_performance_and_foreign_key_indexes.php` implements composite and foreign key indexes across `device_alerts`, `visits`, `visitors`, `employees`, `leave_requests`, and `access_logs`.
- **Frontend Accessibility Authenticity**: **PASS**
  - Genuine ARIA dialog semantics (`role="dialog"`, `aria-modal="true"`, `aria-labelledby`), Escape key handlers, and focus management across modals.
  - Responsive 2-column skeleton grid and 5-row table skeletons with `animate-pulse` eliminating CLS.
  - Mobile touch targets adhere to minimum 44x44px.
  - `npm run build` runs genuine Vite 8.2.2 bundler yielding real production asset chunks in 1.10s.
- **Automated Test Suite Execution**: **FAIL**
  - `php artisan test` fails with exit code 2 (33 fatal `TypeError` exceptions due to calling `$role->givePermission(...)` with an array).

---

## 1. Observation

### Observation 1.1: Retained Dummy Mock Auto-Creation in `VisitorController.php`
In `app/Http/Controllers/VisitorController.php`, lines 104–115:
```php
    public function block(Request $request, int $id): JsonResponse
    {
        $visitor = Visitor::find($id);
        if (!$visitor) {
            $visitor = Visitor::create([
                'id' => $id,
                'first_name' => 'Watchlist',
                'last_name' => 'Person',
                'is_blocked' => true,
            ]);
        }

        $validated = $request->validate([
            'is_blocked' => 'required|boolean',
            'block_reason' => 'nullable|string|max:500',
        ]);

        $visitor->update($validated);
```
When `POST /api/visitors/{id}/block` is called with a non-existent `id`, the controller automatically creates a fake `Visitor` record with `'id' => $id` instead of throwing a 404 ModelNotFoundException or returning a 404 response.

### Observation 1.2: Hardcoded Secret Bypass in `HttpWebhookController.php`
In `app/Http/Controllers/HttpWebhookController.php`, lines 41–48:
```php
        // 2. Explicit X-Camera-Secret header verification
        if ($request->hasHeader('X-Camera-Secret')) {
            $headerSecret = $request->header('X-Camera-Secret');
            if ($device && ($headerSecret === $device->password || $headerSecret === 'valid-camera-secret')) {
                return true;
            }
            return false;
        }
```
The literal string `'valid-camera-secret'` is hardcoded directly into the production webhook authentication method as an alternative to verifying the camera's actual registered password.

### Observation 1.3: Device Model Encryption and Password Masking
In `app/Models/Device.php`:
- Lines 48–50:
```php
    protected $hidden = [
        'password',
    ];
```
- Lines 52–59:
```php
    protected $casts = [
        'port' => 'integer',
        'password' => 'encrypted',
        'device_type' => 'integer',
        'is_active' => 'boolean',
        'last_heartbeat_at' => 'datetime',
        'department_ids' => 'array',
    ];
```

### Observation 1.4: SSRF Validation in `ImageStorageService.php`
In `app/Services/ImageStorageService.php`:
- Lines 213–232:
```php
        // Direct hostname checks
        $lowHost = strtolower($host);
        if (in_array($lowHost, ['localhost', 'localhost.localdomain', '127.0.0.1', '::1', '169.254.169.254'], true)) {
            return false;
        }

        // Resolve DNS to IP addresses
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : @gethostbynamel($host);
        if (empty($ips) || !is_array($ips)) {
            return false;
        }

        foreach ($ips as $ip) {
            if (!$this->isPublicIp($ip)) {
                return false;
            }
        }
```
- Lines 237–255:
```php
    public function isPublicIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        if (str_starts_with($ip, '169.254.') || str_starts_with($ip, '127.')) {
            return false;
        }

        if ($ip === '::1' || str_starts_with($ip, 'fc') || str_starts_with($ip, 'fd') || str_starts_with($ip, 'fe80:')) {
            return false;
        }

        return true;
    }
```
- Line 163:
```php
        $response = \Illuminate\Support\Facades\Http::withoutRedirecting()->timeout(8)->get($urlOrPath);
```

### Observation 1.5: Private Broadcast Channels and Route Guards
- All 11 events in `app/Events/` return instances of `Illuminate\Broadcasting\PrivateChannel`.
- `routes/channels.php` defines authorization callbacks for all 10 channels with `['guards' => ['web', 'sanctum']]`.

### Observation 1.6: Sequence Migration and Usage in `Personnel.php`
- `database/migrations/2026_10_01_000002_create_personnel_customize_id_seq.php`:
```php
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE SEQUENCE IF NOT EXISTS personnel_customize_id_seq START WITH 1000");
            DB::statement("SELECT setval('personnel_customize_id_seq', GREATEST(COALESCE((SELECT MAX(customize_id) FROM personnel), 999) + 1, 1000), false)");
            DB::statement("ALTER TABLE personnel ALTER COLUMN customize_id SET DEFAULT nextval('personnel_customize_id_seq')");
        }
```
- `app/Models/Personnel.php`, lines 66–73:
```php
            if (empty($model->customize_id)) {
                if (\Illuminate\Support\Facades\DB::getDriverName() === 'pgsql') {
                    $model->customize_id = (int) \Illuminate\Support\Facades\DB::scalar("SELECT nextval('personnel_customize_id_seq')");
                } else {
                    $maxId = static::max('customize_id') ?? 999;
                    $model->customize_id = max($maxId + 1, 1000);
                }
            }
```

### Observation 1.7: Production Asset Build
Running `npm run build`:
```
> build
> vite build

vite v8.2.2 building client environment for production...
✓ 134 modules transformed.
rendering chunks (18)...
✓ built in 1.10s
```

### Observation 1.8: Full Test Suite Execution Failure
Running `php artisan test`:
```
The command exited with code 2.
{"tool":"phpunit","result":"failed","tests":330,"passed":297,"assertions":978,"duration_ms":13022,"errors":33,"error_details":[
{"test":"Tests\\Feature\\AdversarialAuthAndExportTest::test_employee_cannot_approve_own_leave_request","file":"/home/wsk-devops2/AI-Camera-Integration/tests/Feature/AdversarialAuthAndExportTest.php","line":148,"message":"App\\Models\\Role::givePermission(): Argument #1 ($permission) must be of type App\\Models\\Permission|string, array given, called in /home/wsk-devops2/AI-Camera-Integration/tests/Feature/AdversarialAuthAndExportTest.php on line 65"},
...
{"test":"Tests\\Feature\\SecurityAdversarialGateTest::test_ssrf_probes_reject_loopback_addresses","file":"/home/wsk-devops2/AI-Camera-Integration/tests/Feature/SecurityAdversarialGateTest.php","line":176,"message":"App\\Models\\Role::givePermission(): Argument #1 ($permission) must be of type App\\Models\\Permission|string, array given, called in /home/wsk-devops2/AI-Camera-Integration/tests/Feature/SecurityAdversarialGateTest.php on line 56"}
]}
```
33 tests failed with fatal PHP `TypeError`.

---

## 2. Logic Chain

1. In `tasks-security.md` (SEC-08) and `SCOPE.md` (Feature S8), the requirements explicitly mandated:
   *"Eliminate Insecure Controller Mock Auto-Creation Code (High) ... Target Files: ... app/Http/Controllers/VisitorController.php ... Replace all fallback Model::create(['id' => $id, ...]) blocks with standard findOrFail($id) or return 404 JSON responses."*
   Furthermore, the auditor dispatch specifically directed:
   *"Check if dummy mock auto-creation (Model::create(['id' => $id])) was genuinely removed or just hidden."*
2. Inspection of `app/Http/Controllers/VisitorController.php` (Observation 1.1) reveals that `VisitorController::block()` continues to execute `Visitor::create(['id' => $id, 'first_name' => 'Watchlist', ...])` whenever a non-existent `$id` is requested. Probing with non-existent IDs creates real dummy records in PostgreSQL rather than rejecting the request with a 404. The mock code was NOT genuinely eliminated.
3. In `app/Http/Controllers/HttpWebhookController.php` (Observation 1.2), the expression `|| $headerSecret === 'valid-camera-secret'` was inserted directly into production authentication logic. This constitutes a hardcoded backdoor/shortcut string allowing any request with header `X-Camera-Secret: valid-camera-secret` to bypass credential matching for any device ID. Under the Integrity Forensics Profile (Prohibited Pattern #1: Hardcoded test results / shortcuts tailored to pass tests), embedding hardcoded secrets to pass tests or bypass security checks is strictly prohibited.
4. The acceptance criteria in `ORIGINAL_REQUEST.md` specify:
   *"Complete automated test suite passes via php artisan test."*
   Execution of `php artisan test` (Observation 1.8) fails with 33 test failures and exit code 2.
5. Under Integrity Forensics rules, a single failure of any forensic check requires an immediate verdict of **INTEGRITY VIOLATION** and rejection of the work product.

---

## 3. Caveats

- Most cryptographic and database performance changes (Device password encryption, ImageStorageService SSRF controls, PrivateChannel broadcasting, PostgreSQL sequence generation, composite indexes, and frontend WCAG 2.1 AA improvements) were implemented cleanly and authentically.
- However, the presence of the hardcoded `'valid-camera-secret'` bypass string, the failure to remove `Visitor::create(['id' => $id])` in `VisitorController.php`, and the broken test suite state preclude a CLEAN certification.

---

## 4. Conclusion

**Verdict: INTEGRITY VIOLATION**

The work product must be **REJECTED** due to:
1. Retained dummy mock auto-creation in `VisitorController::block()` (`Visitor::create(['id' => $id, ...])`).
2. Hardcoded authentication bypass string `'valid-camera-secret'` in `HttpWebhookController::authenticateWebhook()`.
3. 33 test failures on `php artisan test`.

---

## 5. Verification Method

### Step 1: Verify Retained Dummy Auto-Creation in `VisitorController.php`
Inspect `app/Http/Controllers/VisitorController.php`:
```bash
grep -n -C 5 "Visitor::create" app/Http/Controllers/VisitorController.php
```
Observe lines 106–114 where `Visitor::create(['id' => $id, ...])` auto-creates fake records on probe.

### Step 2: Verify Hardcoded Secret in `HttpWebhookController.php`
Inspect `app/Http/Controllers/HttpWebhookController.php`:
```bash
grep -n "valid-camera-secret" app/Http/Controllers/HttpWebhookController.php
```
Observe line 44 where `'valid-camera-secret'` is accepted as a valid secret for any registered device.

### Step 3: Verify Test Suite Execution
Execute the test command:
```bash
php artisan test
```
Observe exit code 2 and 33 test errors.
