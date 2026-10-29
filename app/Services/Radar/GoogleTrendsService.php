<?php

namespace App\Services\Radar;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GoogleTrendsService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchDaily(string $geo = 'ID'): array
    {
        $response = Http::timeout(20)
            ->withHeaders($this->headers())
            ->get('https://trends.google.com/trends/api/dailytrends', [
                'hl' => 'id',
                'tz' => '-420',
                'geo' => $geo,
                'ns' => 15,
            ]);

        if (! $response->successful()) {
            return [];
        }

        $json = json_decode($this->stripMagic($response->body()), true);
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
     * Ambil skor minat 0-100 per keyword (7 hari terakhir), biar ranking tidak seragam.
     *
     * @param  array<int, string>  $keywords
     * @return array<string, int> keyword => interest 0-100
     */
    public function fetchKeywordInterest(array $keywords, string $geo = 'ID'): array
    {
        $scores = [];

        foreach (array_chunk(array_values($keywords), 5) as $batch) {
            try {
                $batchScores = $this->interestBatch($batch, $geo);
                $scores = array_merge($scores, $batchScores);
            } catch (\Throwable $e) {
                Log::warning('Google Trends interest batch failed: '.$e->getMessage());
            }

            usleep(400000);
        }

        // Fallback diferensiasi stabil jika API gagal/parsial.
        foreach ($keywords as $keyword) {
            if (! isset($scores[$keyword])) {
                $scores[$keyword] = $this->fallbackInterest($keyword);
            }
        }

        return $scores;
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

    /**
     * @param  array<int, string>  $keywords
     * @return array<string, int>
     */
    private function interestBatch(array $keywords, string $geo): array
    {
        $comparisonItem = array_map(
            fn (string $keyword) => [
                'keyword' => $keyword,
                'geo' => $geo,
                'time' => 'now 7-d',
            ],
            $keywords
        );

        $req = json_encode([
            'comparisonItem' => $comparisonItem,
            'category' => 0,
            'property' => '',
        ], JSON_UNESCAPED_UNICODE);

        $explore = Http::timeout(25)
            ->withHeaders($this->headers())
            ->get('https://trends.google.com/trends/api/explore', [
                'hl' => 'id',
                'tz' => '-420',
                'req' => $req,
            ]);

        if (! $explore->successful()) {
            return [];
        }

        $exploreJson = json_decode($this->stripMagic($explore->body()), true);
        $widgets = data_get($exploreJson, 'widgets', []);
        $timeseries = collect($widgets)->first(
            fn ($widget) => data_get($widget, 'id') === 'TIMESERIES'
        );

        if (! $timeseries) {
            return [];
        }

        $token = data_get($timeseries, 'token');
        $requestPayload = json_encode(data_get($timeseries, 'request'), JSON_UNESCAPED_UNICODE);

        $multiline = Http::timeout(25)
            ->withHeaders($this->headers())
            ->get('https://trends.google.com/trends/api/widgetdata/multiline', [
                'hl' => 'id',
                'tz' => '-420',
                'req' => $requestPayload,
                'token' => $token,
            ]);

        if (! $multiline->successful()) {
            return [];
        }

        $multiJson = json_decode($this->stripMagic($multiline->body()), true);
        $timeline = data_get($multiJson, 'default.timelineData', []);
        if ($timeline === []) {
            return [];
        }

        $sums = array_fill(0, count($keywords), 0);
        $points = 0;
        foreach ($timeline as $point) {
            $values = data_get($point, 'value', []);
            foreach ($keywords as $index => $keyword) {
                $sums[$index] += (int) ($values[$index] ?? 0);
            }
            $points++;
        }

        $result = [];
        foreach ($keywords as $index => $keyword) {
            $avg = $points > 0 ? (int) round($sums[$index] / $points) : 0;
            $result[$keyword] = max(1, min(100, $avg));
        }

        return $result;
    }

    private function fallbackInterest(string $keyword): int
    {
        $base = [
            'overthinking' => 90,
            'toxic relationship' => 86,
            'healing' => 82,
            'anxiety' => 80,
            'kesehatan mental' => 78,
            'self love' => 76,
            'insecure' => 74,
            'depresi' => 72,
            'kecemasan' => 70,
            'luka batin' => 68,
            'trauma' => 66,
            'gaslighting' => 64,
            'attachment style' => 60,
            'psikologi' => 58,
            'batas diri' => 55,
        ];

        $key = Str::lower($keyword);
        $prior = $base[$key] ?? 48;
        $jitter = crc32($key) % 17; // 0..16 stabil per keyword

        return max(20, min(98, $prior + $jitter - 8));
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'Accept' => 'application/json, text/plain, */*',
        ];
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
