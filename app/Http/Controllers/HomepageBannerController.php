<?php

namespace App\Http\Controllers;

use App\Models\HomepageBanner;
use App\Models\HomepageBannerSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class HomepageBannerController extends Controller
{
    public function index()
    {
        $settings = HomepageBannerSetting::first();
        return response()->json([
            'autoplay' => $settings?->autoplay ?? true,
            'interval_ms' => $settings?->interval_ms ?? 5000,
            'banners' => HomepageBanner::query()->where('is_active', true)->orderBy('kind')->orderBy('sort_order')->get(),
        ]);
    }

    public function adminIndex()
    {
        $settings = HomepageBannerSetting::first();
        return response()->json([
            'autoplay' => $settings?->autoplay ?? true,
            'interval_ms' => $settings?->interval_ms ?? 5000,
            'banners' => HomepageBanner::query()->orderBy('kind')->orderBy('sort_order')->get(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'autoplay' => ['required', 'boolean'],
            'interval_ms' => ['required', 'integer', 'min:1500', 'max:60000'],
            'banners' => ['required', 'array', 'max:30'],
            'banners.*.id' => ['nullable', 'integer'],
            'banners.*.kind' => ['required', Rule::in(['hero', 'strip'])],
            'banners.*.is_active' => ['required', 'boolean'],
            'banners.*.content' => ['required', 'array'],
            'images.*' => ['nullable', 'image', 'mimes:jpeg,png,webp,avif', 'max:8192'],
        ]);

        DB::transaction(function () use ($request, $data) {
            $settings = HomepageBannerSetting::firstOrNew();
            $settings->fill(['autoplay' => $data['autoplay'], 'interval_ms' => $data['interval_ms']])->save();
            $kept = [];

            foreach ($data['banners'] as $index => $row) {
                $banner = !empty($row['id']) ? HomepageBanner::find($row['id']) : new HomepageBanner();
                if (!$banner) $banner = new HomepageBanner();
                $content = $row['content'];
                $image = $request->file("images.$index");
                if ($image) {
                    if (!empty($banner->content['background_image'])) {
                        Storage::disk('public')->delete($banner->content['background_image']);
                    }
                    $content['background_image'] = $image->store('homepage-banners', 'public');
                }
                $banner->fill([
                    'kind' => $row['kind'],
                    'content' => $content,
                    'sort_order' => $index,
                    'is_active' => $row['is_active'],
                ])->save();
                $kept[] = $banner->id;
            }

            HomepageBanner::query()->whereNotIn('id', $kept)->get()->each(function ($banner) {
                if (!empty($banner->content['background_image'])) Storage::disk('public')->delete($banner->content['background_image']);
                $banner->delete();
            });
        });

        return $this->adminIndex();
    }
}
