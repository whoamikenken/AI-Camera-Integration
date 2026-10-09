<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class AdversarialMilestone3Challenger2Test extends TestCase
{
    /**
     * Helper to invoke the SecurityHeaders middleware with a given request.
     */
    private function handleMiddleware(Request $request): Response
    {
        $middleware = new SecurityHeaders;
        $next = function ($req) {
            return new Response('<!DOCTYPE html><html><head></head><body>AI Camera Hub Empirical Test</body></html>', 200, [
                'Content-Type' => 'text/html',
            ]);
        };

        return $middleware->handle($request, $next);
    }

    /**
     * Helper to parse a Content-Security-Policy string into an associative array [directive => tokens].
     */
    private function parseCsp(string $csp): array
    {
        $directives = [];
        $parts = explode(';', $csp);
        foreach ($parts as $part) {
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
    // 1. CSP Header Generation Under Multiple Application Environments
    // =========================================================================

    public function test_csp_in_production_environment(): void
    {
        app()->detectEnvironment(fn () => 'production');

        Config::set('broadcasting.connections.reverb.options.host', 'reverb.prod.internal');
        Config::set('broadcasting.connections.reverb.options.port', 8080);
        Config::set('filesystems.disks.s3.url', 'https://prod-media.s3.ap-southeast-1.amazonaws.com');

        $request = Request::create('https://hub.example.com/', 'GET');
        $response = $this->handleMiddleware($request);

        $this->assertTrue($response->headers->has('Content-Security-Policy'), 'CSP header missing in production');
        $csp = $response->headers->get('Content-Security-Policy');
        $directives = $this->parseCsp($csp);

        // A. script-src
        $this->assertArrayHasKey('script-src', $directives);
        $this->assertContains("'self'", $directives['script-src']);
        $this->assertContains("'unsafe-inline'", $directives['script-src']);
        $this->assertContains("'wasm-unsafe-eval'", $directives['script-src']);
        $this->assertContains('https://static.cloudflareinsights.com', $directives['script-src']);
        $this->assertNotContains("'unsafe-eval'", $directives['script-src'], 'PROD VIOLATION: unsafe-eval present in production');

        // B. worker-src
        $this->assertArrayHasKey('worker-src', $directives);
        $this->assertEquals(["'self'", 'blob:'], $directives['worker-src']);

        // C. img-src: No wildcards
        $this->assertArrayHasKey('img-src', $directives);
        $this->assertNotContains('https:', $directives['img-src'], 'PROD VIOLATION: img-src contains wildcard https:');
        $this->assertNotContains('http:', $directives['img-src'], 'PROD VIOLATION: img-src contains wildcard http:');
        $this->assertNotContains('*', $directives['img-src'], 'PROD VIOLATION: img-src contains wildcard *');
        $this->assertContains("'self'", $directives['img-src']);
        $this->assertContains('data:', $directives['img-src']);
        $this->assertContains('blob:', $directives['img-src']);
        $this->assertContains('https://prod-media.s3.ap-southeast-1.amazonaws.com', $directives['img-src']);

        // D. connect-src: No wildcards, no dev origins
        $this->assertArrayHasKey('connect-src', $directives);
        $this->assertNotContains('https:', $directives['connect-src'], 'PROD VIOLATION: connect-src contains wildcard https:');
        $this->assertNotContains('ws:', $directives['connect-src'], 'PROD VIOLATION: connect-src contains wildcard ws:');
        $this->assertNotContains('wss:', $directives['connect-src'], 'PROD VIOLATION: connect-src contains wildcard wss:');
        $this->assertNotContains('*', $directives['connect-src'], 'PROD VIOLATION: connect-src contains wildcard *');
        $this->assertNotContains('ws://localhost:*', $directives['connect-src']);
        $this->assertNotContains('wss://localhost:*', $directives['connect-src']);
        $this->assertNotContains('ws://127.0.0.1:*', $directives['connect-src']);
        $this->assertNotContains('wss://camera-dev.8gategames.com', $directives['connect-src']);

        // Reverb origins present
        $this->assertContains('ws://reverb.prod.internal:8080', $directives['connect-src']);
        $this->assertContains('wss://reverb.prod.internal:8080', $directives['connect-src']);

        // E. HSTS header in production
        $this->assertTrue($response->headers->has('Strict-Transport-Security'), 'HSTS header missing in production');
        $this->assertEquals('max-age=31536000; includeSubDomains', $response->headers->get('Strict-Transport-Security'));
    }

    public function test_csp_in_local_environment(): void
    {
        app()->detectEnvironment(fn () => 'local');

        $request = Request::create('http://localhost:8000/', 'GET');
        $response = $this->handleMiddleware($request);

        $csp = $response->headers->get('Content-Security-Policy');
        $directives = $this->parseCsp($csp);

        // script-src: Must retain 'unsafe-eval' for local dev
        $this->assertArrayHasKey('script-src', $directives);
        $this->assertContains("'unsafe-eval'", $directives['script-src']);
        $this->assertContains("'wasm-unsafe-eval'", $directives['script-src']);

        // connect-src: Must contain dev server origins
        $this->assertArrayHasKey('connect-src', $directives);
        $this->assertContains('ws://localhost:*', $directives['connect-src']);
        $this->assertContains('wss://localhost:*', $directives['connect-src']);
        $this->assertContains('ws://127.0.0.1:*', $directives['connect-src']);
        $this->assertContains('wss://camera-dev.8gategames.com', $directives['connect-src']);

        // HSTS header: Should not be enforced over insecure local HTTP
        $this->assertFalse($response->headers->has('Strict-Transport-Security'));
    }

    public function test_csp_in_testing_environment(): void
    {
        app()->detectEnvironment(fn () => 'testing');

        $request = Request::create('http://localhost:8000/', 'GET');
        $response = $this->handleMiddleware($request);

        $csp = $response->headers->get('Content-Security-Policy');
        $directives = $this->parseCsp($csp);

        // Testing environment behaves as non-production
        $this->assertContains("'unsafe-eval'", $directives['script-src']);
        $this->assertContains('ws://localhost:*', $directives['connect-src']);
        $this->assertContains('wss://camera-dev.8gategames.com', $directives['connect-src']);
    }

    public function test_csp_in_staging_environment(): void
    {
        app()->detectEnvironment(fn () => 'staging');

        $request = Request::create('https://staging-camera.example.com/', 'GET');
        $response = $this->handleMiddleware($request);

        $csp = $response->headers->get('Content-Security-Policy');
        $directives = $this->parseCsp($csp);

        // In staging, app()->environment('production') is false
        $this->assertContains("'unsafe-eval'", $directives['script-src']);
        $this->assertContains('ws://localhost:*', $directives['connect-src']);
    }

    // =========================================================================
    // 2. Vite Dev Server Origins and Config Alignment
    // =========================================================================

    public function test_vite_config_matches_csp_dev_origins(): void
    {
        $viteConfigPath = base_path('vite.config.js');
        $this->assertFileExists($viteConfigPath);

        $viteContent = file_get_contents($viteConfigPath);

        // Check Vite dev server HMR config matches CSP entries
        $this->assertStringContainsString('camera-dev.8gategames.com', $viteContent);
        $this->assertStringContainsString('strictPort: true', $viteContent);
        $this->assertStringContainsString('protocol: \'wss\'', $viteContent);

        // Check non-prod CSP provides matching origin
        app()->detectEnvironment(fn () => 'local');
        $request = Request::create('http://127.0.0.1:8000/', 'GET');
        $response = $this->handleMiddleware($request);
        $directives = $this->parseCsp($response->headers->get('Content-Security-Policy'));

        $this->assertContains('wss://camera-dev.8gategames.com', $directives['connect-src']);
        $this->assertContains('ws://127.0.0.1:*', $directives['connect-src']);
    }

    // =========================================================================
    // 3. S3 URL and Endpoint Permutations & Deduplication
    // =========================================================================

    public function test_s3_distinct_url_and_endpoint_both_included(): void
    {
        app()->detectEnvironment(fn () => 'production');
        Config::set('filesystems.disks.s3.url', 'https://custom-cdn.example.com');
        Config::set('filesystems.disks.s3.endpoint', 'https://s3.us-west-2.amazonaws.com');

        $request = Request::create('https://hub.example.com/', 'GET');
        $response = $this->handleMiddleware($request);
        $directives = $this->parseCsp($response->headers->get('Content-Security-Policy'));

        $this->assertContains('https://custom-cdn.example.com', $directives['img-src']);
        $this->assertContains('https://s3.us-west-2.amazonaws.com', $directives['img-src']);
    }

    public function test_s3_identical_url_and_endpoint_deduplicated(): void
    {
        app()->detectEnvironment(fn () => 'production');
        $sameUrl = 'https://s3.ap-southeast-1.amazonaws.com/my-bucket';
        Config::set('filesystems.disks.s3.url', $sameUrl);
        Config::set('filesystems.disks.s3.endpoint', $sameUrl);

        $request = Request::create('https://hub.example.com/', 'GET');
        $response = $this->handleMiddleware($request);
        $directives = $this->parseCsp($response->headers->get('Content-Security-Policy'));

        $occurrences = array_count_values($directives['img-src']);
        $this->assertEquals(1, $occurrences[$sameUrl], 'Identical S3 URL/endpoint must be deduplicated in img-src');
    }

    public function test_s3_empty_config_leaves_no_empty_tokens(): void
    {
        app()->detectEnvironment(fn () => 'production');
        Config::set('filesystems.disks.s3.url', null);
        Config::set('filesystems.disks.s3.endpoint', '');

        $request = Request::create('https://hub.example.com/', 'GET');
        $response = $this->handleMiddleware($request);
        $directives = $this->parseCsp($response->headers->get('Content-Security-Policy'));

        $this->assertNotContains('', $directives['img-src']);
        $this->assertEquals(["'self'", 'data:', 'blob:'], $directives['img-src']);
    }

    // =========================================================================
    // 4. Reverb WebSocket and Host Permutations
    // =========================================================================

    public function test_reverb_host_matches_request_host_deduplicates(): void
    {
        app()->detectEnvironment(fn () => 'production');
        Config::set('broadcasting.connections.reverb.options.host', 'camera.example.com');
        Config::set('broadcasting.connections.reverb.options.port', 8080);

        $request = Request::create('https://camera.example.com/', 'GET');
        $response = $this->handleMiddleware($request);
        $directives = $this->parseCsp($response->headers->get('Content-Security-Policy'));

        $occurrences = array_count_values($directives['connect-src']);
        $this->assertEquals(1, $occurrences['ws://camera.example.com:8080']);
        $this->assertEquals(1, $occurrences['wss://camera.example.com:8080']);
        $this->assertEquals(1, $occurrences['ws://camera.example.com']);
        $this->assertEquals(1, $occurrences['wss://camera.example.com']);
    }

    public function test_reverb_custom_port_interpolated_correctly(): void
    {
        app()->detectEnvironment(fn () => 'production');
        Config::set('broadcasting.connections.reverb.options.host', 'reverb.internal');
        Config::set('broadcasting.connections.reverb.options.port', 9001);

        $request = Request::create('https://camera.example.com/', 'GET');
        $response = $this->handleMiddleware($request);
        $directives = $this->parseCsp($response->headers->get('Content-Security-Policy'));

        $this->assertContains('ws://reverb.internal:9001', $directives['connect-src']);
        $this->assertContains('wss://reverb.internal:9001', $directives['connect-src']);
    }

    public function test_reverb_null_config_falls_back_gracefully(): void
    {
        app()->detectEnvironment(fn () => 'production');
        Config::set('broadcasting.connections.reverb.options.host', null);
        putenv('VITE_REVERB_HOST'); // Unset env

        $request = Request::create('https://standalone.example.com/', 'GET');
        $response = $this->handleMiddleware($request);
        $directives = $this->parseCsp($response->headers->get('Content-Security-Policy'));

        $this->assertContains('ws://standalone.example.com:8080', $directives['connect-src']);
        $this->assertContains('wss://standalone.example.com:8080', $directives['connect-src']);
        $this->assertNotContains('ws://:8080', $directives['connect-src']);
        $this->assertNotContains('wss://:8080', $directives['connect-src']);
    }

    // =========================================================================
    // 5. WebAssembly Software Video Decoding Support
    // =========================================================================

    public function test_webassembly_decoder_wasm_file_integrity(): void
    {
        $wasmPath = public_path('player/decoder.wasm');
        $this->assertFileExists($wasmPath);

        // Verify valid WebAssembly binary header (\0asm)
        $header = file_get_contents($wasmPath, false, null, 0, 4);
        $this->assertEquals("\x00asm", $header, 'decoder.wasm must be a valid WebAssembly binary with \\0asm magic bytes');

        // Verify worker script and JS loader exist
        $this->assertFileExists(public_path('player/decoder_worker.js'));
        $this->assertFileExists(public_path('player/decoder.js'));
    }

    // =========================================================================
    // 6. Lockfile Dependency Constraints & CVE Mitigations
    // =========================================================================

    public function test_package_lock_transitive_dependency_constraints(): void
    {
        $lockFile = base_path('package-lock.json');
        $this->assertFileExists($lockFile);

        $lockData = json_decode(file_get_contents($lockFile), true);
        $this->assertNotNull($lockData);

        $packages = $lockData['packages'] ?? [];

        // 1. Vue >= 3.5.43
        $vueVersion = ltrim($packages['node_modules/vue']['version'] ?? '0.0.0', 'v');
        $this->assertTrue(version_compare($vueVersion, '3.5.43', '>='), "Vue {$vueVersion} is below 3.5.43");

        // 2. @vue/server-renderer >= 3.5.42 (GHSA-g2v6-rqmx-r4w6)
        $serverRendererVersion = ltrim($packages['node_modules/@vue/server-renderer']['version'] ?? '0.0.0', 'v');
        $this->assertTrue(version_compare($serverRendererVersion, '3.5.42', '>='), "@vue/server-renderer {$serverRendererVersion} is below 3.5.42");

        // 3. shell-quote >= 1.11.0 (GHSA-pqg4-j6r4-53mv)
        $foundShellQuote = false;
        foreach ($packages as $pkgPath => $pkg) {
            if (str_ends_with($pkgPath, 'shell-quote')) {
                $foundShellQuote = true;
                $sqVer = ltrim($pkg['version'] ?? '0.0.0', 'v');
                $this->assertTrue(version_compare($sqVer, '1.11.0', '>='), "shell-quote {$sqVer} at {$pkgPath} is below 1.11.0");
            }
        }
        $this->assertTrue($foundShellQuote, 'shell-quote was located and verified');

        // 4. source-map-js >= 1.2.2 (GHSA-68fv-2mgg-jv7q)
        $foundSourceMap = false;
        foreach ($packages as $pkgPath => $pkg) {
            if (str_ends_with($pkgPath, 'source-map-js')) {
                $foundSourceMap = true;
                $smVer = ltrim($pkg['version'] ?? '0.0.0', 'v');
                $this->assertTrue(version_compare($smVer, '1.2.2', '>='), "source-map-js {$smVer} at {$pkgPath} is below 1.2.2");
            }
        }
        $this->assertTrue($foundSourceMap, 'source-map-js was located and verified');
    }

    public function test_composer_lock_transitive_dependency_constraints(): void
    {
        $lockFile = base_path('composer.lock');
        $this->assertFileExists($lockFile);

        $lockData = json_decode(file_get_contents($lockFile), true);
        $this->assertNotNull($lockData);

        $packages = collect($lockData['packages'])->keyBy('name');

        // 1. laravel/framework >= 13.30.0 (CVE-2026-102279)
        $this->assertTrue($packages->has('laravel/framework'));
        $fwVer = ltrim($packages->get('laravel/framework')['version'], 'v');
        $this->assertTrue(version_compare($fwVer, '13.30.0', '>='), "laravel/framework {$fwVer} is below 13.30.0");

        // 2. league/commonmark >= 2.10.3 (PKSA-m2dq-1fhr-29b1 & PKSA-m4t9-vsgq-8khn)
        $this->assertTrue($packages->has('league/commonmark'));
        $cmVer = ltrim($packages->get('league/commonmark')['version'], 'v');
        $this->assertTrue(version_compare($cmVer, '2.10.3', '>='), "league/commonmark {$cmVer} is below 2.10.3");

        // 3. league/flysystem >= 3.36.0 (CVE-2026-102601)
        $this->assertTrue($packages->has('league/flysystem'));
        $fsVer = ltrim($packages->get('league/flysystem')['version'], 'v');
        $this->assertTrue(version_compare($fsVer, '3.36.0', '>='), "league/flysystem {$fsVer} is below 3.36.0");
    }

    // =========================================================================
    // 7. Defensive HTTP Headers Conformance
    // =========================================================================

    public function test_defensive_http_headers_present(): void
    {
        $request = Request::create('https://hub.example.com/', 'GET');
        $response = $this->handleMiddleware($request);

        $this->assertEquals('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertEquals('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertEquals('1; mode=block', $response->headers->get('X-XSS-Protection'));
        $this->assertEquals('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
        $this->assertEquals('camera=(self), microphone=(), geolocation=()', $response->headers->get('Permissions-Policy'));
    }
}
