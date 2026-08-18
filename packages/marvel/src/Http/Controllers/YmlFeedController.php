<?php

namespace Marvel\Http\Controllers;

use App\Services\PublicStoreUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Category;

class YmlFeedController extends CoreController
{
    public function __construct(private readonly PublicStoreUrl $publicStoreUrl)
    {
    }

    public function index(Request $request, $page = null)
    {
        // Параметры постраничной загрузки
        $perPage = 1000;
        $page = $page ? ((int) $page > 0 ? (int) $page : 1) : 1;

        // Загружаем продукты с категориями
        $products = Product::with('categories')
            ->where('language', 'ru')
            ->where('status', 'publish')
            ->whereNotNull('slug')
            ->where('slug', '!=', '')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        // Загружаем категории
        $categories = Category::where('language', 'ru')->get();

        // Создаём XML-документ
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><yml_catalog date="' . now()->format('Y-m-d H:i') . '"></yml_catalog>');
        $shop = $xml->addChild('shop');
        $shop->addChild('name', 'SANCAN.ru');
        $shop->addChild('company', 'ООО "САНКЭН"');
        $shop->addChild('url', $this->publicStoreUrl->baseUrl());

        // Валюта
        $currencies = $shop->addChild('currencies');
        $currency = $currencies->addChild('currency');
        $currency->addAttribute('id', 'RUR');
        $currency->addAttribute('rate', '1');

        // Категории
        $categoriesXml = $shop->addChild('categories');
        foreach ($categories as $category) {
            $cat = $categoriesXml->addChild('category', htmlspecialchars($category->name));
            $cat->addAttribute('id', $category->id);
            if ($category->parent_id) {
                $cat->addAttribute('parentId', $category->parent_id);
            }
        }

        // Офферы (товары)
        $offers = $shop->addChild('offers');
        foreach ($products as $product) {
            $category = $product->categories->first();

            // Пропускаем товар без категории
            if (!$category) {
                continue;
            }

            $offer = $offers->addChild('offer');
            $offer->addAttribute('id', $product->id);
            
            // Доступность товара
            $available = (!empty($product->in_stock) && $product->in_stock > 0) ? 'true' : 'false';
            $offer->addAttribute('available', $available);

            // URL товара - используем формат /element/{slug}-{id}
            $cleanSlug = $product->slug;
            if (strpos($cleanSlug, '/ru/') === 0) {
                $cleanSlug = substr($cleanSlug, 4);
            }
            $offer->addChild('url', $this->publicStoreUrl->productUrl($cleanSlug, $product->id));

            // Цена: если есть sale_price, используем его как основную цену, иначе price
            $currentPrice = !empty($product->sale_price) && $product->sale_price > 0 
                ? $product->sale_price 
                : $product->price;
            $offer->addChild('price', $currentPrice);

            // Старая цена (если есть скидка и sale_price меньше price)
            if (!empty($product->sale_price) && $product->sale_price < $product->price && $product->price > 0) {
                $offer->addChild('oldprice', $product->price);
            }

            // Валюта
            $offer->addChild('currencyId', 'RUR');

            // Категория
            $offer->addChild('categoryId', $category->id);

            // Артикул (SKU)
            if (!empty($product->sku)) {
                $offer->addChild('vendorCode', htmlspecialchars($product->sku));
            }

            // Поиск изображения
            $image = null;
            if (is_string($product->image)) {
                $decoded = json_decode($product->image, true);

                // Пробуем декодировать JSON
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    if (isset($decoded[0]['original']) && filter_var($decoded[0]['original'], FILTER_VALIDATE_URL)) {
                        $image = $decoded[0]['original'];
                    }
                }
                // Если просто строка — и это валидный URL
                elseif (filter_var($product->image, FILTER_VALIDATE_URL)) {
                    $image = $product->image;
                }
            }

            // Добавляем картинку или комментарий, если не найдена
            if ($image) {
                $offer->addChild('picture', $image);
            } else {
                $domOffer = dom_import_simplexml($offer);
                $domDoc = $domOffer->ownerDocument;
                $domOffer->appendChild($domDoc->createComment(" no picture for ID {$product->id} "));
            }

            // Название и описание
            $offer->addChild('name', htmlspecialchars($product->name));
            $offer->addChild('description', htmlspecialchars(strip_tags($product->description)));
        }

        // Отдаём XML-ответ
        return Response::make($xml->asXML(), 200, [
            'Content-Type' => 'application/xml'
        ]);
    }
}
