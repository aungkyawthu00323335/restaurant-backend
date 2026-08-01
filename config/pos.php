<?php

return [
    'api_token' => env('POS_API_TOKEN', ''),
    'max_image_bytes' => (int) env('POS_MAX_IMAGE_BYTES', 5 * 1024 * 1024),
    'max_page_size' => (int) env('POS_MAX_PAGE_SIZE', 100),
    'max_export_rows' => (int) env('POS_MAX_EXPORT_ROWS', 5000),
    'catalog_cache_seconds' => (int) env('POS_CATALOG_CACHE_SECONDS', 10),
    'dashboard_cache_seconds' => (int) env('POS_DASHBOARD_CACHE_SECONDS', 10),
];
