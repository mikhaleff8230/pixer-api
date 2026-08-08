<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

class S3CheckCommand extends Command
{
    protected $signature = 's3:check {--disk=s3 : Filesystem disk name}';

    protected $description = 'Safe S3 health check: upload, read, delete a temporary file';

    public function handle(): int
    {
        $diskName = (string) $this->option('disk');
        $this->info('S3 disk: ' . $diskName);

        try {
            $disk = Storage::disk($diskName);
            $config = config('filesystems.disks.' . $diskName, []);
            $bucket = $config['bucket'] ?? null;
            $endpoint = $config['endpoint'] ?? null;
            $url = $config['url'] ?? null;

            if (empty($bucket)) {
                $this->error('Bucket connection: FAIL (AWS_BUCKET is empty)');
                return self::FAILURE;
            }

            $this->line('Bucket: ' . $bucket);
            if ($endpoint) {
                $this->line('Endpoint: ' . $endpoint);
            }
            if ($url) {
                $this->line('Public URL base: ' . $url);
            }

            $key = 'system/health-check/' . now()->format('YmdHis') . '.txt';
            $payload = 'sancan-s3-check-' . now()->toIso8601String();

            $disk->put($key, $payload, [
                'visibility' => 'public',
                'ContentType' => 'text/plain',
                'CacheControl' => 'no-store',
            ]);
            $this->info('Upload test: OK');

            if (!$disk->exists($key)) {
                $this->error('Read test: FAIL (object not found after upload)');
                return self::FAILURE;
            }

            $body = $disk->get($key);
            if ($body !== $payload) {
                $this->error('Read test: FAIL (content mismatch)');
                $disk->delete($key);
                return self::FAILURE;
            }
            $this->info('Read test: OK');

            $disk->delete($key);
            if ($disk->exists($key)) {
                $this->error('Delete test: FAIL (object still exists)');
                return self::FAILURE;
            }
            $this->info('Delete test: OK');
            $this->info('Bucket connection: OK');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Bucket connection: FAIL');
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }
}
