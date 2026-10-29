<?php

return [
    'gemini_api_key' => trim((string) env('GEMINI_API_KEY', '')),
    'gemini_model' => env('GEMINI_MODEL', 'gemini-2.0-flash'),
];
