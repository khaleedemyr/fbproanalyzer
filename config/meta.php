<?php

/**
 * Default hanya public_profile.
 * App use case Page: set META_SCOPES tanpa "email" (sering Invalid Scopes).
 * Contoh: public_profile,pages_show_list,pages_read_engagement,pages_read_user_content,read_insights
 */
$defaultScopes = [
    'public_profile',
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
