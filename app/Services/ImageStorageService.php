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
}
