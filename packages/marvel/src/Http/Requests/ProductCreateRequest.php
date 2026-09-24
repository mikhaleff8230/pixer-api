<?php

namespace Marvel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Marvel\Enums\ProductStatus;
use Marvel\Enums\ProductType;

class ProductCreateRequest extends FormRequest
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
        foreach ($requestData as $key => $value) {
            // product_type должен быть строкой, не парсим его
            if ($key === 'product_type') {
                // Убеждаемся что product_type это строка
                if (is_string($value)) {
                    continue;
                }
                // Если это объект, извлекаем value
                if (is_array($value) && isset($value['value'])) {
                    $this->merge([$key => $value['value']]);
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
            'name'                         => ['required', 'string', 'max:255'],
            'slug'                         => ['nullable', 'string'],
            'price'                        => ['nullable', 'numeric'],
            'sale_price'                   => ['nullable', 'lte:price'],
            'type_id'                      => ['required', 'exists:Marvel\Database\Models\Type,id'],
            'shop_id'                      => ['required', 'exists:Marvel\Database\Models\Shop,id'],
            'manufacturer_id'              => ['nullable', 'exists:Marvel\Database\Models\Manufacturer,id'],
            'author_id'                    => ['nullable', 'exists:Marvel\Database\Models\Author,id'],
            'product_type'                 => ['required', Rule::in($productType)],
            'categories'                   => ['array'],
            'tags'                         => ['array'],
            'language'                     => ['nullable', 'string'],
            'dropoff_locations'            => ['array'],
            'pickup_locations'             => ['array'],
            'digital_file'                 => ['array'],
            'variations'                   => ['nullable', 'array'],
            'variation_options'            => ['nullable', 'array'],
            'variation_options.upsert'     => ['nullable', 'array'],
            'variation_options.upsert.*.price' => ['required_with:variation_options.upsert', 'numeric', 'min:0'],
            'variation_options.upsert.*.quantity' => ['required_with:variation_options.upsert', 'integer', 'min:0'],
            'variation_options.upsert.*.sku' => ['required_with:variation_options.upsert', 'string'],
            'variation_options.upsert.*.sale_price' => ['nullable', 'numeric', 'min:0'],
            'variation_options.upsert.*.options' => ['required_with:variation_options.upsert', 'array'],
            'variation_options.delete'     => ['nullable', 'array'],
            'quantity'                     => ['nullable', 'integer'],
            'unit'                         => ['nullable', 'string'],
            'description'                  => ['nullable', 'string'],
            'sku'                          => ['nullable', 'string'],
            'image'                        => ['array'],
            'gallery'                      => ['array'],
            'video'                        => ['nullable', 'sometimes', 'file', 'mimetypes:video/mp4,application/mp4,video/webm,video/quicktime,video/mpeg,video/x-msvideo,video/avi,video/x-ms-wmv,video/ogg,application/ogg', 'mimes:mp4,mpeg,mpg,mov,avi,wmv,webm,ogv', 'max:51200'], // 50MB максимум
            'video_as_cover'               => ['nullable', 'boolean'],
            'remove_video'                 => ['nullable', 'boolean'],
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
