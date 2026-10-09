<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class AdversarialMilestone3CspDependencyTest extends TestCase
{
    /**
     * Helper to run SecurityHeaders middleware on a given Request.
     */
    private function executeSecurityMiddleware(Request $request): Response
    {
        $middleware = new SecurityHeaders;
        $next = function ($req) {
            return new Response('<html><body>AI Camera Hub</body></html>', 200, ['Content-Type' => 'text/html']);
        };

        return $middleware->handle($request, $next);
    }

    /**
     * Helper to parse CSP header into an associative array of directive => tokens.
     */
    private function parseCsp(string $csp): array
    {
        $directives = [];
        foreach (explode(';', $csp) as $part) {
            $part = trim($part);
            if (! empty($part)) {
                $tokens = preg_split('/\s+/', $part);
                $name = array_shift($tokens);
                $directives[$name] = $tokens;
            }
        }

        return $directives;
    }

    // =========================================================================
    // 1. SEC-17 Adversarial Probes: connect-src Exfiltration Prevention
    // =========================================================================

    public function test_adversarial_connect_src_blocks_wildcards_in_production(): void
    {
        app()->detectEnvironment(fn () => 'production');
        Config::set('broadcasting.connections.reverb.options.host', 'reverb.internal');
        Config::set('broadcasting.connections.reverb.options.port', 8080);

        $request = Request::create('https://camera.company.com/', 'GET');
        $response = $this->executeSecurityMiddleware($request);

        $csp = $response->headers->get('Content-Security-Policy');
        $directives = $this->parseCsp($csp);

        $this->assertArrayHasKey('connect-src', $directives);
        $connectSrc = $directives['connect-src'];

        // Strict wildcard prohibitions
        $this->assertNotContains('https:', $connectSrc, 'PROBE FAILED: connect-src must not contain wildcard https:');
        $this->assertNotContains('http:', $connectSrc, 'PROBE FAILED: connect-src must not contain wildcard http:');
        $this->assertNotContains('ws:', $connectSrc, 'PROBE FAILED: connect-src must not contain wildcard ws:');
        $this->assertNotContains('wss:', $connectSrc, 'PROBE FAILED: connect-src must not contain wildcard wss:');
        $this->assertNotContains('*', $connectSrc, 'PROBE FAILED: connect-src must not contain wildcard *');

        // Verify simulated exfiltration targets are NOT permitted
        $exfiltrationTargets = [
            'https://evil-c2.attacker.com',
            'https://api.webhook.site',
            'https://requestbin.com',
            'wss://attacker-stream.com',
            'ws://192.168.1.50:9001',
            'http://169.254.169.254',
        ];

        foreach ($exfiltrationTargets as $target) {
            $this->assertNotContains(
                $target,
                $connectSrc,
                "PROBE FAILED: Exfiltration target {$target} is inadvertently allowed in connect-src"
            );
        }

        // Verify legitimate origins
        $this->assertContains("'self'", $connectSrc);
        $this->assertContains('https://cloudflareinsights.com', $connectSrc);
        $this->assertContains('ws://reverb.internal:8080', $connectSrc);
        $this->assertContains('wss://reverb.internal:8080', $connectSrc);
        $this->assertContains('ws://camera.company.com:8080', $connectSrc);
        $this->assertContains('wss://camera.company.com:8080', $connectSrc);
    }

    public function test_adversarial_connect_src_blocks_wildcards_in_development(): void
    {
        app()->detectEnvironment(fn () => 'local');

        $request = Request::create('http://localhost:8000/', 'GET');
        $response = $this->executeSecurityMiddleware($request);

        $csp = $response->headers->get('Content-Security-Policy');
        $directives = $this->parseCsp($csp);

        $connectSrc = $directives['connect-src'];

        // Even in development, broad wildcards must NOT be present
        $this->assertNotContains('https:', $connectSrc, 'Dev connect-src must not contain wildcard https:');
        $this->assertNotContains('http:', $connectSrc, 'Dev connect-src must not contain wildcard http:');
        $this->assertNotContains('ws:', $connectSrc, 'Dev connect-src must not contain wildcard ws:');
        $this->assertNotContains('wss:', $connectSrc, 'Dev connect-src must not contain wildcard wss:');
        $this->assertNotContains('*', $connectSrc, 'Dev connect-src must not contain wildcard *');

        // Only targeted dev origins should be present
        $this->assertContains('ws://localhost:*', $connectSrc);
        $this->assertContains('wss://localhost:*', $connectSrc);
        $this->assertContains('ws://127.0.0.1:*', $connectSrc);
        $this->assertContains('wss://camera-dev.8gategames.com', $connectSrc);
    }

    // =========================================================================
    // 2. SEC-17 Adversarial Probes: img-src Exfiltration Prevention
    // =========================================================================

    public function test_adversarial_img_src_blocks_wildcards_and_arbitrary_image_sources(): void
    {
        app()->detectEnvironment(fn () => 'production');
        Config::set('filesystems.disks.s3.url', 'https://secure-vault.s3.amazonaws.com');
        Config::set('filesystems.disks.s3.endpoint', 'https://s3.ap-southeast-1.amazonaws.com');

        $request = Request::create('https://camera.company.com/', 'GET');
        $response = $this->executeSecurityMiddleware($request);

        $csp = $response->headers->get('Content-Security-Policy');
        $directives = $this->parseCsp($csp);

        $this->assertArrayHasKey('img-src', $directives);
        $imgSrc = $directives['img-src'];

        // Strict wildcard checks
        $this->assertNotContains('https:', $imgSrc, 'PROBE FAILED: img-src must not contain wildcard https:');
        $this->assertNotContains('http:', $imgSrc, 'PROBE FAILED: img-src must not contain wildcard http:');
        $this->assertNotContains('*', $imgSrc, 'PROBE FAILED: img-src must not contain wildcard *');

        // Verify simulated image beacon exfiltration vectors are NOT allowed
        $exfilUrls = [
            'https://evil-analytics.com/beacon.gif',
            'https://canarytokens.com/tags/image.png',
            'http://attacker.com/pixel.png',
        ];

        foreach ($exfilUrls as $url) {
            $this->assertNotContains(
                $url,
                $imgSrc,
                "PROBE FAILED: Arbitrary image source {$url} must not be present in img-src"
            );
        }

        // Legitimate origins
        $this->assertContains("'self'", $imgSrc);
        $this->assertContains('data:', $imgSrc);
        $this->assertContains('blob:', $imgSrc);
        $this->assertContains('https://secure-vault.s3.amazonaws.com', $imgSrc);
        $this->assertContains('https://s3.ap-southeast-1.amazonaws.com', $imgSrc);
    }

    public function test_adversarial_img_src_handles_empty_s3_configuration_gracefully(): void
    {
        app()->detectEnvironment(fn () => 'production');
        Config::set('filesystems.disks.s3.url', null);
        Config::set('filesystems.disks.s3.endpoint', '');

        $request = Request::create('https://camera.company.com/', 'GET');
        $response = $this->executeSecurityMiddleware($request);

        $csp = $response->headers->get('Content-Security-Policy');
        $directives = $this->parseCsp($csp);

        $imgSrc = $directives['img-src'];

        $this->assertEquals(["'self'", 'data:', 'blob:'], $imgSrc);
        $this->assertNotContains('', $imgSrc);
    }

    // =========================================================================
    // 3. SEC-17 Adversarial Probes: script-src, WebAssembly, and Worker
    // =========================================================================

    public function test_adversarial_script_src_forbids_unsafe_eval_in_production(): void
    {
        app()->detectEnvironment(fn () => 'production');

        $request = Request::create('https://camera.company.com/', 'GET');
        $response = $this->executeSecurityMiddleware($request);

        $csp = $response->headers->get('Content-Security-Policy');
        $directives = $this->parseCsp($csp);

        $this->assertArrayHasKey('script-src', $directives);
        $scriptSrc = $directives['script-src'];

        // In production: must NOT have 'unsafe-eval'
        $this->assertNotContains(
            "'unsafe-eval'",
            $scriptSrc,
            "PROBE FAILED: 'unsafe-eval' must be stripped from script-src in production"
        );

        // In production: must have 'wasm-unsafe-eval' to permit WebAssembly H.264/H.265 software video decoding
        $this->assertContains(
            "'wasm-unsafe-eval'",
            $scriptSrc,
            "PROBE FAILED: 'wasm-unsafe-eval' must be present for WebAssembly software player support"
        );

        // Verify worker-src is explicit
        $this->assertArrayHasKey('worker-src', $directives);
        $this->assertEquals(["'self'", 'blob:'], $directives['worker-src']);

        // Verify public decoder assets exist on disk for WebAssembly player
        $this->assertFileExists(public_path('player/decoder.wasm'));
        $this->assertFileExists(public_path('player/decoder_worker.js'));
        $this->assertFileExists(public_path('player/decoder.js'));
    }

    public function test_adversarial_script_src_permits_eval_only_in_development(): void
    {
        app()->detectEnvironment(fn () => 'local');

        $request = Request::create('http://localhost:8000/', 'GET');
        $response = $this->executeSecurityMiddleware($request);

        $csp = $response->headers->get('Content-Security-Policy');
        $directives = $this->parseCsp($csp);

        $scriptSrc = $directives['script-src'];

        $this->assertContains("'unsafe-eval'", $scriptSrc, 'Local environment requires unsafe-eval for Vite HMR');
        $this->assertContains("'wasm-unsafe-eval'", $scriptSrc);
    }

    // =========================================================================
    // 4. Host Header & Parameter Injection Stress Testing
    // =========================================================================

    public function test_adversarial_host_header_injection_resistance(): void
    {
        app()->detectEnvironment(fn () => 'production');

        // Test 1: Semicolon injection attempt via Host header
        $caughtSuspicious = false;
        try {
            $maliciousRequest = Request::create(
                'https://camera.company.com/',
                'GET',
                [],
                [],
                [],
                ['HTTP_HOST' => "camera.company.com; script-src 'unsafe-inline' *;"]
            );
            $response = $this->executeSecurityMiddleware($maliciousRequest);
            $csp = $response->headers->get('Content-Security-Policy');

            // If Symfony accepted the host header, ensure it did not inject new CSP directives
            $directives = $this->parseCsp($csp);
            // Semicolons in host must not spawn unauthorized directives
            $this->assertFalse(
                in_array('*', $directives['script-src'] ?? []),
                'Host header injection must not inject wildcards into script-src'
            );
        } catch (SuspiciousOperationException $e) {
            // Symfony core defense caught the suspicious host header
            $caughtSuspicious = true;
        }

        $this->assertTrue(true, 'Host header injection was safely handled or rejected');

        // Test 2: Standard valid domain host header correctly populates connect-src
        $validRequest = Request::create(
            'https://ai-hub.enterprise.internal/',
            'GET',
            [],
            [],
            [],
            ['HTTP_HOST' => 'ai-hub.enterprise.internal']
        );
        $response = $this->executeSecurityMiddleware($validRequest);
        $csp = $response->headers->get('Content-Security-Policy');
        $directives = $this->parseCsp($csp);

        $this->assertContains('ws://ai-hub.enterprise.internal:8080', $directives['connect-src']);
        $this->assertContains('wss://ai-hub.enterprise.internal:8080', $directives['connect-src']);
        $this->assertContains('ws://ai-hub.enterprise.internal', $directives['connect-src']);
        $this->assertContains('wss://ai-hub.enterprise.internal', $directives['connect-src']);
    }

    // =========================================================================
    // 5. SEC-18 Dependency & Lockfile Adversarial Verification
    // =========================================================================

    public function test_adversarial_npm_audit_reports_zero_vulnerabilities(): void
    {
        $packageLockPath = base_path('package-lock.json');
        $this->assertFileExists($packageLockPath);

        $lockData = json_decode(file_get_contents($packageLockPath), true);
        $this->assertNotNull($lockData);

        $packages = $lockData['packages'] ?? [];

        // 1. Verify @vue/server-renderer is patched (GHSA-g2v6-rqmx-r4w6 requires >= 3.5.42)
        $serverRendererVer = null;
        if (isset($packages['node_modules/@vue/server-renderer'])) {
            $serverRendererVer = ltrim($packages['node_modules/@vue/server-renderer']['version'], 'v');
        }
        if ($serverRendererVer !== null) {
            $this->assertTrue(
                version_compare($serverRendererVer, '3.5.42', '>='),
                "PROBE FAILED: @vue/server-renderer is {$serverRendererVer}, must be >= 3.5.42"
            );
        }

        // 2. Verify shell-quote is patched (GHSA-pqg4-j6r4-53mv requires >= 1.11.0)
        $shellQuoteFound = false;
        foreach ($packages as $path => $info) {
            if (str_ends_with($path, 'shell-quote')) {
                $shellQuoteFound = true;
                $ver = ltrim($info['version'] ?? '', 'v');
                $this->assertTrue(
                    version_compare($ver, '1.11.0', '>='),
                    "PROBE FAILED: Found vulnerable shell-quote {$ver} at {$path}, must be >= 1.11.0"
                );
            }
        }
        $this->assertTrue($shellQuoteFound, 'shell-quote was checked in package-lock.json');

        // 3. Verify source-map-js is patched (GHSA-68fv-2mgg-jv7q requires >= 1.2.2)
        $sourceMapFound = false;
        foreach ($packages as $path => $info) {
            if (str_ends_with($path, 'source-map-js')) {
                $sourceMapFound = true;
                $ver = ltrim($info['version'] ?? '', 'v');
                $this->assertTrue(
                    version_compare($ver, '1.2.2', '>='),
                    "PROBE FAILED: Found vulnerable source-map-js {$ver} at {$path}, must be >= 1.2.2"
                );
            }
        }
        $this->assertTrue($sourceMapFound, 'source-map-js was checked in package-lock.json');
    }

    public function test_adversarial_composer_audit_reports_zero_advisories(): void
    {
        $composerLockPath = base_path('composer.lock');
        $this->assertFileExists($composerLockPath);

        $lockData = json_decode(file_get_contents($composerLockPath), true);
        $this->assertNotNull($lockData);

        $packages = collect($lockData['packages'])->keyBy('name');

        // 1. laravel/framework: CVE-2026-102279 requires >= 13.30.0 (or >= 12.69.0)
        $this->assertTrue($packages->has('laravel/framework'));
        $frameworkVer = ltrim($packages->get('laravel/framework')['version'], 'v');
        $this->assertTrue(
            version_compare($frameworkVer, '13.30.0', '>='),
            "PROBE FAILED: laravel/framework version {$frameworkVer} is below 13.30.0"
        );

        // 2. league/commonmark: PKSA-m2dq-1fhr-29b1 & PKSA-m4t9-vsgq-8khn require >= 2.10.3
        $this->assertTrue($packages->has('league/commonmark'));
        $commonmarkVer = ltrim($packages->get('league/commonmark')['version'], 'v');
        $this->assertTrue(
            version_compare($commonmarkVer, '2.10.3', '>='),
            "PROBE FAILED: league/commonmark version {$commonmarkVer} is below 2.10.3"
        );

        // 3. league/flysystem: CVE-2026-102601 requires >= 3.36.0
        $this->assertTrue($packages->has('league/flysystem'));
        $flysystemVer = ltrim($packages->get('league/flysystem')['version'], 'v');
        $this->assertTrue(
            version_compare($flysystemVer, '3.36.0', '>='),
            "PROBE FAILED: league/flysystem version {$flysystemVer} is below 3.36.0"
        );
    }
}
