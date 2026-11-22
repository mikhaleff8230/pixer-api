<?php

namespace Tests\Unit;

use App\Services\VariableProductImportNormalizer;
use PHPUnit\Framework\TestCase;

final class VariableProductImportNormalizerTest extends TestCase
{
    public function test_it_keeps_ranges_as_single_unconfirmed_designations(): void
    {
        $result = (new VariableProductImportNormalizer())->normalize([
            'source' => 'telegram',
            'name' => 'Куртка',
            'product_type' => 'variable',
            'attributes' => ['size' => '42-44, 46-48'],
        ]);

        self::assertSame('42-44, 46-48', $result['attributes']['size'][0]['normalized']);
        self::assertTrue($result['attributes']['size'][0]['is_range']);
        self::assertSame([], $result['variation_options']);
    }

    public function test_it_splits_only_unambiguous_space_separated_values(): void
    {
        $result = (new VariableProductImportNormalizer())->normalize([
            'product_type' => 'variable',
            'attributes' => ['size' => 'S M L XL', 'fit' => ['S (42–44)', 'One Size']],
        ]);

        self::assertSame(['S', 'M', 'L', 'XL'], array_column($result['attributes']['size'], 'normalized'));
        self::assertSame('S (42-44)', $result['attributes']['fit'][0]['normalized']);
        self::assertSame('One Size', $result['attributes']['fit'][1]['normalized']);
    }
}
