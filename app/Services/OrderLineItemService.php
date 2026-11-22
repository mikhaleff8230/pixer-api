<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Variation;
use Marvel\Enums\ProductType;

final class OrderLineItemService
{
    /**
     * Resolve sellable rows and replace all client supplied prices with server prices.
     */
    public function resolve(array $items): array
    {
        if ($items === []) {
            throw ValidationException::withMessages(['products' => 'Корзина пуста.']);
        }

        $resolved = [];
        foreach ($items as $index => $item) {
            $quantity = max(1, (int) ($item['order_quantity'] ?? 0));
            $product = Product::query()->find($item['product_id'] ?? null);
            if (!$product || $product->status !== 'publish') {
                throw ValidationException::withMessages(["products.$index" => 'Товар недоступен для покупки.']);
            }

            $variation = null;
            if ($product->product_type === ProductType::VARIABLE) {
                $variationId = $item['variation_option_id'] ?? null;
                $variation = Variation::query()
                    ->whereKey($variationId)
                    ->where('product_id', $product->id)
                    ->first();

                if (!$variation || $variation->is_disable || $variation->stock_status === 'unknown' || (int) $variation->quantity < $quantity) {
                    throw ValidationException::withMessages([
                        "products.$index.variation_option_id" => 'Выбранная вариация недоступна или закончилась.',
                    ]);
                }
                $unitPrice = (float) ($variation->sale_price ?: $variation->price);
            } else {
                if (!empty($item['variation_option_id'])) {
                    throw ValidationException::withMessages([
                        "products.$index.variation_option_id" => 'Вариация не принадлежит этому товару.',
                    ]);
                }
                if (!$product->in_stock || (int) $product->quantity < $quantity) {
                    throw ValidationException::withMessages(["products.$index" => 'Товар закончился.']);
                }
                $unitPrice = (float) ($product->sale_price ?: $product->price);
            }

            if ($unitPrice < 0) {
                throw ValidationException::withMessages(["products.$index" => 'Некорректная цена товара.']);
            }

            $resolved[] = [
                'product_id' => (int) $product->id,
                'variation_option_id' => $variation?->id,
                'order_quantity' => $quantity,
                'unit_price' => $unitPrice,
                'subtotal' => round($unitPrice * $quantity, 2),
                'variation_snapshot' => $variation ? [
                    'variation_option_id' => $variation->id,
                    'sku' => $variation->sku,
                    'title' => $variation->title,
                    'options' => $variation->options,
                    'unit_price' => $unitPrice,
                ] : null,
            ];
        }

        return $resolved;
    }

    public function total(array $items): float
    {
        return round(array_sum(array_column($items, 'subtotal')), 2);
    }
}
