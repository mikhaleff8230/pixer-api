<?php

namespace App\Console\Commands;

use App\Support\MediaUrl;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class MediaAuditCommand extends Command
{
    protected $signature = 'media:audit
        {--limit=0 : Max product rows to scan (0 = all)}
        {--product= : Audit a single product id}
        {--disk=s3 : Filesystem disk}';

    protected $description = 'Audit DB image references against current S3 bucket (read-only)';

    public function handle(): int
    {
        $diskName = (string) $this->option('disk');
        $limit = (int) $this->option('limit');
        $productId = $this->option('product');

        $checked = 0;
        $found = 0;
        $missing = 0;
        $invalid = 0;
        $samples = [];

        try {
            $disk = Storage::disk($diskName);
        } catch (Throwable $e) {
            $this->error('Cannot open disk "' . $diskName . '": ' . $e->getMessage());
            return self::FAILURE;
        }

        $query = DB::table('products')->select(['id', 'image', 'gallery']);
        if ($productId) {
            $query->where('id', $productId);
        } else {
            $query->where(function ($q) {
                $q->whereNotNull('image')->orWhereNotNull('gallery');
            });
        }
        if ($limit > 0) {
            $query->limit($limit);
        }

        foreach ($query->cursor() as $row) {
            foreach ($this->extractUrls($row->image, $row->gallery) as $url) {
                $checked++;
                $key = MediaUrl::objectKey($url);
                if (!$key) {
                    $invalid++;
                    continue;
                }
                try {
                    if ($disk->exists($key)) {
                        $found++;
                    } else {
                        $missing++;
                        if (count($samples) < 10) {
                            $samples[] = [
                                'product_id' => $row->id,
                                'key' => $key,
                                'url' => mb_substr($url, 0, 120),
                            ];
                        }
                    }
                } catch (Throwable $e) {
                    $invalid++;
                }
            }
        }

        // Spatie media table (relative paths)
        $mediaQuery = DB::table('media')->select(['id', 'model_id', 'model_type', 'disk', 'file_name']);
        if ($productId) {
            $mediaQuery->where('model_type', 'like', '%Product%')->where('model_id', $productId);
        }
        if ($limit > 0) {
            $mediaQuery->limit($limit);
        }

        foreach ($mediaQuery->cursor() as $media) {
            $checked++;
            // Default Spatie path: {id}/{file_name}
            $key = $media->id . '/' . $media->file_name;
            try {
                if ($disk->exists($key)) {
                    $found++;
                } else {
                    $missing++;
                    if (count($samples) < 10) {
                        $samples[] = [
                            'product_id' => $media->model_id,
                            'key' => $key,
                            'url' => 'media:' . $media->id,
                        ];
                    }
                }
            } catch (Throwable $e) {
                $invalid++;
            }
        }

        $this->info('Media records checked: ' . $checked);
        $this->info('Objects found in S3: ' . $found);
        $this->info('Missing objects: ' . $missing);
        $this->info('Invalid paths: ' . $invalid);

        if ($samples) {
            $this->newLine();
            $this->warn('Sample missing:');
            foreach ($samples as $sample) {
                $this->line(sprintf(
                    '- product=%s key=%s url=%s',
                    $sample['product_id'],
                    $sample['key'],
                    $sample['url']
                ));
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function extractUrls($image, $gallery): array
    {
        $urls = [];
        foreach ([$image, $gallery] as $raw) {
            if (!$raw) {
                continue;
            }
            $data = is_string($raw) ? json_decode($raw, true) : $raw;
            if (!is_array($data)) {
                continue;
            }
            // Single image object or list
            $items = isset($data['original']) || isset($data['thumbnail']) || isset($data['url'])
                ? [$data]
                : $data;
            foreach ($items as $item) {
                if (!is_array($item)) {
                    if (is_string($item) && $item !== '') {
                        $urls[] = $item;
                    }
                    continue;
                }
                foreach (['original', 'thumbnail', 'url'] as $field) {
                    if (!empty($item[$field]) && is_string($item[$field])) {
                        $urls[] = $item[$field];
                    }
                }
            }
        }

        return array_values(array_unique($urls));
    }
}
