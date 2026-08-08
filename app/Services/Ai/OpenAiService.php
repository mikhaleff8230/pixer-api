<?php

namespace App\Services\Ai;

use GuzzleHttp\Client as HttpClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Marvel\Database\Models\Product;
use OpenAI;
use RuntimeException;

class OpenAiService
{
    private ?OpenAI\Client $client = null;

    public function generateDescription(Product $product, array $context): array
    {
        $imageUrl = (string) ($context['input_image_url'] ?? '');
        $productName = (string) ($product->name ?? $context['product_name'] ?? 'Товар');
        $price = $context['product_price'] ?? $product->price;

        $prompt = <<<PROMPT
Ты помощник маркетплейса SANCAN (second-hand / б/у товары).
Сгенерируй карточку товара на русском языке.
Верни строго JSON с полями:
- title (string, до 120 символов)
- description (string, 2-4 абзаца, честно про состояние)
- category (string|null)
- tags (string[], 3-8 тегов)
- condition (string|null)
- recommendations (string[], 3-5 советов продавцу)

Товар: {$productName}
Текущая цена: {$price}
Текущее описание: {$product->description}
PROMPT;

        $parsed = $this->chatJson(
            'Ты опытный копирайтер карточек товаров. Отвечай только валидным JSON.',
            $prompt,
            $this->textModel($context),
            $imageUrl ?: null
        );

        return [
            'title' => $parsed['title'] ?? $productName,
            'description' => $parsed['description'] ?? (string) $product->description,
            'category' => $parsed['category'] ?? null,
            'tags' => $parsed['tags'] ?? [],
            'condition' => $parsed['condition'] ?? null,
            'recommendations' => $parsed['recommendations'] ?? [],
        ];
    }

    public function analyzePhoto(string $imageUrl, array $context): array
    {
        if ($imageUrl === '') {
            throw new RuntimeException('Для анализа фото нужен URL изображения.');
        }

        $parsed = $this->chatJson(
            'Ты эксперт по оценке фото товаров для маркетплейса. Отвечай только валидным JSON.',
            'Проанализируй фото товара. Верни JSON: item_type, color, category_hint, visible_defects (string[]), has_tag (bool|null), photo_quality (good|average|poor), warnings (string[]).',
            $this->visionModel($context),
            $imageUrl
        );

        return array_merge([
            'image_url' => $imageUrl,
            'item_type' => 'unknown',
            'color' => 'unknown',
            'category_hint' => null,
            'visible_defects' => [],
            'has_tag' => null,
            'photo_quality' => 'average',
            'warnings' => [],
        ], $parsed);
    }

    public function enhancePhoto(string $imageUrl, array $context): array
    {
        return $this->editProductPhoto(
            $imageUrl,
            'Улучши свет, резкость и чистоту фото товара для карточки маркетплейса. Не меняй форму, цвет и детали товара. Без водяных знаков.',
            'photo_enhance',
            'Улучшены свет, резкость и кадрирование без изменения товара.',
            $context
        );
    }

    public function whiteBackground(string $imageUrl, array $context): array
    {
        return $this->editProductPhoto(
            $imageUrl,
            'Убери фон и помести товар на чистый белый фон. Не изменяй сам товар, его пропорции, цвет и фактуру.',
            'photo_white_background',
            'Подготовлен белый фон без изменения формы, цвета и фактуры товара.',
            $context
        );
    }

