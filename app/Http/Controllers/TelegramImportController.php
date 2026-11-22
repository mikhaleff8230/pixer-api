<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessTelegramImportPost;
use App\Models\TelegramImportChannel;
use App\Models\TelegramImportPost;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Shop;
use Marvel\Http\Controllers\SancanImporterAuthController;

class TelegramImportController extends Controller
{
    public function index(Request $request)
    {
        $query = TelegramImportChannel::withCount(['posts', 'posts as products_count' => fn ($q) => $q->whereNotNull('product_id')])->latest();
        if ($request->filled('shop_id')) { $this->shop($request, (int) $request->shop_id); $query->where('shop_id', $request->shop_id); }
        return $query->paginate(min(100, max(1, (int) $request->input('limit', 25))));
    }

    public function store(Request $request)
    {
        $data = $this->validatedChannel($request);
        $this->shop($request, (int) $data['shop_id']);
        $data['created_by'] = $request->user('api')->id;
        $data['username'] = $this->username($data['url'] ?? $data['username'] ?? '');
        $data['title'] = $data['title'] ?: ($data['username'] ?: 'Telegram channel');
        $data['content_permission_confirmed_at'] = now();
        return response()->json(TelegramImportChannel::create($data), 201);
    }

    public function update(Request $request, TelegramImportChannel $channel)
    {
        $this->shop($request, (int) $channel->shop_id);
        $data = $this->validatedChannel($request, true);
        if (isset($data['url']) || isset($data['username'])) $data['username'] = $this->username($data['url'] ?? $data['username'] ?? '');
        $channel->fill($data)->save();
        return $channel->fresh();
    }

    public function destroy(Request $request, TelegramImportChannel $channel)
    {
        $this->shop($request, (int) $channel->shop_id);
        $channel->update(['status' => 'archived', 'auto_import_enabled' => false]);
        $channel->delete();
        return response()->noContent();
    }

    public function sync(Request $request, TelegramImportChannel $channel)
    {
        $this->shop($request, (int) $channel->shop_id);
        $channel->update(['status' => 'sync_requested', 'last_error' => null]);
        return response()->json(['message' => 'Синхронизация поставлена в очередь Listener.', 'channel' => $channel->fresh()]);
    }

    public function pause(Request $request, TelegramImportChannel $channel)
    {
        $this->shop($request, (int) $channel->shop_id);
        $channel->update(['status' => 'paused', 'auto_import_enabled' => false]);
        return $channel->fresh();
    }

    public function stats(Request $request)
    {
        $shopIds = $this->allowedShopIds($request);
        $channels = TelegramImportChannel::whereIn('shop_id', $shopIds);
        $posts = TelegramImportPost::whereHas('channel', fn ($q) => $q->whereIn('shop_id', $shopIds));
        return [
            'channels_total' => (clone $channels)->count(),
            'channels_active' => (clone $channels)->where('status', 'active')->where('auto_import_enabled', true)->count(),
            'posts_today' => (clone $posts)->whereDate('created_at', today())->count(),
            'drafts_created' => (clone $posts)->where('status', 'draft_created')->count(),
            'review' => (clone $posts)->where('status', 'review')->count(),
            'skipped' => (clone $posts)->whereIn('status', ['skipped', 'duplicate'])->count(),
            'errors' => (clone $posts)->where('status', 'error')->count(),
            'last_sync_at' => (clone $channels)->max('last_sync_at'),
        ];
    }

    public function posts(Request $request)
    {
        $shopIds = $this->allowedShopIds($request);
        $query = TelegramImportPost::with(['channel:id,title,username,shop_id', 'media', 'product.shop:id,slug'])->whereHas('channel', fn ($q) => $q->whereIn('shop_id', $shopIds))->latest('original_date');
        if ($request->filled('channel_id')) $query->where('channel_id', $request->channel_id);
        if ($request->filled('status')) $query->where('status', $request->status);
        return $query->paginate(min(100, max(1, (int) $request->input('limit', 25))));
    }

