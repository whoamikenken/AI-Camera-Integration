<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Handle an incoming request and add defensive HTTP security headers.
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=()');

        if ($request->isSecure() || app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        $isProd = app()->environment('production');

        $scriptSrc = ["'self'", "'unsafe-inline'"];
        if (! $isProd) {
            $scriptSrc[] = "'unsafe-eval'";
        }
        $scriptSrc[] = "'wasm-unsafe-eval'";
        $scriptSrc[] = 'https://static.cloudflareinsights.com';

        $workerSrc = ["'self'", 'blob:'];

        $styleSrc = ["'self'", "'unsafe-inline'", 'https://fonts.googleapis.com'];

        $imgSrc = ["'self'", 'data:', 'blob:'];
        $s3Url = config('filesystems.disks.s3.url');
        if (! empty($s3Url)) {
            $imgSrc[] = $s3Url;
        }
        $s3Endpoint = config('filesystems.disks.s3.endpoint');
        if (! empty($s3Endpoint)) {
            $imgSrc[] = $s3Endpoint;
        }

        $fontSrc = ["'self'", 'data:', 'https://fonts.gstatic.com'];

        $connectSrc = ["'self'", 'https://cloudflareinsights.com'];
        $reverbHost = config('broadcasting.connections.reverb.options.host') ?: env('VITE_REVERB_HOST');
        $reverbPort = config('broadcasting.connections.reverb.options.port') ?: env('VITE_REVERB_PORT', 8080);

        $hosts = array_filter(array_unique([
            $reverbHost,
            $request->getHost(),
        ]));

        foreach ($hosts as $host) {
            if (! empty($host)) {
                $connectSrc[] = "ws://{$host}:{$reverbPort}";
                $connectSrc[] = "wss://{$host}:{$reverbPort}";
                $connectSrc[] = "ws://{$host}";
                $connectSrc[] = "wss://{$host}";
            }
        }

        if (! $isProd) {
            $connectSrc[] = 'ws://localhost:*';
            $connectSrc[] = 'wss://localhost:*';
            $connectSrc[] = 'ws://127.0.0.1:*';
            $connectSrc[] = 'wss://camera-dev.8gategames.com';
        }

        $cspDirectives = [
            "default-src 'self'",
            'script-src '.implode(' ', array_unique($scriptSrc)),
            'worker-src '.implode(' ', array_unique($workerSrc)),
            'style-src '.implode(' ', array_unique($styleSrc)),
            'img-src '.implode(' ', array_unique($imgSrc)),
            'font-src '.implode(' ', array_unique($fontSrc)),
            'connect-src '.implode(' ', array_unique($connectSrc)),
            "frame-ancestors 'none'",
        ];

        $csp = implode('; ', $cspDirectives).';';

        $response->headers->set('Content-Security-Policy', $csp);

        return $response;
    }
}
