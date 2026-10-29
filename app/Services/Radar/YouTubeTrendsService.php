<?php

namespace App\Services\Radar;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class YouTubeTrendsService
{
    /**
     * @param  array<int, string>  $keywords
     * @return array<int, array<string, mixed>>
     */
    public function fetch(array $keywords, ?string $apiKey = null): array
    {
        $apiKey = $apiKey ?: config('radar.youtube_api_key');
        if (blank($apiKey)) {
            return [];
        }

        $since = now()->subDays(14)->toRfc3339String();
        $items = [];

        foreach (array_slice($keywords, 0, 8) as $keyword) {
            $response = Http::timeout(20)->get('https://www.googleapis.com/youtube/v3/search', [
                'part' => 'snippet',
                'q' => $keyword.' Indonesia',
                'type' => 'video',
                'order' => 'viewCount',
                'publishedAfter' => $since,
                'maxResults' => 5,
                'relevanceLanguage' => 'id',
                'regionCode' => 'ID',
                'key' => $apiKey,
            ]);

            if (! $response->successful()) {
                continue;
            }

            foreach ($response->json('items') ?? [] as $video) {
                $videoId = data_get($video, 'id.videoId');
                $title = (string) data_get($video, 'snippet.title', '');
                if (! $videoId || $title === '') {
                    continue;
                }

                $stats = $this->videoStats($videoId, $apiKey);
                $views = (int) ($stats['viewCount'] ?? 0);

                $items[] = [
                    'keyword' => $keyword,
                    'title' => $title,
                    'snippet' => (string) data_get($video, 'snippet.description', ''),
                    'url' => 'https://www.youtube.com/watch?v='.$videoId,
                    'metric_value' => $views,
                    'format' => 'video',
                    'raw' => [
                        'channel' => data_get($video, 'snippet.channelTitle'),
                        'published_at' => data_get($video, 'snippet.publishedAt'),
                        'stats' => $stats,
                    ],
                ];
            }
        }

        return collect($items)
            ->sortByDesc('metric_value')
            ->unique(fn (array $item) => $item['url'])
            ->take(40)
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function videoStats(string $videoId, string $apiKey): array
    {
        $response = Http::timeout(15)->get('https://www.googleapis.com/youtube/v3/videos', [
            'part' => 'statistics',
            'id' => $videoId,
            'key' => $apiKey,
        ]);

        if (! $response->successful()) {
            return [];
        }

        return $response->json('items.0.statistics') ?? [];
    }
}
