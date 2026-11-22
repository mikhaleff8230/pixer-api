<?php

namespace Tests\Unit;

use Illuminate\Validation\ValidationException;
use Marvel\Http\Controllers\OzonImportController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class OzonImportUrlTest extends TestCase
{
    public function test_it_normalizes_an_ozon_product_url(): void
    {
        $result = $this->normalize('https://www.ozon.ru/product/nazvanie-123456789/?utm_source=test', '123456789');

        $this->assertSame(['https://www.ozon.ru/product/123456789/', '123456789'], $result);
    }

    /**
     * @dataProvider liveOzonUrlProvider
     */
    public function test_it_normalizes_current_ozon_url_shapes(string $url, string $id): void
    {
        $this->assertSame(['https://www.ozon.ru/product/'.$id.'/', $id], $this->normalize($url, $id));
    }

    public static function liveOzonUrlProvider(): array
    {
        return [
            ['https://www.ozon.ru/product/smazka-silikonovaya-lavr-100-ml-427420982/?at=test', '427420982'],
            ['https://www.ozon.ru/product/tualetnaya-bumaga-zemma-12-rulonov-933027806/?at=test', '933027806'],
            ['https://www.ozon.ru/product/gel-dlya-stirki-weissgauff-5942595401/?at=test', '5942595401'],
        ];
    }

    public function test_it_rejects_a_non_ozon_host(): void
    {
        $this->expectException(ValidationException::class);
        $this->normalize('https://example.com/product/item-123456789/', '123456789');
    }

    public function test_it_rejects_a_mismatched_product_id(): void
    {
        $this->expectException(ValidationException::class);
        $this->normalize('https://www.ozon.ru/product/item-123456789/', '987654321');
    }

    private function normalize(string $url, ?string $id): array
    {
        $method = new ReflectionMethod(OzonImportController::class, 'normalizeOzonUrl');
        $method->setAccessible(true);

        return $method->invoke(new OzonImportController(), $url, $id);
    }
}
