<?php

namespace App\Services\Radar;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GoogleTrendsService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchDaily(string $geo = 'ID'): array
    {
        $response = Http::timeout(20)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; FBProRadar/1.0)',
            ])
            ->get('https://trends.google.com/trends/api/dailytrends', [
                'hl' => 'id',
                'tz' => '-420',
                'geo' => $geo,
                'ns' => 15,
            ]);

        if (! $response->successful()) {
            return [];
        }

        $body = $response->body();
        $json = json_decode($this->stripMagic($body), true);
        $days = data_get($json, 'default.trendingSearchesDays', []);

        $items = [];
        foreach ($days as $day) {
            foreach (data_get($day, 'trendingSearches', []) as $trend) {
                $title = (string) data_get($trend, 'title.query', '');
                if ($title === '') {
                    continue;
                }

                $traffic = (string) data_get($trend, 'formattedTraffic', '0');
                $metric = $this->parseTraffic($traffic);

                $items[] = [
                    'keyword' => $title,
                    'title' => $title,
                    'snippet' => collect(data_get($trend, 'articles', []))
                        ->pluck('title')
                        ->filter()
                        ->take(2)
                        ->implode(' · '),
                    'url' => data_get($trend, 'articles.0.url'),
                    'metric_value' => $metric,
                    'format' => 'topic',
                    'raw' => [
                        'traffic' => $traffic,
                        'related' => collect(data_get($trend, 'relatedQueries', []))->pluck('query')->take(5)->values(),
                    ],
                ];
            }
        }

        return $items;
    }

    /**
     * @param  array<int, string>  $keywords
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    public function filterPsychology(array $items, array $keywords): array
    {
        $needles = collect($keywords)
            ->map(fn (string $k) => Str::lower($k))
            ->all();

        $psychHints = [
            'psikolog', 'mental', 'overthinking', 'anxiety', 'trauma', 'healing',
            'toxic', 'insecure', 'depresi', 'cemas', 'galau', 'patah hati',
            'gaslight', 'attachment', 'self love', 'luka batin', 'kecemasan',
            'konseling', 'therapy', 'terapi', 'emosi', 'marah', 'sedih',
        ];

        return collect($items)
            ->filter(function (array $item) use ($needles, $psychHints) {
                $hay = Str::lower(($item['title'] ?? '').' '.($item['snippet'] ?? '').' '.($item['keyword'] ?? ''));

                foreach ($needles as $needle) {
                    if ($needle !== '' && str_contains($hay, $needle)) {
                        return true;
                    }
                }

                foreach ($psychHints as $hint) {
                    if (str_contains($hay, $hint)) {
                        return true;
                    }
                }

                return false;
            })
            ->values()
            ->all();
    }

    private function stripMagic(string $body): string
    {
        return (string) preg_replace('/^\)\]\}\',?\s*/', '', $body);
    }

    private function parseTraffic(string $traffic): int
    {
        $traffic = strtoupper(trim($traffic));
        $traffic = str_replace(['+', ' ', ','], '', $traffic);

        if (str_ends_with($traffic, 'JT') || str_ends_with($traffic, 'M')) {
            return (int) ((float) $traffic * 1_000_000);
        }

        if (str_ends_with($traffic, 'RB') || str_ends_with($traffic, 'K')) {
            return (int) ((float) $traffic * 1_000);
        }

        return (int) preg_replace('/\D/', '', $traffic) ?: 0;
    }
}
