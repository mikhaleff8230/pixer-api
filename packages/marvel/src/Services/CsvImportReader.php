<?php

namespace Marvel\Services;

final class CsvImportReader
{
    public static function parse(string $content): array
    {
        $content = self::normalizeEncoding($content);
        if (trim($content) === '') {
            return [];
        }

        $semicolonRows = self::readRecords($content, ';');
        if (self::isWrappedCsv($semicolonRows)) {
            return self::parseWrappedRows($semicolonRows);
        }

        $delimiter = self::detectDelimiter($content);
        $records = self::readRecords($content, $delimiter);
        return self::combineRows($records, $delimiter);
    }

    public static function promoteFirstGalleryImage(array $product): array
    {
        $gallery = self::normalizeMediaList($product['gallery'] ?? null);
        $image = trim((string) ($product['image'] ?? ''));

        if ($image === '' && $gallery) {
            $image = array_shift($gallery);
            $product['image'] = $image;
        }

        if ($image !== '') {
            $gallery = array_values(array_filter($gallery, static fn ($url) => $url !== $image));
        }

        $product['gallery'] = $gallery;
        return $product;
    }

    private static function normalizeMediaList($media): array
    {
        if (is_string($media)) {
            $media = trim($media);
            if ($media === '') {
                return [];
            }

            $decoded = json_decode($media, true);
            if (is_array($decoded)) {
                $media = $decoded;
            } else {
                // Поддерживаем основные разделители экспортов CSV. Lookahead
                // сохраняет запятые внутри URL и подписей, если следующий
                // элемент не похож на ссылку или путь к изображению.
                $media = preg_split(
                    '/\s*(?:[;|\r\n]+|,(?=\s*(?:https?:\/\/|\/|[A-Za-z0-9_.-]+\.(?:jpe?g|png|webp|gif|avif))))\s*/i',
                    $media
                ) ?: [];
            }
        }

        if (!is_array($media)) {
            return [];
        }

        $urls = [];
        foreach ($media as $item) {
            if (is_array($item)) {
                $item = $item['original'] ?? $item['url'] ?? $item['thumbnail'] ?? null;
            }
            if (!is_scalar($item)) {
                continue;
            }
            $url = trim((string) $item);
            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }

    private static function normalizeEncoding(string $content): string
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        if (function_exists('mb_check_encoding') && !mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', ['Windows-1251', 'CP1251', 'ISO-8859-1']);
        }
        return $content;
    }

    private static function readRecords(string $content, string $delimiter): array
    {
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            return [];
        }
        fwrite($stream, $content);
        rewind($stream);

        $rows = [];
        while (($row = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            if (self::isEmptyRow($row)) {
                continue;
            }
            $rows[] = $row;
        }
        fclose($stream);
        return $rows;
    }

    private static function isWrappedCsv(array $rows): bool
    {
        if (!$rows || count($rows[0]) < 1) {
            return false;
        }
        $first = (string) ($rows[0][0] ?? '');
        $nestedHeader = str_getcsv($first, ',', '"', '');
        $trailingColumnsAreEmpty = count($rows[0]) === 1
            || count(array_filter(array_slice($rows[0], 1), static fn ($value) => trim((string) $value) !== '')) === 0;

        return $trailingColumnsAreEmpty && count($nestedHeader) > 1;
    }

    private static function parseWrappedRows(array $outerRows): array
    {
        $headerSegment = (string) array_shift($outerRows)[0];
        $headers = self::normalizeHeaders(str_getcsv($headerSegment, ',', '"', ''));
        if (!$headers) {
            return [];
        }

        $rows = [];
        $buffer = '';
        foreach ($outerRows as $outerRow) {
            $buffer .= (string) ($outerRow[0] ?? '');
            $values = str_getcsv($buffer, ',', '"', '');

            // WooCommerce/Excel может разнести одну вложенную CSV-строку по
            // нескольким внешним строкам. Собираем её до полного числа колонок.
            if (count($values) < count($headers)) {
                continue;
            }

            $rows[] = array_combine($headers, self::normalizeValues($values, count($headers), ','));
            $buffer = '';
        }

        return $rows;
    }

    private static function combineRows(array $records, string $delimiter): array
    {
        if (!$records) {
            return [];
        }
        $headers = self::normalizeHeaders(array_shift($records));
        if (!$headers) {
            return [];
        }

        $rows = [];
        foreach ($records as $values) {
            $rows[] = array_combine($headers, self::normalizeValues($values, count($headers), $delimiter));
        }
        return $rows;
    }

    private static function normalizeHeaders(array $headers): array
    {
        $seen = [];
        $result = [];
        foreach ($headers as $index => $header) {
            $base = trim((string) $header);
            $base = preg_replace('/^\xEF\xBB\xBF/', '', $base) ?? $base;
            if ($base === '') {
                $base = 'column_' . ($index + 1);
            }
            $name = $base;
            $suffix = 2;
            while (isset($seen[$name])) {
                $name = $base . '_' . $suffix++;
            }
            $seen[$name] = true;
            $result[] = $name;
        }
        return $result;
    }

    private static function normalizeValues(array $values, int $expected, string $delimiter): array
    {
        if (count($values) > $expected) {
            $values = array_merge(
                array_slice($values, 0, $expected - 1),
                [implode($delimiter, array_slice($values, $expected - 1))]
            );
        }
        $values = array_slice(array_pad($values, $expected, null), 0, $expected);
        return array_map(static function ($value) {
            if (!is_string($value)) {
                return $value;
            }
            return trim(str_replace(['\\r\\n', '\\n', '\\r'], "\n", $value));
        }, $values);
    }

    private static function detectDelimiter(string $content): string
    {
        $firstLine = strtok($content, "\r\n") ?: '';
        $scores = [];
        foreach ([',', ';', "\t"] as $delimiter) {
            $scores[$delimiter] = count(str_getcsv($firstLine, $delimiter, '"', ''));
        }
        arsort($scores);
        return (string) array_key_first($scores);
    }

    private static function isEmptyRow(array $row): bool
    {
        return count(array_filter($row, static fn ($value) => trim((string) $value) !== '')) === 0;
    }
}
