<?php

namespace Marvel\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Marvel\Database\Models\Attachment;
use RuntimeException;

class OzonImageImportService
{
    private const MAX_BYTES = 10_485_760;
    private const MAX_REDIRECTS = 3;
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function import(array $urls): array
    {
        $images = [];

        foreach (array_slice(array_values(array_unique($urls)), 0, 12) as $url) {
            try {
                $images[] = $this->download((string) $url);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return $images;
    }

    private function download(string $url): array
    {
        $currentUrl = $url;

        for ($redirect = 0; $redirect <= self::MAX_REDIRECTS; $redirect++) {
            $target = $this->assertPublicHttpUrl($currentUrl);

            $options = ['allow_redirects' => false];
            if ($target['pinned_ip']) {
                // Pin the validated public address so a DNS change between validation
                // and the request cannot redirect the download into a private network.
                $options['curl'] = [
                    CURLOPT_RESOLVE => [
                        $target['host'].':'.$target['port'].':'.$target['pinned_ip'],
                    ],
                ];
            }

            $response = Http::timeout(15)
                ->connectTimeout(5)
                ->withHeaders(['User-Agent' => 'SANCAN-Importer/1.0'])
                ->withOptions($options)
                ->get($currentUrl);

            if ($response->redirect()) {
                $location = $response->header('Location');
                if (!$location || $redirect === self::MAX_REDIRECTS) {
                    throw new RuntimeException('Too many image redirects.');
                }
                $currentUrl = $this->resolveRedirect($currentUrl, $location);
                continue;
            }

            if (!$response->successful()) {
                throw new RuntimeException('Image download failed with HTTP '.$response->status().'.');
            }

            $declaredLength = (int) ($response->header('Content-Length') ?: 0);
            if ($declaredLength > self::MAX_BYTES) {
                throw new RuntimeException('Image is larger than 10 MB.');
            }

            $body = $response->body();
            if ($body === '' || strlen($body) > self::MAX_BYTES) {
                throw new RuntimeException('Image is empty or larger than 10 MB.');
            }

            $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
            $detectedMime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($body);
            if (!in_array($mime, self::ALLOWED_MIME_TYPES, true)
                || !in_array($detectedMime, self::ALLOWED_MIME_TYPES, true)) {
                throw new RuntimeException('Unsupported image content type.');
            }

            $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$detectedMime];
            $attachment = new Attachment();
            $attachment->save();
            $media = $attachment->addMediaFromString($body)
                ->usingFileName(Str::uuid().'.'.$extension)
                ->toMediaCollection('default', config('shop.media_disk') ?: 's3');

            return [
                'id' => $attachment->id,
                'thumbnail' => $media->getUrl('thumbnail'),
                'original' => $media->getUrl(),
            ];
        }

        throw new RuntimeException('Image redirect loop detected.');
    }

    private function assertPublicHttpUrl(string $url): array
    {
        $parts = parse_url($url);
        if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            throw new RuntimeException('Only HTTP(S) image URLs are allowed.');
        }

        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if ($host === '' || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
            $this->assertPublicIp($host);
            return ['host' => $host, 'port' => (int) ($parts['port'] ?? (($parts['scheme'] ?? '') === 'https' ? 443 : 80)), 'pinned_ip' => null];
        }

        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if (!$records) {
            throw new RuntimeException('Image host cannot be resolved.');
        }

        $addresses = [];
        foreach ($records as $record) {
            $ip = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
            $this->assertPublicIp($ip);
            $addresses[] = isset($record['ipv6']) ? '['.$ip.']' : $ip;
        }

        return [
            'host' => $host,
            'port' => (int) ($parts['port'] ?? (($parts['scheme'] ?? '') === 'https' ? 443 : 80)),
            'pinned_ip' => $addresses[0],
        ];
    }

    private function assertPublicIp(string $ip): void
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new RuntimeException('Private or reserved image address is forbidden.');
        }
    }

    private function resolveRedirect(string $baseUrl, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $base = parse_url($baseUrl);
        $origin = ($base['scheme'] ?? 'https').'://'.($base['host'] ?? '');
        if (isset($base['port'])) {
            $origin .= ':'.$base['port'];
        }

        return $origin.'/'.ltrim($location, '/');
    }
}
