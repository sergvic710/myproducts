<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Which model reads the receipts
    |--------------------------------------------------------------------------
    | The provider must be able to read PDF files and images, and to return
    | structured output. In Prism that means anthropic, gemini or mistral.
    | Provider and model always change together:
    |
    |   anthropic + claude-opus-5        paid, best quality
    |   gemini    + gemini-2.5-flash     free tier, no card needed
    |   mistral   + mistral-small-latest free tier
    |
    | The API key is read by Prism from config/prism.php:
    | ANTHROPIC_API_KEY / GEMINI_API_KEY / MISTRAL_API_KEY.
    */
    'provider' => env('RECEIPT_PROVIDER', 'anthropic'),
    'model' => env('RECEIPT_MODEL', 'claude-opus-5'),

    /*
    |--------------------------------------------------------------------------
    | Where receipt files are stored
    |--------------------------------------------------------------------------
    | The `local` disk is rooted at storage/app/private, so the real folder is
    | storage/app/private/bot/docs.
    */
    'disk' => env('RECEIPT_DISK', 'local'),
    'path' => 'bot/docs',

    /*
    | Anthropic rejects requests over 32 MB. Stay well under that.
    */
    'max_file_size' => 25 * 1024 * 1024,

    /*
    | Category used when a product does not fit any existing category.
    */
    'fallback_category' => 'default',
];
