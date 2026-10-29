<?php

/**
 * Default hanya public_profile — permission bawaan Facebook Login.
 * email / pages_* harus diaktifkan dulu di Meta App (Sesuaikan use case),
 * baru diisi lewat META_SCOPES.
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
