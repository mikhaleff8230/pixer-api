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

    public function test_it_promotes_first_delimited_gallery_image(): void
    {
        $product = CsvImportReader::promoteFirstGalleryImage([
            'gallery' => "https://example.com/1.jpg; https://example.com/2.jpg|\nhttps://example.com/3.jpg",
        ]);

        self::assertSame('https://example.com/1.jpg', $product['image']);
        self::assertSame([
            'https://example.com/2.jpg',
            'https://example.com/3.jpg',
        ], $product['gallery']);
    }

    public function test_it_promotes_first_image_from_json_or_array_gallery(): void
    {
        $fromJson = CsvImportReader::promoteFirstGalleryImage([
            'gallery' => '["https://example.com/a.jpg","https://example.com/b.jpg"]',
        ]);
        $fromArray = CsvImportReader::promoteFirstGalleryImage([
            'gallery' => ['https://example.com/c.jpg', 'https://example.com/d.jpg'],
        ]);

        self::assertSame('https://example.com/a.jpg', $fromJson['image']);
        self::assertSame(['https://example.com/b.jpg'], $fromJson['gallery']);
        self::assertSame('https://example.com/c.jpg', $fromArray['image']);
        self::assertSame(['https://example.com/d.jpg'], $fromArray['gallery']);
    }

    public function test_it_preserves_existing_main_image_without_gallery_duplicate(): void
    {
        $product = CsvImportReader::promoteFirstGalleryImage([
            'image' => 'https://example.com/main.jpg',
            'gallery' => 'https://example.com/main.jpg, https://example.com/other.jpg',
        ]);

        self::assertSame('https://example.com/main.jpg', $product['image']);
        self::assertSame(['https://example.com/other.jpg'], $product['gallery']);
    }
}
