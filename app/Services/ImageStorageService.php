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
            $extension = strtolower($type[1]);
            if ($extension === 'jpeg') {
                $extension = 'jpg';
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
            try {
                $response = \Illuminate\Support\Facades\Http::timeout(8)->get($urlOrPath);
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
}
