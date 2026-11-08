<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Haziq's own student chatbot -- a separate key from config/assistant.php
    |--------------------------------------------------------------------------
    |
    | Deliberately its own key, not Chloe's GEMINI_API_KEY -- see
    | app/Modules/Haziq/Assistant/GeminiClient.php's own docblock for why a
    | second caller sharing her key could push both over the free-tier
    | quota unpredictably.
    |
    | Read here, inside a config file, rather than with env() directly in
    | GeminiClient -- the same reason every other env() call in this app
    | lives in a config file: it survives `php artisan config:cache`, and a
    | test can override it with config(['haziq_assistant.api_key' => ...])
    | reliably, which plain env() does not.
    */
    'api_key' => env('HAZIQ_GEMINI_API_KEY'),

    'model' => env('HAZIQ_ASSISTANT_MODEL', 'gemini-3.5-flash-lite'),

];