    public function retry(Request $request, TelegramImportPost $post)
    {
        $this->shop($request, (int) $post->channel->shop_id);
        abort_if($post->product_id, 409, 'Для публикации уже создан товар.');
        $post->update(['status' => 'new', 'error_message' => null]);
        ProcessTelegramImportPost::dispatch($post->id);
        return response()->json(['message' => 'Повторная обработка поставлена в очередь.']);
    }

    public function bulk(Request $request)
    {
        $data = $request->validate([
            'post_ids' => ['required', 'array', 'min:1', 'max:200'], 'post_ids.*' => ['integer'],
            'action' => ['required', Rule::in(['retry', 'exclude', 'set_category', 'set_markup', 'publish'])],
            'category_id' => ['required_if:action,set_category', 'nullable', 'integer', 'exists:categories,id'],
            'markup_percent' => ['required_if:action,set_markup', 'nullable', 'numeric', 'min:0', 'max:1000'],
        ]);
        $shopIds = $this->allowedShopIds($request);
        $posts = TelegramImportPost::with('channel')->whereIn('id', $data['post_ids'])->whereHas('channel', fn ($q) => $q->whereIn('shop_id', $shopIds))->get();
        abort_if($posts->count() !== count(array_unique($data['post_ids'])), 403, 'Часть публикаций недоступна.');

        foreach ($posts as $post) {
            if ($data['action'] === 'exclude') $post->update(['status' => 'excluded']);
            if ($data['action'] === 'set_category') $post->update(['category_id' => $data['category_id'], 'status' => $post->product_id ? $post->status : 'new']);
            if ($data['action'] === 'set_markup') $post->update(['markup_percent_override' => $data['markup_percent'], 'status' => $post->product_id ? $post->status : 'new']);
            if (in_array($data['action'], ['retry', 'set_category', 'set_markup'], true) && !$post->product_id) ProcessTelegramImportPost::dispatch($post->id);
            if ($data['action'] === 'publish' && $post->product_id && $post->status === 'draft_created') {
                Product::whereKey($post->product_id)->update(['status' => 'publish']);
            }
        }
        return response()->json(['updated' => $posts->count()]);
    }

    private function validatedChannel(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        return $request->validate([
            'shop_id' => [$required, 'integer', 'exists:shops,id'], 'telegram_channel_id' => ['nullable', 'string', 'max:100'],
            'username' => ['nullable', 'string', 'max:100'], 'url' => ['nullable', 'string', 'max:255'], 'title' => [$required, 'string', 'max:255'],
            'supplier_name' => ['nullable', 'string', 'max:255'], 'access_method' => [$required, Rule::in(['bot', 'mtproto'])],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'], 'price_source' => [$required, Rule::in(['wholesale', 'retail'])],
            'markup_percent' => [$required, 'numeric', 'min:0', 'max:1000'], 'fixed_markup' => ['nullable', 'numeric', 'min:0'],
            'round_to' => ['nullable', 'integer', Rule::in([1, 10, 50, 100])], 'minimum_sale_price' => ['nullable', 'numeric', 'min:0'],
            'initial_import_mode' => [$required, Rule::in(['none', 'limit', 'dates'])], 'initial_import_limit' => ['nullable', 'integer', Rule::in([20, 50, 100, 500])],
            'initial_from' => ['nullable', 'date'], 'initial_to' => ['nullable', 'date', 'after_or_equal:initial_from'], 'auto_import_enabled' => ['sometimes', 'boolean'],
            'content_permission_confirmed' => [$required, 'accepted'],
        ]);
    }

    private function shop(Request $request, int $id): Shop
    {
        $shop = Shop::findOrFail($id); abort_unless(SancanImporterAuthController::userCanAccessShop($request->user('api'), $shop), 403); return $shop;
    }
    private function allowedShopIds(Request $request): array
    {
        return Shop::query()->get()->filter(fn ($shop) => SancanImporterAuthController::userCanAccessShop($request->user('api'), $shop))->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
    private function username(string $value): ?string
    {
        $value = trim($value); if ($value === '') return null; if (preg_match('~(?:t\.me/|@)([A-Za-z0-9_]+)~i', $value, $m)) return $m[1]; return ltrim($value, '@');
    }
}
