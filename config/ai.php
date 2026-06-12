<?php

return [
    'openai' => [
        'api_key' => env('OPENAI_SECRET_KEY'),
        'text_model' => env('OPENAI_TEXT_MODEL', 'gpt-4o-mini'),
        'vision_model' => env('OPENAI_VISION_MODEL', 'gpt-4o-mini'),
        'image_model' => env('OPENAI_IMAGE_MODEL', 'gpt-image-1'),
        'image_fallback_model' => env('OPENAI_IMAGE_FALLBACK_MODEL', 'dall-e-2'),
    ],
];
