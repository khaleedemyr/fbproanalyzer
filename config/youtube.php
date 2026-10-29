<?php

return [
    'api_key' => trim((string) env('YOUTUBE_API_KEY', ''), " \t\n\r\0\x0B\"'"),
    'http_referer' => rtrim((string) env('YOUTUBE_HTTP_REFERER', 'https://heng.justusku.co.id'), '/'),
    'client_id' => trim((string) env('YOUTUBE_CLIENT_ID', ''), " \t\n\r\0\x0B\"'"),
    'client_secret' => trim((string) env('YOUTUBE_CLIENT_SECRET', ''), " \t\n\r\0\x0B\"'"),
    'redirect_uri' => env('YOUTUBE_REDIRECT_URI', env('APP_URL').'/youtube/callback'),
    'scopes' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'YOUTUBE_SCOPES',
        'openid,email,profile,https://www.googleapis.com/auth/youtube.readonly,https://www.googleapis.com/auth/yt-analytics.readonly'
    ))))),
];
