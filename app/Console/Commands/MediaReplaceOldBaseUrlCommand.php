<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MediaReplaceOldBaseUrlCommand extends Command
{
    protected $signature = 'media:replace-old-base-url
        {--dry-run : Only report changes, do not write}
        {--from= : Old base URL (default: previous Timeweb bucket URL)}
        {--to= : New base URL (default: MEDIA_URL)}';

    protected $description = 'Replace old absolute S3 base URLs in products.image/gallery (safe with --dry-run)';

    public function handle(): int
    {
        $from = rtrim((string) ($this->option('from') ?: 'https://s3.twcstorage.ru/c9c7b7f0-sancan-media'), '/');
        $to = rtrim((string) ($this->option('to') ?: (config('media.url') ?: '')), '/');
        $dryRun = (bool) $this->option('dry-run');

        if ($to === '') {
            $this->error('Target MEDIA_URL / --to is empty. Set MEDIA_URL or pass --to=');
            return self::FAILURE;
        }

        $this->info('From: ' . $from);
        $this->info('To:   ' . $to);
        $this->info('Mode: ' . ($dryRun ? 'DRY-RUN' : 'WRITE'));

        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $from) . '%';
        $rows = DB::table('products')
            ->select(['id', 'image', 'gallery'])
            ->where(function ($q) use ($like) {
                $q->where('image', 'like', $like)
                    ->orWhere('gallery', 'like', $like);
            })
            ->get();

        $this->info('Matching products: ' . $rows->count());

        $changed = 0;
        $examples = 0;

        foreach ($rows as $row) {
            $newImage = $this->replaceInJson($row->image, $from, $to);
            $newGallery = $this->replaceInJson($row->gallery, $from, $to);

            if ($newImage === $row->image && $newGallery === $row->gallery) {
                continue;
            }

            $changed++;
            if ($examples < 5) {
                $this->line('--- product #' . $row->id);
                if ($newImage !== $row->image) {
                    $this->line('image before: ' . mb_substr((string) $row->image, 0, 160));
                    $this->line('image after:  ' . mb_substr((string) $newImage, 0, 160));
                }
                $examples++;
            }

            if (!$dryRun) {
                DB::table('products')->where('id', $row->id)->update([
                    'image' => $newImage,
                    'gallery' => $newGallery,
                ]);
            }
        }

        $this->info('Products that would change: ' . $changed);
        if ($dryRun) {
            $this->warn('No data written (dry-run). Re-run without --dry-run only after confirmation.');
        } else {
            $this->info('Updated products: ' . $changed);
        }

        return self::SUCCESS;
    }

    private function replaceInJson($raw, string $from, string $to)
    {
        if ($raw === null || $raw === '') {
            return $raw;
        }

        if (!is_string($raw)) {
            $raw = json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if (!str_contains($raw, $from)) {
            return $raw;
        }

        return str_replace($from, $to, $raw);
    }
}
