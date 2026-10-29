<?php

return [
    // Trim kutip biar key dari panel hosting tidak rusak.
    'gemini_api_key' => trim((string) env('GEMINI_API_KEY', ''), " \t\n\r\0\x0B\"'"),
    // gemini-2.0-flash sudah shutdown Juni 2026.
    'gemini_model' => env('GEMINI_MODEL', 'gemini-3.8-flash'),
    'gemini_model_fallbacks' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'GEMINI_MODEL_FALLBACKS',
            'gemini-3.8-flash,gemini-3.5-flash,gemini-2.5-flash,gemini-flash-latest'
        ))
    ))),
];
