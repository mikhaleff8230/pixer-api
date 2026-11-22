<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Marvel\Database\Models\Attachment;

final class TelegramMediaImportService
{
    public function import(UploadedFile $file): array
    {
        $attachment = new Attachment();
        $attachment->save();
        $extension = strtolower($file->extension() ?: 'jpg');
        $media = $attachment->addMedia($file->getRealPath())
            ->usingFileName(Str::uuid().'.'.$extension)
            ->toMediaCollection('default', config('shop.media_disk') ?: 's3');

        return [
            'attachment_id' => $attachment->id,
            'image' => ['id' => $attachment->id, 'thumbnail' => $media->getUrl('thumbnail'), 'original' => $media->getUrl()],
            'content_hash' => hash_file('sha256', $file->getRealPath()),
        ];
    }
}
