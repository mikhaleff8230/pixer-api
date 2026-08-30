<?php

namespace App\Http\Controllers;

use App\Models\SellerOnboarding;
use App\Services\DecimalMoney;
use App\Services\ProductPublicationService;
use App\Services\SellerOnboardingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Marvel\Database\Models\Attachment;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\User;
use Marvel\Database\Models\Type;
use Marvel\Database\Repositories\ShopRepository;
use Marvel\Http\Controllers\AttachmentController;
use Marvel\Http\Controllers\ProductController;
use Marvel\Http\Requests\AttachmentRequest;
use Marvel\Http\Requests\ProductCreateRequest;
use Marvel\Http\Requests\ShopCreateRequest;

class SellerOnboardingController extends Controller
{
    public function __construct(private SellerOnboardingService $onboarding) {}

    private function state(Request $request): SellerOnboarding
    {
        abort_unless($this->onboarding->eligible($request->user()) && $request->user()->is_active, 403);
        return $this->onboarding->ensure($request->user(), (array) $request->input('attribution', []));
    }

    public function show(Request $request)
    {
        return response()->json($this->onboarding->present($this->state($request)))->header('Cache-Control', 'private, no-store');
    }

    public function shop(Request $request)
    {
        $rules = (new ShopCreateRequest)->rules();
        $data = Validator::make(['name' => trim((string) $request->input('name'))], ['name' => $rules['name']])->validate();
        return DB::transaction(function () use ($request, $data) {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $state = $this->state($request);
            if (!$state->completed_at) {
                $update = new Request($data);
                $update->setUserResolver(fn () => $request->user());
                app(ShopRepository::class)->updateShop($update, $state->shop_id);
                $state->update(['step' => 'product', 'shop_completed_at' => $state->shop_completed_at ?? now()]);
                $this->onboarding->event($state, 'seller_shop_completed');
                $this->onboarding->event($state, 'seller_first_product_started');
            }
            return $this->onboarding->present($state);
        }, 3);
    }

    public function photo(AttachmentRequest $request)
    {
        $this->state($request);
        $request->validate(['attachment' => 'required|array|size:1', 'attachment.*' => 'required|image|mimes:jpg,jpeg,png,webp|max:10240']);
        // Use exactly the existing media pipeline; only mark ownership of wizard uploads.
        $images = app(AttachmentController::class)->store($request);
        foreach ($images as $image) {
            $media = Attachment::findOrFail($image['id'])->getFirstMedia('default');
            $media->setCustomProperty('onboarding_uploaded_by', $request->user()->id)->save();
        }
        return $images;
    }

    public function draft(Request $request)
    {
        $data = $request->validate([
            'version' => 'required|integer|min:0',
            'draft' => 'required|array:name,price,category_id,image',
            'draft.name' => 'nullable|string|max:255',
            'draft.price' => 'nullable|string|max:24',
            'draft.category_id' => 'nullable|integer|exists:categories,id',
            'draft.image' => 'nullable|array',
        ]);
        return DB::transaction(function () use ($request, $data) {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $state = $this->state($request);
            abort_if((int) $state->draft_version !== (int) $data['version'], 409, 'Черновик изменён в другой вкладке. Обновите страницу.');
            if (!$state->completed_at && !$state->first_product_id) {
                $draft = $data['draft'];
                if (!empty($draft['image'])) $draft['image'] = $this->image($request, $draft['image']);
                $state->update(['product_draft' => $draft, 'draft_version' => $state->draft_version + 1]);
            }
            return ['version' => $state->draft_version];
        }, 3);
    }

    private function image(Request $request, array $image): array
    {
        $attachment = Attachment::find($image['id'] ?? null);
        $media = $attachment?->getFirstMedia('default');
        if (!$media || !in_array($media->mime_type, ['image/jpeg', 'image/png', 'image/webp'], true)
            || (int) $media->getCustomProperty('onboarding_uploaded_by') !== (int) $request->user()->id) {
            throw ValidationException::withMessages(['image' => 'Загрузите фотографию товара в формате JPG, PNG или WEBP.']);
        }
        return ['id' => $attachment->id, 'original' => $media->getUrl(), 'thumbnail' => $media->getUrl('thumbnail')];
    }

