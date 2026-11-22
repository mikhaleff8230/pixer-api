<?php

namespace App\Services;

use Illuminate\Support\Str;

final class TelegramProductNormalizer
{
    public function normalize(string $text): array
    {
        $original = trim($text);
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $original) ?: [])));
        $evidence = [];

        [$wholesale, $wholesaleEvidence] = $this->price($original, '/(?:опт(?:овая|ом)?|оптовая\s+цена)\D{0,20}(\d[\d\s.,]*)/iu');
        [$retail, $retailEvidence] = $this->price($original, '/(?:розниц[аыеу]|розничная\s+цена)\D{0,20}(\d[\d\s.,]*)/iu');
        if ($wholesaleEvidence) $evidence['wholesale_price'] = $wholesaleEvidence;
        if ($retailEvidence) $evidence['retail_price'] = $retailEvidence;
        if ($wholesale === null && $retail === null) {
            [$generic, $genericEvidence] = $this->price($original, '/(?:цена|стоимость)\D{0,15}(\d[\d\s.,]*)/iu');
            $retail = $generic;
            if ($genericEvidence) $evidence['retail_price'] = $genericEvidence;
        }

        $sizes = $this->values($original, '/(?:размеры?|р-ры|разм\.?)[\s:—-]*([^\n]+)/iu', true);
        $colors = $this->values($original, '/(?:цвета?|расцветки?)[\s:—-]*([^\n]+)/iu', false);
        $title = $this->title($lines);
        $article = $this->capture($original, '/(?:артикул|арт\.?|sku)[\s:#№-]*([\pL\d._-]+)/iu');
        $contact = $this->capture($original, '/(@[A-Za-z0-9_]{4,})/u');
        $place = $this->capture($original, '/((?:садовод|тц|рынок|павильон|место)\s*[^\n,;]{1,40})/iu');
        $minimumBatch = $this->capture($original, '/(?:от|min(?:imum)?)[\s:]*(\d+)\s*(?:шт|ед|штук)/iu');

        $missing = [];
        if (!$title) $missing[] = 'name';
        if ($wholesale === null && $retail === null) $missing[] = 'price';

        return [
            'name' => $title,
            'description' => $this->description($lines),
            'wholesale_price' => $wholesale,
            'retail_price' => $retail,
            'minimum_batch' => $minimumBatch ? (int) $minimumBatch : null,
            'sizes' => $sizes,
            'colors' => $colors,
            'article' => $article,
            'supplier_contact' => $contact,
            'trading_place' => $place,
            'product_type' => ($sizes || $colors) ? 'variable' : 'simple',
            'variation_options' => [],
            'missing_fields' => $missing,
            'requires_review' => count($missing) > 0,
            'confidence' => $missing ? 0.45 : (($sizes || $colors) ? 0.9 : 0.82),
            'evidence' => $evidence,
            'original_text' => $original,
        ];
    }

    private function price(string $text, string $pattern): array
    {
        if (!preg_match($pattern, $text, $m)) return [null, null];
        $value = (float) str_replace(',', '.', preg_replace('/\s+/u', '', $m[1]));
        return [$value > 0 ? $value : null, trim($m[0])];
    }

    private function values(string $text, string $pattern, bool $sizes): array
    {
        if (!preg_match($pattern, $text, $m)) return [];
        $raw = trim(preg_replace('/(?:опт|розниц|цена|₽|руб).*$/iu', '', $m[1]));
        $parts = preg_split('/\s*[,;|\/]\s*|\s{2,}/u', $raw) ?: [];
        if (count($parts) === 1 && $sizes && !preg_match('/\d\s*[-–]\s*\d/u', $raw)) {
            $parts = preg_split('/\s+/u', $raw) ?: [];
        }
        return array_values(array_unique(array_filter(array_map(function ($value) use ($sizes) {
            $value = trim(str_replace('–', '-', $value));
            if ($sizes) return preg_match('/^(?:\d{1,3}(?:-\d{1,3})?|XX?S|XX?L|[SML]|ONE\s*SIZE)$/iu', $value) ? Str::upper($value) : null;
            return mb_strlen($value) <= 40 ? mb_strtolower($value) : null;
        }, $parts))));
    }

    private function title(array $lines): ?string
    {
        foreach ($lines as $line) {
            $clean = trim(preg_replace('/^[\p{So}\p{Sk}\s]+/u', '', $line));
            if ($clean === '' || preg_match('/^(?:новинка|хит|sale|акция|в наличии)[!\s.]*$/iu', $clean)
                || preg_match('/(?:цена|опт|розниц|размер|цвет|заказ|@|http)/iu', $clean)) continue;
            return Str::limit($clean, 255, '');
        }
        return null;
    }

    private function description(array $lines): string
    {
        return implode("\n", array_values(array_filter($lines, fn ($line) => !preg_match('/^(?:заказ|подписывай|наш канал|http)/iu', $line))));
    }

    private function capture(string $text, string $pattern): ?string
    {
        return preg_match($pattern, $text, $m) ? trim($m[1]) : null;
    }
}
