<?php

namespace App\Services;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class VariableProductImportNormalizer
{
    /**
     * Normalize a marketplace/Telegram variable-product draft without assuming
     * that every cartesian combination is sellable.
     */
    public function normalize(array $payload): array
    {
        if (($payload['product_type'] ?? null) !== 'variable') {
            throw ValidationException::withMessages(['product_type' => 'Ожидается тип variable.']);
        }

        $attributes = [];
        foreach ((array) ($payload['attributes'] ?? []) as $name => $rawValues) {
            $values = is_array($rawValues) ? $rawValues : $this->splitUnambiguousList((string) $rawValues);
            $attributes[Str::slug((string) $name, '_')] = array_values(array_filter(array_map(
                fn ($value) => $this->normalizeDesignation((string) $value),
                $values
            ), fn ($value) => $value['normalized'] !== ''));
        }

        return [
            'source' => trim((string) ($payload['source'] ?? 'unknown')),
            'source_post_id' => isset($payload['source_post_id']) ? (string) $payload['source_post_id'] : null,
            'name' => trim((string) ($payload['name'] ?? '')),
            'product_type' => 'variable',
            'attributes' => $attributes,
            'supplier_price' => isset($payload['supplier_price']) ? (float) $payload['supplier_price'] : null,
            'selling_price' => isset($payload['selling_price']) ? (float) $payload['selling_price'] : null,
            'currency' => strtoupper((string) ($payload['currency'] ?? 'RUB')),
            'status' => 'draft',
            // SKU создаются только из явно подтверждённых комбинаций.
            'variation_options' => $this->normalizeConfirmedCombinations((array) ($payload['variation_options'] ?? [])),
        ];
    }

    public function normalizeDesignation(string $raw): array
    {
        $original = trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw);
        $normalized = str_replace(['–', '—'], '-', $original);
        $normalized = preg_replace('/\s*-\s*/u', '-', $normalized) ?? $normalized;
        if (preg_match('/^one\s*size$/iu', $normalized)) {
            $normalized = 'One Size';
        } elseif (preg_match('/^[a-z]{1,4}$/iu', $normalized)) {
            $normalized = strtoupper($normalized);
        }

        return [
            'original' => $original,
            'normalized' => $normalized,
            'is_range' => (bool) preg_match('/\p{L}|\d/u', $normalized) && str_contains($normalized, '-'),
        ];
    }

    private function splitUnambiguousList(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '' || preg_match('/[()\-–—,]/u', $raw)) {
            return [$raw];
        }
        return preg_split('/\s+/u', $raw) ?: [$raw];
    }

    private function normalizeConfirmedCombinations(array $combinations): array
    {
        return array_values(array_map(function (array $combination) {
            return [
                'attributes' => (array) ($combination['attributes'] ?? []),
                'sku' => isset($combination['sku']) ? (string) $combination['sku'] : null,
                'price' => isset($combination['price']) ? (float) $combination['price'] : null,
                'quantity' => array_key_exists('quantity', $combination) ? (int) $combination['quantity'] : null,
                'stock_status' => $combination['stock_status'] ?? 'unknown',
                'is_disable' => !($combination['confirmed_available'] ?? false),
            ];
        }, $combinations));
    }
}
