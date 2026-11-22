<?php

namespace Tests\Unit;

use App\Services\TelegramProductNormalizer;
use PHPUnit\Framework\TestCase;

final class TelegramProductNormalizerTest extends TestCase
{
    public function test_it_extracts_prices_sizes_colors_and_supplier_details(): void
    {
        $result = (new TelegramProductNormalizer())->normalize("🔥 Новинка\nКуртка женская зимняя\nРазмеры 42 44 46 48 50\nЦвет чёрный, беж, молочный\nОпт 2300 руб\nРозница 3500 руб\nСадовод 22-45\nЗаказ @seller");

        self::assertSame('Куртка женская зимняя', $result['name']);
        self::assertSame(2300.0, $result['wholesale_price']);
        self::assertSame(3500.0, $result['retail_price']);
        self::assertSame(['42', '44', '46', '48', '50'], $result['sizes']);
        self::assertSame(['чёрный', 'беж', 'молочный'], $result['colors']);
        self::assertSame('variable', $result['product_type']);
        self::assertSame([], $result['variation_options']);
        self::assertFalse($result['requires_review']);
    }

    public function test_it_never_invents_a_missing_price(): void
    {
        $result = (new TelegramProductNormalizer())->normalize("Платье вечернее\nРазмеры S M L");
        self::assertNull($result['wholesale_price']);
        self::assertNull($result['retail_price']);
        self::assertContains('price', $result['missing_fields']);
        self::assertTrue($result['requires_review']);
    }
}
