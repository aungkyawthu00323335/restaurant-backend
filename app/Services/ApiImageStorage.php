<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ApiImageStorage
{
    private const EXTENSIONS = [
        'image/gif' => 'gif',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function storeBase64(?string $value, string $directory): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $encoded = $this->stripDataUri($value);
        $maxBytes = max(1, (int) config('pos.max_image_bytes', 5 * 1024 * 1024));
        $maxEncodedLength = (int) ceil($maxBytes * 4 / 3) + 16;
        $maxMegabytes = round($maxBytes / 1024 / 1024, 1);

        if (strlen($encoded) > $maxEncodedLength) {
            $this->invalid("The image must not be larger than {$maxMegabytes} MB.");
        }

        $bytes = base64_decode($encoded, true);
        if ($bytes === false || strlen($bytes) > $maxBytes) {
            $this->invalid("The image is invalid or larger than {$maxMegabytes} MB.");
        }

        $image = @getimagesizefromstring($bytes);
        $mime = is_array($image) ? ($image['mime'] ?? null) : null;
        $extension = is_string($mime) ? (self::EXTENSIONS[$mime] ?? null) : null;

        if ($extension === null) {
            $this->invalid('Only JPG, PNG, GIF, and WebP images are allowed.');
        }

        $directory = trim($directory, '/');
        $path = $directory.'/'.Str::uuid().'.'.$extension;

        if (! Storage::disk('public')->put($path, $bytes)) {
            $this->invalid('The image could not be stored.');
        }

        return Storage::disk('public')->url($path);
    }

    public function delete(?string $imageUrl, ?string $expectedDirectory = null): void
    {
        if ($imageUrl === null || trim($imageUrl) === '') {
            return;
        }

        $path = parse_url($imageUrl, PHP_URL_PATH);
        if (! is_string($path)) {
            return;
        }

        $marker = '/storage/';
        $position = strpos($path, $marker);
        if ($position === false) {
            return;
        }

        $storagePath = ltrim(rawurldecode(substr($path, $position + strlen($marker))), '/');
        if ($storagePath === '' || str_contains($storagePath, '..')) {
            return;
        }

        if ($expectedDirectory !== null) {
            $prefix = trim($expectedDirectory, '/').'/';
            if (! str_starts_with($storagePath, $prefix)) {
                return;
            }
        }

        Storage::disk('public')->delete($storagePath);
    }

    private function stripDataUri(string $value): string
    {
        if (! str_starts_with($value, 'data:')) {
            return preg_replace('/\s+/', '', $value) ?? '';
        }

        if (! preg_match('/^data:image\/[a-zA-Z0-9.+-]+;base64,(.*)$/s', $value, $matches)) {
            $this->invalid('The image data URI is invalid.');
        }

        return preg_replace('/\s+/', '', $matches[1]) ?? '';
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages([
            'image_base64' => [$message],
        ]);
    }
}
