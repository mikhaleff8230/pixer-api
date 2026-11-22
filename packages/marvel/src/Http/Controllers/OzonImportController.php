<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Shop;
use Marvel\Database\Models\Type;
use Marvel\Enums\ProductStatus;
use Marvel\Enums\ProductType;
use Marvel\Services\ArticleGeneratorService;
use Marvel\Services\OzonImageImportService;
use Marvel\Services\ProductSlugService;

class OzonImportController extends CoreController
{
    public function store(Request $request, OzonImageImportService $imageImporter): JsonResponse
    {
        $validated = $request->validate([
            'source_platform' => ['required', 'in:ozon'],
            'shop_id' => ['required', 'integer', 'exists:shops,id'],
            'source_url' => ['required', 'url', 'max:2048'],
            'source_product_id' => ['nullable', 'string', 'max:100'],
            'title' => ['required', 'string', 'max:255'],
            'source_price' => ['required', 'numeric', 'min:0.01', 'max:999999999'],
            'markup_percent' => ['required', 'numeric', 'min:0', 'max:1000'],
            'additional_costs' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'images' => ['nullable', 'array', 'max:12'],
            'images.*' => ['url', 'max:2048'],
            'description' => ['nullable', 'string', 'max:100000'],
            'attributes' => ['nullable', 'array'],
            'content_rights_confirmed' => ['accepted'],
            'refresh_images' => ['nullable', 'boolean'],
            'refresh_content' => ['nullable', 'boolean'],
        ]);

        [$sourceUrl, $sourceProductId] = $this->normalizeOzonUrl(
            $validated['source_url'],
            $validated['source_product_id'] ?? null
        );
        $shop = $this->resolveShop($request, (int) $validated['shop_id']);
        $actor = $request->user('api');

        $duplicate = Product::query()
            ->where('shop_id', $shop->id)
            ->where(function ($query) use ($sourceUrl, $sourceProductId) {
                $query->where('preview_url', $sourceUrl)
                    ->orWhereHas('metas', function ($metaQuery) use ($sourceProductId) {
                        $metaQuery->where('key', 'source_product_id')->where('value', $sourceProductId);
                    });
            })
            ->first();

        if ($duplicate) {
            $restoredImages = [];
            $contentUpdated = false;
            $hasNoImages = empty($duplicate->image) && empty($duplicate->gallery);
            $shouldRefreshImages = $hasNoImages || !empty($validated['refresh_images']);
            if ($shouldRefreshImages && !empty($validated['images'])) {
                $restoredImages = $imageImporter->import($validated['images']);
                if ($restoredImages) {
                    $duplicate->image = $restoredImages[0];
                    $duplicate->gallery = $restoredImages;
                    $duplicate->save();
                }
            }

            if (!empty($validated['refresh_content'])) {
                $duplicate->description = $validated['description'] ?? '';
                $duplicate->setMeta([
                    'source_attributes' => json_encode($validated['attributes'] ?? [], JSON_UNESCAPED_UNICODE),
                    'source_imported_by' => $actor->id,
                ]);
                $duplicate->save();
                $contentUpdated = true;
            }

            $message = 'Товар уже импортирован';
            if ($restoredImages && $contentUpdated) {
                $message .= ', фотографии и описание обновлены';
            } elseif ($restoredImages) {
                $message .= ', фотографии обновлены';
            } elseif ($contentUpdated) {
                $message .= ', описание обновлено';
            }

            return response()->json([
                'message' => $message,
                'duplicate' => true,
                'product_id' => $duplicate->id,
                'imported_images' => count($restoredImages),
                'edit_url' => $this->editUrl($shop, $duplicate),
            ], 409);
        }

        $ozonType = Type::query()
            ->whereIn('slug', ['ozon', 'ozon-product', 'product-ozon'])
            ->orWhereRaw('LOWER(name) LIKE ?', ['%ozon%'])
            ->orWhereRaw('LOWER(name) LIKE ?', ['%озон%'])
            ->first();
        if (!$ozonType) {
            throw ValidationException::withMessages([
                'source_platform' => ['В справочнике SANCAN не найден существующий тип «Товар с Ozon».'],
            ]);
        }

        $sourcePrice = (float) $validated['source_price'];
        $markupPercent = (float) $validated['markup_percent'];
        $additionalCosts = (float) ($validated['additional_costs'] ?? 0);
        $sellingPrice = (float) ceil(($sourcePrice + $additionalCosts) * (1 + $markupPercent / 100));
        $images = $imageImporter->import($validated['images'] ?? []);

        $product = DB::transaction(function () use (
            $validated, $shop, $ozonType, $sourceUrl, $sourceProductId,
            $sourcePrice, $markupPercent, $additionalCosts, $sellingPrice, $images, $actor
        ) {
            $slug = ProductSlugService::generateForNewProduct($validated['title'], $validated['title']);
            $product = Product::create([
                'name' => $validated['title'],
                'slug' => $slug['slug'],
                'slug_numeric_code' => $slug['slug_numeric_code'],
                // Ozon.ru content is imported into the Russian catalog. Without
                // this explicit value the database default (`en`) makes the
                // draft invisible to Seller Admin requests with `language=ru`.
                'language' => 'ru',
                'description' => $validated['description'] ?? '',
                'type_id' => $ozonType->id,
                'shop_id' => $shop->id,
                'price' => $sellingPrice,
                'sale_price' => null,
                'min_price' => $sellingPrice,
                'max_price' => $sellingPrice,
                'quantity' => 1,
                'unit' => 'шт.',
                'in_stock' => true,
                'is_taxable' => false,
                'is_digital' => false,
                'is_external' => false,
                'product_type' => ProductType::SIMPLE,
                'status' => ProductStatus::DRAFT,
                'sku' => 'OZON'.substr(preg_replace('/\D+/', '', $sourceProductId), -24),
                'internal_article' => ArticleGeneratorService::generateProductArticle(),
                'preview_url' => $sourceUrl,
                'image' => $images[0] ?? null,
                'gallery' => $images,
            ]);

            $product->setMeta([
                'source_platform' => 'ozon',
                'source_product_id' => $sourceProductId,
                'source_price' => $sourcePrice,
                'markup_percent' => $markupPercent,
                'additional_costs' => $additionalCosts,
                'source_attributes' => json_encode($validated['attributes'] ?? [], JSON_UNESCAPED_UNICODE),
                'content_rights_confirmed' => true,
                'source_imported_by' => $actor->id,
            ]);
            $product->save();

            return $product;
        });

        return response()->json([
            'message' => 'Черновик товара создан',
            'duplicate' => false,
            'product_id' => $product->id,
            'selling_price' => $sellingPrice,
            'imported_images' => count($images),
            'shop' => ['id' => $shop->id, 'name' => $shop->name, 'slug' => $shop->slug],
            'edit_url' => $this->editUrl($shop, $product),
        ], 201);
    }

