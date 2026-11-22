<?php

namespace App\Services;

use App\Models\TelegramImportChannel;

final class TelegramPricingService
{
    public function calculate(TelegramImportChannel $channel, array $normalized, ?float $markupOverride = null): ?array
    {
        $source = $channel->price_source === 'retail' ? 'retail_price' : 'wholesale_price';
        $base = $normalized[$source] ?? null;
        if ($base === null && $source === 'wholesale_price') $base = $normalized['retail_price'] ?? null;
        if ($base === null) return null;

        $markup = $markupOverride ?? (float) $channel->markup_percent;
        $raw = ((float) $base * (1 + $markup / 100)) + (float) $channel->fixed_markup;
        $roundTo = max(1, (int) $channel->round_to);
        $selling = ceil($raw / $roundTo) * $roundTo;
        if ($channel->minimum_sale_price !== null) $selling = max($selling, (float) $channel->minimum_sale_price);

        return ['source' => $source, 'base_price' => (float) $base, 'selling_price' => $selling, 'markup_percent' => $markup, 'fixed_markup' => (float) $channel->fixed_markup, 'round_to' => $roundTo, 'calculated_at' => now()->toIso8601String()];
    }
}
