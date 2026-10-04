<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageStorageService
{
    /**
     * Store a base64 encoded image string to public disk.
     *
     * @param string|null $base64Data
     * @param string $folder Subfolder e.g. 'snaps', 'scenes', 'personnel'
     * @return string|null Public URL of stored image
     */
    public function storeBase64Image(?string $base64Data, string $folder = 'snaps'): ?string
    {
        if (empty($base64Data)) {
            return null;
        }

        // Clean out possible data URI prefixes
        if (preg_match('/^data:image\/(\w+);base64,/', $base64Data, $type)) {
            $base64Data = substr($base64Data, strpos($base64Data, ',') + 1);
            $rawExt = strtolower($type[1]);
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
            if (! in_array($rawExt, $allowedExtensions, true)) {
                $extension = 'jpg';
            } else {
                $extension = $rawExt === 'jpeg' ? 'jpg' : $rawExt;
            }
        } else {
            $extension = 'jpg';
        }

        $decodedBinary = base64_decode($base64Data);
        if ($decodedBinary === false) {
            return null;
        }

        $datePath = date('Y/m/d');
        $fileName = "{$folder}/{$datePath}/" . Str::random(24) . ".{$extension}";

        Storage::disk('public')->put($fileName, $decodedBinary);

        return Storage::disk('public')->url($fileName);
    }

    /**
     * Alias for storeBase64Image.
     */
    public function saveBase64Image(?string $base64Data, string $folder = 'snaps'): ?string
    {
        return $this->storeBase64Image($base64Data, $folder);
    }

    /**
     * Store a base64 encoded image and return both path, url, and full base64 data uri.
     *
     * @param string|null $base64Data
     * @param string $folder
     * @return array{url: string, base64: string, path: string}|null
     */
    public function storeFromBase64(?string $base64Data, string $folder = 'personnel'): ?array
    {
        if (empty($base64Data)) {
            return null;
        }

        $mimeType = 'image/jpeg';
        $rawBase64 = $base64Data;

        if (preg_match('/^data:(image\/\w+);base64,/', $base64Data, $matches)) {
            $mimeType = $matches[1];
            $rawBase64 = substr($base64Data, strpos($base64Data, ',') + 1);
        }

        $extension = str_contains($mimeType, 'png') ? 'png' : 'jpg';

        $decodedBinary = base64_decode($rawBase64);
        if ($decodedBinary === false) {
            return null;
        }

        $datePath = date('Y/m');
        $path = "{$folder}/{$datePath}/" . Str::random(24) . ".{$extension}";

        Storage::disk('public')->put($path, $decodedBinary);
        $url = Storage::disk('public')->url($path);

        $formattedBase64 = 'data:' . $mimeType . ';base64,' . base64_encode($decodedBinary);

        return [
            'url' => $url,
            'base64' => $formattedBase64,
            'path' => $path,
        ];
    }

    /**
     * Store an uploaded file and return its public URL and base64 string.
     *
     * @param UploadedFile $file
     * @param string $folder
     * @return array{url: string, base64: string, path: string}
     */
    public function storeUploadedImage(UploadedFile $file, string $folder = 'personnel'): array
    {
        $path = $file->store("{$folder}/" . date('Y/m'), 'public');
        $url = Storage::disk('public')->url($path);
        $binary = file_get_contents($file->getRealPath());
        $base64 = 'data:' . $file->getMimeType() . ';base64,' . base64_encode($binary);

        return [
            'url' => $url,
            'base64' => $base64,
            'path' => $path,
        ];
    }

    /**
     * Store from an existing storage relative path or public storage URL or external image URL.
     *
     * @param string|null $urlOrPath
     * @param string $folder
     * @return array{url: string, base64: string, path: string}|null
     */
    public function storeFromUrlOrPath(?string $urlOrPath, string $folder = 'personnel'): ?array
    {
        if (empty($urlOrPath)) {
            return null;
        }

        // If it is already a base64 data string
        if (str_starts_with($urlOrPath, 'data:image')) {
            return $this->storeFromBase64($urlOrPath, $folder);
        }

        $binary = null;
        $extension = 'jpg';

        // Check if it's a local storage URL or relative path
        $relativePath = $urlOrPath;
        if (str_contains($relativePath, '/storage/')) {
            $relativePath = preg_replace('#^.*?/storage/#', '', $relativePath);
        }
        $relativePath = ltrim($relativePath, '/');

        if (Storage::disk('public')->exists($relativePath)) {
            $binary = Storage::disk('public')->get($relativePath);
            $ext = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                $extension = $ext === 'jpeg' ? 'jpg' : $ext;
            }
        } elseif (filter_var($urlOrPath, FILTER_VALIDATE_URL)) {
            if (!$this->isSafeUrl($urlOrPath)) {
                return null;
            }

            try {
                $response = \Illuminate\Support\Facades\Http::withoutRedirecting()->timeout(8)->get($urlOrPath);
                if ($response->successful()) {
                    $binary = $response->body();
                    $contentType = $response->header('Content-Type') ?? '';
                    if (str_contains($contentType, 'png')) {
                        $extension = 'png';
                    }
                }
            } catch (\Throwable $e) {
                // fall through
            }
        }

        if (!$binary) {
            return null;
        }

        $mimeType = $extension === 'png' ? 'image/png' : 'image/jpeg';
        $newPath = "{$folder}/" . date('Y/m') . '/' . Str::random(24) . ".{$extension}";

        Storage::disk('public')->put($newPath, $binary);
        $url = Storage::disk('public')->url($newPath);
        $base64 = 'data:' . $mimeType . ';base64,' . base64_encode($binary);

        return [
            'url' => $url,
            'base64' => $base64,
            'path' => $newPath,
        ];
    }

    /**
     * Check if a URL is safe to fetch (anti-SSRF validation).
     */
    public function isSafeUrl(?string $url): bool
    {
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        if (empty($host)) {
            return false;
        }

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

        return true;
    }

    /**
     * Determine if an IP address is a public, non-private, non-reserved IP.
     */
    public function isPublicIp(string $ip): bool
    {
        // Reject private and reserved IP ranges (RFC 1918, link-local, loopback, multicast, etc.)
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        // Explicitly check for 169.254.x.x link-local / cloud metadata range if not caught
        if (str_starts_with($ip, '169.254.') || str_starts_with($ip, '127.')) {
            return false;
        }

        // IPv6 loopback / unique local
        if ($ip === '::1' || str_starts_with($ip, 'fc') || str_starts_with($ip, 'fd') || str_starts_with($ip, 'fe80:')) {
            return false;
        }

        return true;
    }

    /**
     * Get the configured disk for biometric media storage.
     */
    public function getDisk(): string
    {
        return config('filesystems.biometrics_disk', env('BIOMETRICS_DISK', 'public'));
    }

    /**
     * Retrieve binary and mime type for secure serving of biometric images.
     */
    public function getMedia(string $path): ?array
    {
        $cleanPath = ltrim(preg_replace('#^.*?/storage/#', '', $path), '/');
        $disks = array_unique([$this->getDisk(), 'public', 'local', 'biometrics']);

        foreach ($disks as $disk) {
            try {
                if (Storage::disk($disk)->exists($cleanPath)) {
                    $mimeType = Storage::disk($disk)->mimeType($cleanPath) ?: 'image/jpeg';
                    return [
                        'content' => Storage::disk($disk)->get($cleanPath),
                        'mime_type' => $mimeType,
                    ];
                }
            } catch (\Throwable $e) {
                // fall through
            }
        }

        return null;
    }
}
