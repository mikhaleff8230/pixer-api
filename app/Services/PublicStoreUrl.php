<?php

namespace App\Services;

use InvalidArgumentException;

final class PublicStoreUrl
{
    private string $baseUrl;

    public function __construct(?string $baseUrl = null)
    {
        $configuredShopUrl = $baseUrl
            ?? config('shop.shop_url')
            ?? config('app.frontend_url')
            ?? config('app.url');

        $this->baseUrl = $this->normalize($configuredShopUrl);
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function productUrl(string $slug, int|string $id): string
    {
        $cleanSlug = trim($slug);
        $cleanSlug = preg_replace('#^/?(?:ru/)?element/#', '', $cleanSlug) ?? $cleanSlug;
        $cleanSlug = preg_replace('#^/?ru/#', '', $cleanSlug) ?? $cleanSlug;
        $cleanSlug = trim($cleanSlug, '/');

        if ($cleanSlug === '') {
            throw new InvalidArgumentException('Product slug must not be empty.');
        }

        return $this->to('/element/' . $cleanSlug . '-' . $id);
    }

    public function to(string $path): string
    {
        $path = '/' . ltrim(trim($path), '/');

        return $this->baseUrl . $path;
    }

    private function normalize(?string $url): string
    {
        $url = trim((string) $url);

        if ($url === '') {
            throw new InvalidArgumentException('Public store URL is not configured.');
        }

        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }

        $parts = parse_url($url);
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '.'));

        if ($host === '') {
            throw new InvalidArgumentException('Public store URL has no valid host.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        if (!in_array($scheme, ['http', 'https'], true)) {
            $scheme = 'https';
        }

        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return $scheme . '://' . $host . $port;
    }
}
