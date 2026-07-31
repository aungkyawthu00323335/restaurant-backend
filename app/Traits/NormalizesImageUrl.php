<?php

namespace App\Traits;

/**
 * Normalizes image_url attributes by stripping localhost/127.0.0.1 prefixes.
 * The client app will resolve relative paths against its configured API host.
 */
trait NormalizesImageUrl
{
    public function getImageUrlAttribute(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        // If it's already a relative path, return as-is
        if (! str_starts_with($value, 'http://') && ! str_starts_with($value, 'https://')) {
            return $value;
        }

        $parsed = parse_url($value);
        $host = $parsed['host'] ?? '';

        // Rewrite localhost/127.0.0.1 URLs to relative paths
        if ($host === 'localhost' || $host === '127.0.0.1') {
            $path = ltrim($parsed['path'] ?? '', '/');
            return $path;
        }

        return $value;
    }
}
