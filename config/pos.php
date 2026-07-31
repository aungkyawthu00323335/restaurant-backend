<?php

return [
    'api_token' => env('POS_API_TOKEN', ''),
    'max_image_bytes' => (int) env('POS_MAX_IMAGE_BYTES', 5 * 1024 * 1024),
];
