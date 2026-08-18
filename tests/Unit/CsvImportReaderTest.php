<?php

namespace Tests\Unit;

use Marvel\Services\CsvImportReader;
use PHPUnit\Framework\TestCase;

class CsvImportReaderTest extends TestCase
{
    public function test_it_reassembles_wrapped_woocommerce_rows(): void
    {
        $csv = <<<'CSV'
"ID,sku,name,""description"",Описание,price,images";
"10,SKU-10,""Товар"",""Короткое"",""Длинное описание с";
"\nпереносом"",100,""https://example.com/1.jpg, https://example.com/2.jpg""";
CSV;

        $rows = CsvImportReader::parse($csv);

        self::assertCount(1, $rows);
        self::assertSame('SKU-10', $rows[0]['sku']);
        self::assertStringContainsString('переносом', $rows[0]['Описание']);
        self::assertStringContainsString('https://example.com/2.jpg', $rows[0]['images']);
    }

    public function test_it_supports_regular_csv_with_multiline_values(): void
    {
        $csv = "sku,name,description,images\n"
            . "A-1,Товар,\"Первая строка\nВторая строка\",https://example.com/a.jpg\n";

        $rows = CsvImportReader::parse($csv);

        self::assertCount(1, $rows);
        self::assertSame("Первая строка\nВторая строка", $rows[0]['description']);
    }
}
