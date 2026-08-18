<?php

namespace Tests\Unit;

use App\Models\YandexDirectSetting;
use App\Services\YandexDirect\YandexDirectService;
use PHPUnit\Framework\TestCase;

class YandexDirectFilterTest extends TestCase
{
    public function test_filter_is_sorted_unique_and_contains_only_requested_products(): void
    {
        $service = new YandexDirectService(new YandexDirectSetting());
        $this->assertSame([['Operand'=>'id','Operator'=>'EQUALS_ANY','Arguments'=>['4673','4680']]], $service->buildFeedFilter([4680,4673,4680]));
    }

    public function test_empty_filter_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        (new YandexDirectService(new YandexDirectSetting()))->buildFeedFilter([]);
    }
}
