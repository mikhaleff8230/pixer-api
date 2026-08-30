<?php

namespace Marvel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Marvel\Enums\ProductStatus;
use Marvel\Enums\ProductType;

class ProductUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Prepare the data for validation.
     * Обрабатываем FormData ДО валидации
     */
    protected function prepareForValidation()
    {
        // Обрабатываем FormData - парсим JSON строки
        $requestData = $this->all();
        
        // ВАЖНО: Логируем для отладки
        \Log::info('ProductUpdateRequest::prepareForValidation - request data', [
            'all_keys' => array_keys($requestData),
            'all_files_keys' => array_keys($this->allFiles()),
            'hasFile_video' => $this->hasFile('video'),
            'has_video' => $this->has('video'),
            'file_video' => $this->file('video') ? 'EXISTS' : 'NULL',
            'product_type' => $requestData['product_type'] ?? 'NOT_SET',
            'product_type_type' => isset($requestData['product_type']) ? gettype($requestData['product_type']) : 'NOT_SET',
            'has_variations' => isset($requestData['variations']),
            'variations_raw' => $requestData['variations'] ?? 'NOT_SET',
            'variations_type' => isset($requestData['variations']) ? gettype($requestData['variations']) : 'NOT_SET',
            'has_variation_options' => isset($requestData['variation_options']),
            'variation_options_raw' => $requestData['variation_options'] ?? 'NOT_SET',
            'variation_options_type' => isset($requestData['variation_options']) ? gettype($requestData['variation_options']) : 'NOT_SET',
            'content_type' => $this->header('Content-Type'),
            'request_method' => $this->method(),
        ]);
        
        foreach ($requestData as $key => $value) {
            // product_type должен быть строкой, не парсим его
            if ($key === 'product_type') {
                // Убеждаемся что product_type это строка
                if (is_string($value) && $value !== '') {
                    // Если это валидная строка, оставляем как есть
                    continue;
                }
                // Если это объект или массив, извлекаем value
                if (is_array($value) && isset($value['value'])) {
                    $this->merge([$key => $value['value']]);
                } elseif (is_string($value) && $value === '') {
                    // Если пустая строка, используем дефолтное значение
                    $this->merge([$key => 'simple']);
                }
                continue;
            }
            // Парсим JSON строки из FormData
            if (is_string($value) && (str_starts_with($value, '{') || str_starts_with($value, '['))) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $this->merge([$key => $decoded]);
                }
            }
        }
        
        // ВАЖНО: Если product_type все еще отсутствует, устанавливаем дефолтное значение
        if (!isset($requestData['product_type']) || $requestData['product_type'] === null || $requestData['product_type'] === '') {
            // Пытаемся получить из существующего товара, если это обновление
            // Сначала проверяем id в FormData, потом в route
            $productId = null;
            if (isset($requestData['id']) && !empty($requestData['id'])) {
                $productId = $requestData['id'];
            } elseif ($this->route('id')) {
                $productId = $this->route('id');
            }
            
            if ($productId) {
                try {
                    $product = \Marvel\Database\Models\Product::find($productId);
                    if ($product && $product->product_type) {
                        $this->merge(['product_type' => $product->product_type]);
                    } else {
                        $this->merge(['product_type' => 'simple']);
                    }
                } catch (\Exception $e) {
                    \Log::warning('ProductUpdateRequest::prepareForValidation - error getting product type from product', [
                        'product_id' => $productId,
                        'error' => $e->getMessage(),
                    ]);
                    $this->merge(['product_type' => 'simple']);
                }
            } else {
                // Если нет id, используем дефолтное значение
                $this->merge(['product_type' => 'simple']);
            }
        }
        
        // Логируем результат
        \Log::info('ProductUpdateRequest::prepareForValidation - after processing', [
            'product_type' => $this->input('product_type'),
            'variations' => $this->input('variations'),
            'variations_type' => gettype($this->input('variations')),
            'variation_options' => $this->input('variation_options'),
            'variation_options_type' => gettype($this->input('variation_options')),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $productStatus = [
            ProductStatus::UNDER_REVIEW,
            ProductStatus::APPROVED,
            ProductStatus::REJECTED,
            ProductStatus::PUBLISH,
            ProductStatus::UNPUBLISH,
            ProductStatus::DRAFT,
        ];

        $productType = [
            ProductType::SIMPLE,
            ProductType::VARIABLE
        ];
        return [
            'name'                         => ['string', 'max:255'],
            'price'                        => ['nullable', 'numeric'],
            'sale_price'                   => ['nullable', 'lte:price'],
            'type_id'                      => ['nullable', 'exists:Marvel\Database\Models\Type,id'],
            'shop_id'                      => ['exists:Marvel\Database\Models\Shop,id'],
            'manufacturer_id'              => ['nullable', 'exists:Marvel\Database\Models\Manufacturer,id'],
            'author_id'                    => ['nullable', 'exists:Marvel\Database\Models\Author,id'],
            'categories'                   => ['exists:Marvel\Database\Models\Category,id'],
            'tags'                         => ['array'], // Могут быть ID или объекты с name для новых тегов
            'tags.*'                       => ['nullable'], // Каждый элемент может быть ID (число) или объект {name: string}
            'dropoff_locations'            => ['array'],
            'pickup_locations'             => ['array'],
            'language'                     => ['nullable', 'string'],
            'digital_file'                 => ['array'],
            'variations'                   => ['array'],
            'variation_options'            => ['array'],
            'variation_options.upsert'     => ['array'],
            'variation_options.upsert.*.price' => ['required_with:variation_options.upsert', 'numeric', 'min:0'],
            'variation_options.upsert.*.quantity' => ['required_with:variation_options.upsert', 'integer', 'min:0'],
            'variation_options.upsert.*.sku' => ['required_with:variation_options.upsert', 'string'],
            'variation_options.upsert.*.sale_price' => ['nullable', 'numeric', 'min:0'],
            'variation_options.upsert.*.options' => ['required_with:variation_options.upsert', 'array'],
            'variation_options.delete'     => ['array'],
            'product_type'                 => ['required', Rule::in($productType)],
            'unit'                         => ['string'],
            'description'                  => ['nullable', 'string'],
            'quantity'                     => ['nullable', 'integer'],
            'sku'                          => ['nullable', 'string', Rule::unique('variation_options')->where(fn ($query) => $query->whereSku($this->sku))],
            'image'                        => ['array'],
            'gallery'                      => ['array'],
            'video'                        => ['nullable', 'sometimes', 'file', 'mimes:mp4,mpeg,mov,avi,wmv,webm,ogv', 'max:40960'], // 40MB максимум
            'video_as_cover'               => ['nullable', 'boolean'],
            'status'                       => ['string', Rule::in($productStatus)],
            'height'                       => ['nullable', 'string'],
            'length'                       => ['nullable', 'string'],
            'width'                        => ['nullable', 'string'],
            'weight'                       => ['nullable', 'numeric', 'min:0'],
            'preview_url'                  => ['nullable', 'string'],
            'external_product_url'         => ['nullable', 'string'],
            'external_product_button_text' => ['nullable', 'string'],
            'in_stock'                     => ['boolean'],
            'is_taxable'                   => ['boolean'],
            'is_digital'                   => ['boolean'],
            'digital_product_type'         => ['nullable', 'string', 'max:50', Rule::in(['file', 'prompt', 'link', 'account', 'subscription', 'key'])],
            'digital_license_keys'         => ['nullable', 'string', 'max:65535'],
            'file_url'                     => ['nullable', 'string'],
            'prompt_text'                  => ['nullable', 'string'],
            'external_url'                 => ['nullable', 'url'],
            'account_data'                 => ['nullable', 'array'],
            'subscription_data'            => ['nullable', 'array'],
            'subscription_days'            => ['nullable', 'integer', 'min:0'],
            'billing_access_type'          => ['nullable', 'string', 'max:32', Rule::in(['subscription', 'one_time', 'lifetime'])],
            'duration_days'                => ['nullable', 'integer', 'min:1'],
            'course'                       => ['nullable', 'array'],
            'course.title'                 => ['nullable', 'string', 'max:255'],
            'course.description'           => ['nullable', 'string'],
            'course.lessons'               => ['nullable', 'array'],
            'course.lessons.*.id'          => ['nullable', 'integer'],
            'course.lessons.*.title'       => ['nullable', 'string', 'max:255'],
            'course.lessons.*.content_type' => ['nullable', 'string', 'max:32'],
            'course.lessons.*.content_url' => ['nullable', 'string', 'max:65535'],
            'course.lessons.*.content_body' => ['nullable', 'string'],
            'course.lessons.*.position'    => ['nullable', 'integer', 'min:0'],
            'course.lessons.*.drip_days'   => ['nullable', 'integer', 'min:0'],
            'key_data'                     => ['nullable', 'array'],
            'is_external'                  => ['boolean'],
            'is_rental'                    => ['boolean'],
            'address'                      => ['nullable', 'string'],
            'region_id'                    => ['nullable', 'integer', 'exists:regions,id'],
            'lat'                          => ['nullable', 'numeric'],
            'lng'                          => ['nullable', 'numeric'],
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}
