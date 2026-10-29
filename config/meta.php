<?php

return [
    'app_id' => env('META_APP_ID'),
    'app_secret' => env('META_APP_SECRET'),
    'graph_version' => env('META_GRAPH_VERSION', 'v22.0'),
    'redirect_uri' => env('META_REDIRECT_URI', env('APP_URL').'/meta/callback'),
    'scopes' => [
        'email',
        'public_profile',
        'pages_show_list',
        'pages_read_engagement',
        'pages_read_user_content',
        'read_insights',
    ],
];
