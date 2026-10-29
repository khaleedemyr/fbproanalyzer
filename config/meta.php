<?php

/**
 * Scopes default sengaja minimal agar OAuth lolos dulu.
 * Setelah di Meta App kamu menambah use case Pages + permission,
 * isi META_SCOPES di .env (comma-separated) misalnya:
 * public_profile,email,pages_show_list,pages_read_engagement,pages_read_user_content,read_insights
 */
$defaultScopes = [
    'public_profile',
    'email',
];

$envScopes = array_values(array_filter(array_map(
    static fn (string $scope) => trim($scope),
    explode(',', (string) env('META_SCOPES', ''))
)));

return [
    'app_id' => env('META_APP_ID'),
    'app_secret' => env('META_APP_SECRET'),
    'graph_version' => env('META_GRAPH_VERSION', 'v22.0'),
    'redirect_uri' => env('META_REDIRECT_URI', env('APP_URL').'/meta/callback'),
    'scopes' => $envScopes !== [] ? $envScopes : $defaultScopes,
];