    private function resolveShop(Request $request, int $shopId): Shop
    {
        $user = $request->user('api');
        $token = $user?->currentAccessToken();
        abort_unless(
            $user && $token
            && Str::startsWith((string) $token->name, 'sancan-importer:shop:')
            && $user->tokenCan('ozon:import')
            && $user->tokenCan('shop:'.$shopId),
            403,
            'Токен SANCAN Importer не разрешает импорт в этот магазин.'
        );

        $shop = Shop::findOrFail($shopId);
        abort_unless(SancanImporterAuthController::userCanAccessShop($user, $shop), 403, 'Нет прав на выбранный магазин.');
        return $shop;
    }

    private function normalizeOzonUrl(string $url, ?string $providedId): array
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host !== 'ozon.ru' && $host !== 'www.ozon.ru' && !Str::endsWith($host, '.ozon.ru')) {
            throw ValidationException::withMessages(['source_url' => ['Разрешены только ссылки Ozon.ru.']]);
        }

        if (!preg_match('~/product/(?:[^/?#]*-)?(\d+)(?:/|$)~i', (string) ($parts['path'] ?? ''), $matches)) {
            throw ValidationException::withMessages(['source_url' => ['Не удалось определить ID товара Ozon из URL.']]);
        }

        $id = $matches[1];
        if ($providedId && preg_replace('/\D+/', '', $providedId) !== $id) {
            throw ValidationException::withMessages(['source_product_id' => ['ID товара не совпадает с URL Ozon.']]);
        }

        return ['https://www.ozon.ru/product/'.$id.'/', $id];
    }

    private function editUrl(Shop $shop, Product $product): string
    {
        $base = rtrim((string) (config('shop.admin_url') ?: env('ADMIN_URL', 'https://seller.sancan.ru')), '/');
        return $base.'/'.$shop->slug.'/products/'.$product->full_slug.'/edit-wizard';
    }
}
