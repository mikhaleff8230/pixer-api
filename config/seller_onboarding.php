<?php

return [
    // off, post_moderation, new_sellers, all. Visibility remains products.status.
    'publication_policy' => env('SELLER_PUBLICATION_POLICY', 'post_moderation'),
    // Existing generic "Товар" group; categories in SANCAN do not all have type_id.
    'quick_product_type_slug' => env('SELLER_ONBOARDING_PRODUCT_TYPE', 'element'),
];
