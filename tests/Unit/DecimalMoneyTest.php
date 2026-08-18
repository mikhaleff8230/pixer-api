<?php

namespace Tests\Unit;

use App\Services\DecimalMoney;
use PHPUnit\Framework\TestCase;

class DecimalMoneyTest extends TestCase
{
    public function test_thirty_percent_markup(): void
    {
        $this->assertSame('130.00', DecimalMoney::addMarkup('100.00', '30.00'));
    }

    public function test_fractional_cost_is_rounded_to_kopecks(): void
    {
        $this->assertSame('33.07', DecimalMoney::addMarkup('25.437', '30.00'));
    }

    public function test_configured_twenty_percent_markup(): void
    {
        $this->assertSame('120.00', DecimalMoney::addMarkup('100.00', '20.00'));
    }
}