    public function product(Request $request)
    {
        // Keep admin impersonation useful for support, without artificial seller conversions.
        abort_if($request->user()->currentAccessToken()?->name === 'admin-impersonation', 403, 'SELLER_SELF_REGISTRATION_REQUIRED');
        return DB::transaction(function () use ($request) {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $state = $this->state($request);
            abort_unless(hash_equals($state->product_request_key, (string) $request->input('request_key')), 409);
            if ($state->first_product_id || $state->completed_at) return $this->onboarding->present($state);
            abort_unless($state->shop_completed_at, 422, 'Сначала сохраните название магазина.');
            $quick = $request->validate([
                'name' => 'required|string|max:255',
                'price' => ['required', 'regex:/^\d{1,8}([.,]\d{1,2})?$/'],
                'category_id' => 'required|integer|exists:categories,id',
                'image' => 'required|array',
            ]);
            $category = Category::findOrFail($quick['category_id']);
            $typeId = $category->type_id ?: Type::where('slug', config('seller_onboarding.quick_product_type_slug', 'element'))->value('id');
            if (!$typeId) throw ValidationException::withMessages(['type_id' => 'Группа товаров ещё не настроена. Обратитесь в поддержку.']);
            $price = DecimalMoney::decimal(DecimalMoney::cents(str_replace(',', '.', (string) $quick['price'])));
            if (DecimalMoney::cents($price) < 1) throw ValidationException::withMessages(['price' => 'Укажите цену больше нуля.']);
            $image = $this->image($request, $quick['image']);
            $input = [
                'name' => trim($quick['name']), 'price' => $price,
                'categories' => [$category->id], 'type_id' => $typeId,
                'shop_id' => $state->shop_id, 'product_type' => 'simple',
                'image' => $image, 'gallery' => [$image], 'language' => $category->language ?: 'ru',
                'quantity' => 1, 'in_stock' => true, 'is_active' => true,
                'status' => 'draft',
            ];
            Validator::make($input, (new ProductCreateRequest)->rules())->validate();
            $create = new Request($input);
            $create->setUserResolver(fn () => $request->user());
            // Existing creation/validation/slug/image/category machinery remains untouched.
            $product = app(ProductController::class)->ProductStore($create);
            $publish = new Request(['shop_id' => $state->shop_id, 'status' => 'publish']);
            $publish->setUserResolver(fn () => $request->user());
            $product->forceFill(app(ProductPublicationService::class)->attributes($publish, $product))->save();
            $this->onboarding->recordProduct($product, $request->user());
            return $this->onboarding->present($state->fresh());
        }, 3);
    }

    public function skip(Request $request)
    {
        $state = $this->state($request);
        if (!$state->completed_at) {
            $state->update(['skipped_at' => now()]);
            $this->onboarding->event($state, 'seller_onboarding_skipped');
        }
        return ['saved' => true];
    }

    public function resume(Request $request)
    {
        $state = $this->state($request);
        if (!$state->completed_at) {
            if ($state->started_at->lt(now()->subMinute())) $this->onboarding->event($state, 'seller_onboarding_resumed');
            if ($state->step === 'product') $this->onboarding->event($state, 'seller_first_product_started');
        }
        return $this->onboarding->present($state);
    }

    public function claimEvents(Request $request)
    {
        $this->state($request);
        return DB::transaction(function () use ($request) {
            $rows = DB::table('seller_onboarding_events')->where('user_id', $request->user()->id)
                ->whereNull('claimed_at')->orderBy('id')->lockForUpdate()->get();
            DB::table('seller_onboarding_events')->whereIn('id', $rows->pluck('id'))->update(['claimed_at' => now()]);
            return $rows->map(fn ($row) => ['event' => $row->event]);
        });
    }
}
