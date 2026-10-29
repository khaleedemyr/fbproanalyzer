<?php

namespace App\Services\YouTube;

use App\Models\User;
use App\Models\YoutubeAccount;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class YouTubeOAuthService
{
    public function configured(): bool
    {
        return filled(config('youtube.client_id')) && filled(config('youtube.client_secret'));
    }

    public function authUrl(string $state): string
    {
        $params = [
            'client_id' => config('youtube.client_id'),
            'redirect_uri' => config('youtube.redirect_uri'),
            'response_type' => 'code',
            'scope' => implode(' ', config('youtube.scopes', [])),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ];

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query($params);
    }

    /**
     * @return array<string, mixed>
     */
    public function exchangeCode(string $code): array
    {
        $response = Http::asForm()->timeout(30)->post('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => config('youtube.client_id'),
            'client_secret' => config('youtube.client_secret'),
            'redirect_uri' => config('youtube.redirect_uri'),
            'grant_type' => 'authorization_code',
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Gagal tukar kode OAuth YouTube: '.data_get($response->json(), 'error_description', $response->body()));
        }

        return $response->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        $response = Http::asForm()->timeout(30)->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('youtube.client_id'),
            'client_secret' => config('youtube.client_secret'),
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Gagal refresh token YouTube: '.data_get($response->json(), 'error_description', $response->body()));
        }

        return $response->json();
    }

    public function ensureFreshToken(YoutubeAccount $account): YoutubeAccount
    {
        if (! $account->isTokenExpired() && filled($account->access_token)) {
            return $account;
        }

        if (blank($account->refresh_token)) {
            throw new RuntimeException('Refresh token YouTube kosong. Hubungkan ulang akun YouTube.');
        }

        $payload = $this->refreshAccessToken($account->refresh_token);
        $account->forceFill([
            'access_token' => $payload['access_token'] ?? $account->access_token,
            'token_expires_at' => isset($payload['expires_in'])
                ? now()->addSeconds((int) $payload['expires_in'] - 60)
                : $account->token_expires_at,
        ])->save();

        return $account->fresh();
    }

    /**
     * @param  array<string, mixed>  $tokenPayload
     */
    public function connectFromTokenPayload(User $user, array $tokenPayload): YoutubeAccount
    {
        $accessToken = (string) ($tokenPayload['access_token'] ?? '');
        if ($accessToken === '') {
            throw new RuntimeException('Access token YouTube kosong.');
        }

        $expiresIn = (int) ($tokenPayload['expires_in'] ?? 3600);
        $refresh = (string) ($tokenPayload['refresh_token'] ?? '');
        $scope = (string) ($tokenPayload['scope'] ?? implode(' ', config('youtube.scopes', [])));

        $profile = $this->googleUserInfo($accessToken);
        $channel = $this->mineChannel($accessToken);

        $channelId = $channel['id'] ?? null;
        $existing = YoutubeAccount::query()
            ->where('user_id', $user->id)
            ->when($channelId, fn ($q) => $q->where('channel_id', $channelId))
            ->orderByDesc('id')
            ->first();

        if (! $existing) {
            $existing = new YoutubeAccount(['user_id' => $user->id]);
        }

        $existing->fill([
            'google_user_id' => $profile['id'] ?? $existing->google_user_id,
            'channel_id' => $channel['id'] ?? $existing->channel_id,
            'channel_title' => $channel['title'] ?? $existing->channel_title,
            'email' => $profile['email'] ?? $existing->email,
            'picture_url' => $profile['picture'] ?? ($channel['thumbnail'] ?? $existing->picture_url),
            'access_token' => $accessToken,
            'refresh_token' => $refresh !== '' ? $refresh : $existing->refresh_token,
            'token_expires_at' => now()->addSeconds(max(60, $expiresIn - 60)),
            'scopes' => $scope,
            'raw' => [
                'profile' => $profile,
                'channel' => $channel,
            ],
        ]);
        $existing->save();

        return $existing->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function googleUserInfo(string $accessToken): array
    {
        $response = Http::timeout(20)
            ->withToken($accessToken)
            ->get('https://www.googleapis.com/oauth2/v3/userinfo');

        if (! $response->successful()) {
            return [];
        }

        $json = $response->json();

        return [
            'id' => $json['sub'] ?? null,
            'email' => $json['email'] ?? null,
            'name' => $json['name'] ?? null,
            'picture' => $json['picture'] ?? null,
        ];
    }

    /**
     * @return array{id:?string,title:?string,thumbnail:?string}
     */
    public function mineChannel(string $accessToken): array
    {
        $response = Http::timeout(25)
            ->withToken($accessToken)
            ->get('https://www.googleapis.com/youtube/v3/channels', [
                'part' => 'snippet,contentDetails,statistics',
                'mine' => 'true',
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Gagal ambil channel YouTube: '.data_get($response->json(), 'error.message', $response->body()));
        }

        $item = $response->json('items.0') ?? [];

        return [
            'id' => $item['id'] ?? null,
            'title' => data_get($item, 'snippet.title'),
            'thumbnail' => data_get($item, 'snippet.thumbnails.default.url'),
            'statistics' => $item['statistics'] ?? [],
        ];
    }
}
