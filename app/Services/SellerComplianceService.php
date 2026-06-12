<?php

namespace App\Services;

use App\Models\ProductOriginType;
use App\Models\SellerTaxStatus;
use Illuminate\Support\Collection;

class SellerComplianceService
{
    private array $allowedOrigins = [
        'private_person' => ['personal_used', 'personal_new'],
        'unknown' => ['personal_used', 'personal_new'],
        'self_employed' => ['handmade'],
        'individual_entrepreneur' => ['resale', 'shop_stock', 'handmade', 'personal_used'],
        'company' => ['personal_used', 'personal_new', 'handmade', 'resale', 'shop_stock'],
    ];

    public function __construct(private SellerBalanceService $balanceService)
    {
    }

    public function canPublishProduct($seller, $product): bool
    {
        return empty($this->getWarnings($seller, $product));
    }

    public function validateProductOrigin($seller, $product): bool
    {
        $sellerStatus = $this->resolveSellerTaxStatusCode($seller);
        $originCode = $this->resolveProductOriginCode($product);

        if (!$originCode) {
            return false;
        }

        return in_array($originCode, $this->allowedOrigins[$sellerStatus] ?? [], true);
    }

    public function getWarnings($seller, $product): array
    {
        $warnings = [];
        $sellerStatus = $this->resolveSellerTaxStatusCode($seller);
        $originCode = $this->resolveProductOriginCode($product);
        $balance = $this->balanceService->getCurrentBalance($seller);

        if (($seller->seller_status ?? 'inactive') === 'blocked') {
            $warnings[] = 'Продавец заблокирован и не может публиковать товары.';
        }

        if (($seller->seller_status ?? 'inactive') === 'limited' || $balance <= 0) {
            $warnings[] = 'Баланс продавца недостаточен для публикации и продвижения.';
        }

        if (!$originCode) {
            $warnings[] = 'Не выбран тип происхождения товара.';
        } elseif (!in_array($originCode, $this->allowedOrigins[$sellerStatus] ?? [], true)) {
            $warnings[] = 'Выбранный тип происхождения товара не разрешен для текущего статуса продавца.';
        }

        if (empty($seller->seller_agreed_tax_responsibility)) {
            $warnings[] = 'Продавец должен принять ответственность за налоги и происхождение товара.';
        }

        return $warnings;
    }

    public function allowedOriginsForStatus(string $sellerTaxStatusCode): Collection
    {
        return ProductOriginType::whereIn('code', $this->allowedOrigins[$sellerTaxStatusCode] ?? [])->get();
    }

    private function resolveSellerTaxStatusCode($seller): string
    {
        if (!empty($seller->seller_tax_status_id)) {
            $status = SellerTaxStatus::find($seller->seller_tax_status_id);
            if ($status) {
                return $status->code;
            }
        }

        return 'unknown';
    }

    private function resolveProductOriginCode($product): ?string
    {
        if (!empty($product->product_origin_type_id)) {
            $origin = ProductOriginType::find($product->product_origin_type_id);
            return $origin?->code;
        }

        return null;
    }
}