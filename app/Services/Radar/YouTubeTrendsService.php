<?php

namespace App\Services\Radar;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class YouTubeTrendsService
{
    /**
     * @param  array<int, string>  $keywords
     * @return array{items: array<int, array<string, mixed>>, error: ?string}
     */
    public function fetchWithStatus(array $keywords, ?string $apiKey = null): array
    {
        $apiKey = trim((string) ($apiKey ?: config('radar.youtube_api_key')));
        if ($apiKey === '') {
            return [
                'items' => [],
                'error' => 'YOUTUBE_API_KEY kosong / belum terbaca. Cek .env lalu jalankan php artisan config:clear',
            ];
        }

        $since = now()->subDays(21)->utc()->format('Y-m-d\TH:i:s\Z');
        $items = [];
        $lastError = null;

        foreach (array_slice($keywords, 0, 5) as $keyword) {
            try {
                $videos = $this->searchVideos($keyword, $apiKey, $since);
                foreach ($videos as $video) {
                    $items[] = $video;
                }
            } catch (RuntimeException $e) {
                $lastError = $e->getMessage();
                break;
            }
        }

        $unique = collect($items)
            ->sortByDesc('metric_value')
            ->unique(fn (array $item) => $item['url'])
            ->take(30)
            ->values()
            ->all();

        if ($unique === [] && $lastError === null) {
            $lastError = 'YouTube API merespons tapi tidak ada video cocok untuk keyword niche. Coba lagi nanti.';
        }

        return [
            'items' => $unique,
            'error' => $unique === [] ? $lastError : null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchVideos(string $keyword, string $apiKey, string $since): array
    {
        $response = Http::timeout(25)
            ->withHeaders($this->browserHeaders())
            ->get('https://www.googleapis.com/youtube/v3/search', [
                'part' => 'snippet',
                'q' => $keyword.' psikologi',
                'type' => 'video',
                'order' => 'viewCount',
                'publishedAfter' => $since,
                'maxResults' => 4,
                'relevanceLanguage' => 'id',
                'regionCode' => 'ID',
                'key' => $apiKey,
            ]);

        if (! $response->successful()) {
            $message = data_get($response->json(), 'error.message')
                ?: 'HTTP '.$response->status().' dari YouTube API';
            throw new RuntimeException($message);
        }

        $rows = $response->json('items') ?? [];
        if ($rows === []) {
            // Fallback tanpa publishedAfter ketat
            $response = Http::timeout(25)
                ->withHeaders($this->browserHeaders())
                ->get('https://www.googleapis.com/youtube/v3/search', [
                    'part' => 'snippet',
                    'q' => $keyword.' Indonesia',
                    'type' => 'video',
                    'order' => 'relevance',
                    'maxResults' => 4,
                    'relevanceLanguage' => 'id',
                    'regionCode' => 'ID',
                    'key' => $apiKey,
                ]);

            if (! $response->successful()) {
                $message = data_get($response->json(), 'error.message')
                    ?: 'HTTP '.$response->status().' dari YouTube API';
                throw new RuntimeException($message);
            }

            $rows = $response->json('items') ?? [];
        }

        $videoIds = collect($rows)
            ->map(fn ($row) => data_get($row, 'id.videoId'))
            ->filter()
            ->values()
            ->all();

        $statsMap = $this->videoStatsMap($videoIds, $apiKey);
        $items = [];

        foreach ($rows as $video) {
            $videoId = data_get($video, 'id.videoId');
            $title = (string) data_get($video, 'snippet.title', '');
            if (! $videoId || $title === '') {
                continue;
            }

            $stats = $statsMap[$videoId] ?? [];
            $views = (int) ($stats['viewCount'] ?? 0);

            $items[] = [
                'keyword' => $keyword,
                'title' => $title,
                'snippet' => 'YouTube · '.(string) data_get($video, 'snippet.channelTitle', ''),
                'url' => 'https://www.youtube.com/watch?v='.$videoId,
                'metric_value' => $views,
                'format' => 'video',
                'raw' => [
                    'channel' => data_get($video, 'snippet.channelTitle'),
                    'published_at' => data_get($video, 'snippet.publishedAt'),
                    'stats' => $stats,
                    'platform' => 'youtube',
                ],
            ];
        }

        return $items;
    }

    /**
     * @param  array<int, string>  $videoIds
     * @return array<string, array<string, mixed>>
     */
    private function videoStatsMap(array $videoIds, string $apiKey): array
    {
        if ($videoIds === []) {
            return [];
        }

        $response = Http::timeout(20)
            ->withHeaders($this->browserHeaders())
            ->get('https://www.googleapis.com/youtube/v3/videos', [
                'part' => 'statistics',
                'id' => implode(',', $videoIds),
                'key' => $apiKey,
            ]);

        if (! $response->successful()) {
            return [];
        }

        $map = [];
        foreach ($response->json('items') ?? [] as $item) {
            $map[$item['id']] = $item['statistics'] ?? [];
        }

        return $map;
    }

    /**
     * @return array<string, string>
     */
    private function browserHeaders(): array
    {
        $referer = rtrim((string) config('radar.youtube_http_referer'), '/');
        if ($referer === '') {
            $referer = 'https://heng.justusku.co.id';
        }

        return [
            'Referer' => $referer.'/',
            'Origin' => $referer,
            'User-Agent' => 'Mozilla/5.0 (compatible; FBProRadar/1.0)',
        ];
    }
}