    public function marketplaceCrop(string $imageUrl, array $context): array
    {
        if ($imageUrl === '') {
            throw new RuntimeException('Для обрезки нужен URL изображения.');
        }

        if (!function_exists('imagecreatefromstring')) {
            throw new RuntimeException('Расширение GD не установлено для обрезки фото.');
        }

        $binary = $this->downloadImage($imageUrl);
        $source = @imagecreatefromstring($binary);
        if ($source === false) {
            throw new RuntimeException('Не удалось прочитать исходное изображение.');
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $targetRatio = 3 / 4;

        if ($width / max(1, $height) > $targetRatio) {
            $newWidth = (int) round($height * $targetRatio);
            $newHeight = $height;
            $srcX = (int) floor(($width - $newWidth) / 2);
            $srcY = 0;
        } else {
            $newWidth = $width;
            $newHeight = (int) round($width / $targetRatio);
            $srcX = 0;
            $srcY = (int) floor(($height - $newHeight) / 2);
        }

        $canvas = imagecreatetruecolor(1200, 1600);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);
        imagecopyresampled($canvas, $source, 0, 0, $srcX, $srcY, 1200, 1600, $newWidth, $newHeight);

        ob_start();
        imagejpeg($canvas, null, 90);
        $outputBinary = (string) ob_get_clean();
        imagedestroy($source);
        imagedestroy($canvas);

        $outputUrl = $this->storePublicImage($outputBinary, 'jpg');

        return [
            'operation' => 'photo_marketplace_crop',
            'original_image_url' => $imageUrl,
            'output_image_url' => $outputUrl,
            'note' => 'Фото центрировано и подготовлено под вертикальную карточку маркетплейса.',
            'requires_seller_apply' => true,
        ];
    }

    public function estimatePrice(Product $product, array $context): array
    {
        $imageUrl = (string) ($context['input_image_url'] ?? '');
        $prompt = sprintf(
            "Оцени цену б/у товара для маркетплейса SANCAN.\nНазвание: %s\nОписание: %s\nТекущая цена продавца: %s\nВерни JSON: quick_sale_price (number), regular_price (number), max_price (number), reason (string).",
            (string) $product->name,
            (string) ($product->description ?? ''),
            (string) ($product->price ?? 'не указана')
        );

        $parsed = $this->chatJson(
            'Ты оценщик second-hand товаров. Дай реалистичные цены в рублях. Отвечай только валидным JSON.',
            $prompt,
            $this->textModel($context),
            $imageUrl ?: null
        );

        return [
            'quick_sale_price' => (float) ($parsed['quick_sale_price'] ?? $parsed['fast_sale_price'] ?? 0),
            'regular_price' => (float) ($parsed['regular_price'] ?? 0),
            'max_price' => (float) ($parsed['max_price'] ?? 0),
            'reason' => (string) ($parsed['reason'] ?? 'Оценка сформирована на основе данных товара.'),
        ];
    }

    private function editProductPhoto(
        string $imageUrl,
        string $prompt,
        string $operation,
        string $note,
        array $context
    ): array {
        if ($imageUrl === '') {
            throw new RuntimeException('Для обработки фото нужен URL изображения.');
        }

        $model = $this->imageModel($context);
        $binary = $this->requestImageEdit($imageUrl, $prompt, $model);
        $outputUrl = $this->storePublicImage($binary, 'png');

        return [
            'operation' => $operation,
            'original_image_url' => $imageUrl,
            'output_image_url' => $outputUrl,
            'note' => $note,
            'model' => $model,
            'requires_seller_apply' => true,
        ];
    }

    private function requestImageEdit(string $imageUrl, string $prompt, string $model): string
    {
        $tempPng = $this->imageUrlToPngTempFile($imageUrl);

        try {
            return $this->postImageEdit($tempPng, $prompt, $model);
        } catch (\Throwable $primaryError) {
            $fallback = (string) config('ai.openai.image_fallback_model', 'dall-e-2');
            if ($fallback !== '' && $fallback !== $model) {
                Log::warning('OpenAI image edit fallback', [
                    'model' => $model,
                    'fallback' => $fallback,
                    'error' => $primaryError->getMessage(),
                ]);

                return $this->postImageEdit($tempPng, $prompt, $fallback);
            }

            throw $primaryError;
        } finally {
            if (is_file($tempPng)) {
                @unlink($tempPng);
            }
        }
    }

