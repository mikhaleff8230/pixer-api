<?php

namespace Marvel\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PlaceFeedResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'url' => $this->url,
            'created_at' => $this->created_at,
            'community' => $this->whenLoaded('community', fn () => $this->community ? [
                'id' => $this->community->id,
                'name' => $this->community->name,
                'slug' => $this->community->slug,
            ] : null),

            // ✅ ЕДИНЫЙ КОНТРАКТ
            'images' => $this->relationLoaded('images')
                ? $this->images->take(1)->map(fn ($image) => [
                    'id' => $image->id,
                    'url' => $image->getImageUrlAttribute(),
                    'thumbnail' => $image->getThumbnailUrlAttribute($image->thumbnail_url),
                ])->values()
                : [],

            'likes_count' => $this->likes_count ?? 0,
            'comments_count' => $this->comments_count ?? 0,

            'user' => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'avatar' => $this->getUserAvatar(),
            ] : [
                'id' => null,
                'name' => 'Unknown User',
                'avatar' => null,
            ],
        ];
    }

    private function getUserAvatar()
    {
        try {
            if ($this->user && $this->user->profile && $this->user->profile->avatar) {
                $avatar = $this->user->profile->avatar;

                if (is_array($avatar) && isset($avatar['thumbnail'])) {
                    $avatarUrl = $avatar['thumbnail'];
                } elseif (is_string($avatar)) {
                    $avatarUrl = $avatar;
                } elseif (is_object($avatar) && isset($avatar->thumbnail)) {
                    $avatarUrl = $avatar->thumbnail;
                } else {
                    $avatarUrl = null;
                }

                if ($avatarUrl && str_starts_with($avatarUrl, '/storage/')) {
                    return 'https://api.sancan.ru' . $avatarUrl;
                }

                return $avatarUrl;
            }

            return null;
        } catch (\Throwable $e) {
            \Log::error('PlaceFeedResource avatar error', [
                'user_id' => $this->user->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
