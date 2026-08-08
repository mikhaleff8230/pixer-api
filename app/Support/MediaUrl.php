<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

class MediaUrl
{
    /**
     * Public CDN / media base URL (no trailing slash).
     */
    public static function base(): ?string
    {
        $base = config('media.url') ?: env('ASSETS_BASE_URL') ?: env('AWS_URL');

        return $base ? rtrim((string) $base, '/') : null;
    }

    /**
     * Build a public URL for a relative path or pass through absolute URLs.
     */
    public static function publicUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        $normalized = self::normalizeRelativePath($path);
        $base = self::base();

        if ($base) {
            return $base . '/' . $normalized;
        }

        try {
            if (config('filesystems.disks.s3.bucket')) {
                return Storage::disk('s3')->url($normalized);
            }
        } catch (\Throwable $e) {
            // fall through
        }

        return null;
    }

    /**
     * Extract an S3 object key from a full URL or relative path.
     */
    public static function objectKey(?string $urlOrPath): ?string
    {
        if ($urlOrPath === null || $urlOrPath === '') {
            return null;
        }

        if (!filter_var($urlOrPath, FILTER_VALIDATE_URL)) {
            return self::normalizeRelativePath($urlOrPath);
        }

        $parsed = parse_url($urlOrPath);
        $path = ltrim($parsed['path'] ?? '', '/');
        if ($path === '') {
            return null;
        }

        $bucket = (string) config('filesystems.disks.s3.bucket');
        $legacyBuckets = array_filter([
            $bucket,
            'c9c7b7f0-sancan-media',
            '9fba268e-1b35-4def-9dfe-77db6d47e612',
        ]);

        foreach ($legacyBuckets as $b) {
            if (str_starts_with($path, $b . '/')) {
                return substr($path, strlen($b) + 1);
            }
        }

        return $path;
    }

    public static function normalizeRelativePath(string $path): string
    {
        if (str_starts_with($path, '/storage/')) {
            $path = substr($path, strlen('/storage/'));
        }

        return ltrim($path, '/');
    }
}
