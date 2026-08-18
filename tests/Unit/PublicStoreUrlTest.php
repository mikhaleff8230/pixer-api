<?php

namespace Tests\Unit;

use App\Services\PublicStoreUrl;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PublicStoreUrlTest extends TestCase
{
    #[DataProvider('baseUrlProvider')]
    public function test_it_normalizes_public_store_url(string $input, string $expected): void
    {
        $this->assertSame($expected, (new PublicStoreUrl($input))->baseUrl());
    }

    public static function baseUrlProvider(): array
    {
        return [
            ['https://sancan.ru', 'https://sancan.ru'],
            ['https://sancan.ru/', 'https://sancan.ru'],
            ['sancan.ru', 'https://sancan.ru'],
            ['https://SANCAN.RU//api', 'https://sancan.ru'],
            ['http://127.0.0.1:3000/', 'http://127.0.0.1:3000'],
        ];
    }

    public function test_product_4673_has_safe_yml_url(): void
    {
        $url = (new PublicStoreUrl('https://sancan.ru/'))->productUrl('/ru/test-product', 4673);

        $this->assertSame('https://sancan.ru/element/test-product-4673', $url);
        $this->assertStringNotContainsString('https://.sancan.ru', $url);
        $this->assertStringNotContainsString('//element', parse_url($url, PHP_URL_PATH));
    }

    public function test_path_join_never_adds_a_double_slash(): void
    {
        $url = (new PublicStoreUrl('https://sancan.ru/'))->to('//element/test-product-4673');

        $this->assertSame('https://sancan.ru/element/test-product-4673', $url);
    }

    public function test_empty_slug_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PublicStoreUrl('sancan.ru'))->productUrl('', 4673);
    }
}
