<?php

namespace Tests\Unit;

use App\Models\TelegramImportChannel;
use App\Services\TelegramPricingService;
use PHPUnit\Framework\TestCase;

final class TelegramPricingServiceTest extends TestCase
{
    public function test_it_applies_markup_fixed_cost_and_rounding(): void
    {
        $channel = new TelegramImportChannel(['price_source' => 'wholesale', 'markup_percent' => 35, 'fixed_markup' => 20, 'round_to' => 10]);
        $result = (new TelegramPricingService())->calculate($channel, ['wholesale_price' => 2000, 'retail_price' => 3000]);
        self::assertSame(2720.0, $result['selling_price']);
        self::assertSame(2000.0, $result['base_price']);
    }
}
