<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Product;
use Marvel\Enums\ProductStatus;
use Marvel\Enums\ProductType;
use Marvel\Services\ArticleGeneratorService;
use Marvel\Services\ProductSlugService;

final class ProductDraftImportService
{
    public function create(array $data, array $meta = [], array $categoryIds = []): Product
    {
        return DB::transaction(function () use ($data, $meta, $categoryIds) {
            $slug = ProductSlugService::generateForNewProduct($data['name'], $data['name']);
            $product = Product::create(array_merge([
                'slug' => $slug['slug'],
                'slug_numeric_code' => $slug['slug_numeric_code'],
                'language' => 'ru',
                'description' => '',
                'sale_price' => null,
                'quantity' => 0,
                'unit' => 'шт.',
                'in_stock' => false,
                'is_taxable' => false,
                'is_digital' => false,
                'is_external' => false,
                'product_type' => ProductType::SIMPLE,
                'status' => ProductStatus::DRAFT,
                'internal_article' => ArticleGeneratorService::generateProductArticle(),
                'gallery' => [],
            ], $data));
            if ($categoryIds) $product->categories()->sync(array_map('intval', $categoryIds));
            if ($meta) { $product->setMeta($meta); $product->save(); }
            return $product->fresh();
        });
    }
}