    private function postImageEdit(string $pngPath, string $prompt, string $model): string
    {
        $http = new HttpClient([
            'base_uri' => 'https://api.openai.com/v1/',
            'timeout' => 120,
        ]);

        $response = $http->post('images/edits', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey(),
            ],
            'multipart' => [
                ['name' => 'model', 'contents' => $model],
                ['name' => 'prompt', 'contents' => $prompt],
                ['name' => 'image', 'contents' => fopen($pngPath, 'r'), 'filename' => 'image.png'],
                ['name' => 'size', 'contents' => '1024x1024'],
            ],
        ]);

        $payload = json_decode((string) $response->getBody(), true);
        $item = $payload['data'][0] ?? null;

        if (!empty($item['b64_json'])) {
            $binary = base64_decode((string) $item['b64_json'], true);
            if ($binary !== false) {
                return $binary;
            }
        }

        if (!empty($item['url'])) {
            return $this->downloadImage((string) $item['url']);
        }

        throw new RuntimeException('OpenAI не вернул изображение.');
    }

    private function chatJson(string $system, string $user, string $model, ?string $imageUrl = null): array
    {
        $content = $user;
        if ($imageUrl) {
            $content = [
                ['type' => 'text', 'text' => $user],
                ['type' => 'image_url', 'image_url' => ['url' => $imageUrl]],
            ];
        }

        $response = $this->client()->chat()->create([
            'model' => $model,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $content],
            ],
            'temperature' => 0.4,
        ]);

        $raw = trim((string) ($response->choices[0]->message->content ?? ''));
        if ($raw === '') {
            throw new RuntimeException('OpenAI вернул пустой ответ.');
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('OpenAI вернул некорректный JSON.');
        }

        return $decoded;
    }

    private function client(): OpenAI\Client
    {
        if ($this->client === null) {
            $this->client = OpenAI::client($this->apiKey());
        }

        return $this->client;
    }

    private function apiKey(): string
    {
        $key = (string) (config('ai.openai.api_key') ?: config('shop.openai.secret_Key'));
        if ($key === '') {
            throw new RuntimeException('OPENAI_SECRET_KEY не настроен в .env');
        }

        return $key;
    }

    private function textModel(array $context): string
    {
        return $this->normalizeModel((string) ($context['model'] ?? ''), (string) config('ai.openai.text_model', 'gpt-4o-mini'));
    }

    private function visionModel(array $context): string
    {
        return $this->normalizeModel((string) ($context['model'] ?? ''), (string) config('ai.openai.vision_model', 'gpt-4o-mini'));
    }

    private function imageModel(array $context): string
    {
        return $this->normalizeModel((string) ($context['model'] ?? ''), (string) config('ai.openai.image_model', 'gpt-image-1'), true);
    }

    private function normalizeModel(string $fromService, string $default, bool $image = false): string
    {
        if ($fromService === '') {
            return $default;
        }

        return match ($fromService) {
            'gpt-image-mini' => (string) config('ai.openai.image_model', 'gpt-image-1'),
            'gpt-4.1-mini' => $image ? (string) config('ai.openai.vision_model', 'gpt-4o-mini') : (string) config('ai.openai.text_model', 'gpt-4o-mini'),
            'image_processor' => $default,
            default => $fromService,
        };
    }

    private function downloadImage(string $url): string
    {
        $http = new HttpClient(['timeout' => 60]);
        $response = $http->get($url);

        $binary = (string) $response->getBody();
        if ($binary === '') {
            throw new RuntimeException('Не удалось скачать изображение.');
        }

        return $binary;
    }

    private function imageUrlToPngTempFile(string $imageUrl): string
    {
        if (!function_exists('imagecreatefromstring')) {
            throw new RuntimeException('Расширение GD не установлено.');
        }

        $binary = $this->downloadImage($imageUrl);
        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            throw new RuntimeException('Не удалось декодировать исходное изображение.');
        }

        $tempPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sancan-ai-' . Str::uuid() . '.png';
        imagepng($image, $tempPath);
        imagedestroy($image);

        return $tempPath;
    }

    private function storePublicImage(string $binary, string $extension): string
    {
        $diskName = config('filesystems.disks.s3.bucket')
            ? 's3'
            : (string) config('filesystems.default', 'public');
        $path = 'ai-results/' . date('Y/m') . '/' . Str::uuid() . '.' . ltrim($extension, '.');

        Storage::disk($diskName)->put($path, $binary, [
            'visibility' => 'public',
            'ContentType' => 'image/' . ($extension === 'jpg' ? 'jpeg' : $extension),
            'CacheControl' => 'public, max-age=31536000, immutable',
        ]);

        return \App\Support\MediaUrl::publicUrl($path)
            ?: Storage::disk($diskName)->url($path);
    }
}
