<?php

return [
    'niche' => env('RADAR_NICHE', 'psikologi'),
    'region' => env('RADAR_REGION', 'ID'),
    'youtube_api_key' => trim((string) env('YOUTUBE_API_KEY', '')),
    'keywords' => [
        'psikologi',
        'kesehatan mental',
        'overthinking',
        'anxiety',
        'healing',
        'trauma',
        'toxic relationship',
        'insecure',
        'self love',
        'attachment style',
        'gaslighting',
        'depresi',
        'kecemasan',
        'luka batin',
        'batas diri',
    ],
];
