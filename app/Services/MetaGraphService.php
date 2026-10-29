<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class MetaGraphService
{
    public function authUrl(string $state): string
    {
        $params = [
            'client_id' => config('meta.app_id'),
            'redirect_uri' => config('meta.redirect_uri'),
            'state' => $state,
            'response_type' => 'code',
        ];

        $scopes = array_values(array_filter(config('meta.scopes', [])));
        if ($scopes !== []) {
            $params['scope'] = implode(',', $scopes);
        }

        return 'https://www.facebook.com/'.config('meta.graph_version').'/dialog/oauth?'.http_build_query($params);
    }

    public function exchangeCode(string $code): array
    {
        $response = Http::get($this->graphUrl('/oauth/access_token'), [
            'client_id' => config('meta.app_id'),
            'client_secret' => config('meta.app_secret'),
            'redirect_uri' => config('meta.redirect_uri'),
            'code' => $code,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Gagal menukar kode OAuth Meta: '.$response->body());
        }

        return $response->json();
    }

    public function exchangeLongLivedToken(string $shortLivedToken): array
    {
        $response = Http::get($this->graphUrl('/oauth/access_token'), [
            'grant_type' => 'fb_exchange_token',
            'client_id' => config('meta.app_id'),
            'client_secret' => config('meta.app_secret'),
            'fb_exchange_token' => $shortLivedToken,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Gagal mendapatkan long-lived token: '.$response->body());
        }

        return $response->json();
    }

    public function me(string $accessToken): array
    {
        $response = Http::get($this->graphUrl('/me'), [
            'fields' => 'id,name,email,picture.type(large)',
            'access_token' => $accessToken,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Gagal mengambil profil Meta: '.$response->body());
        }

        return $response->json();
    }

    public function pages(string $accessToken): array
    {
        $response = Http::get($this->graphUrl('/me/accounts'), [
            'fields' => 'id,name,username,category,access_token,picture{url}',
            'access_token' => $accessToken,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Gagal mengambil daftar Page: '.$response->body());
        }

        return $response->json('data') ?? [];
    }

    public function pagePosts(string $pageId, string $pageToken, int $limit = 25): array
    {
        $response = Http::get($this->graphUrl('/'.$pageId.'/posts'), [
            'fields' => 'id,message,created_time,permalink_url,full_picture,status_type,shares,likes.summary(true),comments.summary(true)',
            'limit' => $limit,
            'access_token' => $pageToken,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Gagal mengambil postingan Page: '.$response->body());
        }

        return $response->json('data') ?? [];
    }

    public function postInsights(string $postId, string $pageToken): array
    {
        $metricSets = [
            'post_impressions,post_impressions_unique,post_engaged_users,post_clicks,post_video_views,post_video_views_organic,post_video_avg_time_watched,post_video_length,post_video_view_time',
            'post_impressions,post_impressions_unique,post_engaged_users,post_clicks,post_video_views,post_video_avg_time_watched',
            'post_impressions,post_impressions_unique,post_engaged_users,post_clicks',
        ];

        $metrics = [];
        foreach ($metricSets as $metric) {
            $response = Http::timeout(30)->get($this->graphUrl('/'.$postId.'/insights'), [
                'metric' => $metric,
                'access_token' => $pageToken,
            ]);

            if (! $response->successful()) {
                continue;
            }

            foreach ($response->json('data') ?? [] as $item) {
                $metrics[$item['name']] = $item['values'][0]['value'] ?? 0;
            }

            if ($metrics !== []) {
                break;
            }
        }

        return $metrics;
    }

    private function graphUrl(string $path): string
    {
        return 'https://graph.facebook.com/'.config('meta.graph_version').$path;
    }
}
