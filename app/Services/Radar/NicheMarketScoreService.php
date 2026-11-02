<?php

namespace App\Services\Radar;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Ranking niche pasar berbasis data: Google Trends interest + YouTube views (bukan opini Gemini).
 */
class NicheMarketScoreService
{
    public function __construct(
        private GoogleTrendsService $googleTrends,
        private YouTubeTrendsService $youtubeTrends,
    ) {
    }

    /**
     * @return array{
     *   region: string,
     *   ranked_at: string,
     *   source: string,
     *   warnings: array<int, string>,
     *   ranks: array<string, array<string, mixed>>
     * }
     */
    public function rank(string $region = 'ID', bool $force = false): array
    {
        $region = strtoupper($region) === 'US' ? 'US' : 'ID';
        $cacheKey = 'niche_market_data_'.$region;

        if (! $force) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && ! empty($cached['ranks'])) {
                return $cached;
            }
        }

        $catalog = config('niches.'.$region, config('niches.ID', []));
        $warnings = [];

        // 1 keyword utama per niche (hemat kuota YouTube).
        $seedBySlug = [];
        foreach ($catalog as $row) {
            $slug = (string) ($row['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $keywords = array_values(array_filter(array_map('strval', $row['keywords'] ?? [])));
            $seed = $keywords[0] ?? ((string) ($row['label'] ?? $slug));
            $seedBySlug[$slug] = [
                'seed' => $seed,
                'label' => (string) ($row['label'] ?? $slug),
                'keywords' => $keywords,
            ];
        }

        $seeds = array_values(array_map(fn ($r) => $r['seed'], $seedBySlug));

        // --- Google Trends interest 0–100 ---
        $interest = [];
        try {
            $interest = $this->googleTrends->fetchKeywordInterest($seeds, $region);
        } catch (\Throwable $e) {
            $warnings[] = 'Google Trends: '.$e->getMessage();
            Log::warning('niche.market.trends_fail', ['error' => $e->getMessage()]);
        }

        // --- Daily trending hits ---
        $daily = [];
        try {
            $daily = $this->googleTrends->fetchDaily($region);
        } catch (\Throwable $e) {
            $warnings[] = 'Google Daily Trends: '.$e->getMessage();
        }

        // --- YouTube probe per niche (1 search) ---
        $ytBySlug = [];
        $ytError = null;
        foreach ($seedBySlug as $slug => $meta) {
            $probe = $this->youtubeTrends->probeKeyword($meta['seed'], $region, 14);
            if (! empty($probe['error']) && $ytError === null) {
                $ytError = (string) $probe['error'];
            }
            $ytBySlug[$slug] = $probe;
            usleep(150000);
        }
        if ($ytError) {
            $warnings[] = 'YouTube: '.$ytError;
        }

        // Normalisasi YouTube views ke 0–100 antar niche (log scale).
        $logViews = [];
        foreach ($ytBySlug as $slug => $probe) {
            $avg = max(0, (float) ($probe['avg_views'] ?? 0));
            $logViews[$slug] = $avg > 0 ? log10($avg + 1) : 0.0;
        }
        $maxLog = max($logViews ?: [0.0]);
        $ytScores = [];
        foreach ($logViews as $slug => $lv) {
            $ytScores[$slug] = $maxLog > 0 ? round(($lv / $maxLog) * 100, 1) : 0.0;
        }

        $ranks = [];
        foreach ($seedBySlug as $slug => $meta) {
            $seed = $meta['seed'];
            $gScore = (float) ($interest[$seed] ?? 0);
            $yScore = (float) ($ytScores[$slug] ?? 0);
            $probe = $ytBySlug[$slug] ?? [];
            $dailyHits = $this->countDailyHits($daily, $meta['keywords'], $seed, $meta['label']);
            $dailyBonus = min(100.0, $dailyHits * 25.0);

            // Bobot data: YouTube 45% · Google interest 40% · Daily trend hit 15%
            $score = round(($yScore * 0.45) + ($gScore * 0.40) + ($dailyBonus * 0.15), 1);

            $channels = (int) ($probe['unique_channels'] ?? 0);
            $avgViews = (int) ($probe['avg_views'] ?? 0);
            $competition = $this->competition($gScore, $avgViews, $channels);

            $whyParts = [];
            $whyParts[] = 'Google minat “'.$seed.'”: '.round($gScore).'/100';
            $whyParts[] = 'YouTube avg views (14h): '.number_format($avgViews);
            if ($dailyHits > 0) {
                $whyParts[] = $dailyHits.' hit di Google Daily Trends';
            }

            $fit = 'Fokus keyword “'.$seed.'”; bukti data skor '.round($score)
                .' (YT '.round($yScore).' · Trends '.round($gScore).' · Daily '.round($dailyBonus).').';

            $ranks[$slug] = [
                'score' => $score,
                'competition' => $competition,
                'why' => implode(' · ', $whyParts),
                'fit_for_new_account' => $fit,
                'metrics' => [
                    'seed_keyword' => $seed,
                    'google_interest' => round($gScore, 1),
                    'youtube_score' => $yScore,
                    'youtube_avg_views' => $avgViews,
                    'youtube_total_views' => (int) ($probe['total_views'] ?? 0),
                    'youtube_videos' => (int) ($probe['videos'] ?? 0),
                    'youtube_channels' => $channels,
                    'daily_trend_hits' => $dailyHits,
                    'daily_bonus' => $dailyBonus,
                ],
            ];
        }

        $payload = [
            'region' => $region,
            'ranked_at' => now()->toDateTimeString(),
            'source' => 'google_trends+youtube',
            'warnings' => $warnings,
            'ranks' => $ranks,
        ];

        Cache::put($cacheKey, $payload, now()->addHours(12));
        Cache::put($cacheKey.'_at', $payload['ranked_at'], now()->addHours(12));

        return $payload;
    }

    /**
     * @param  array<int, array<string, mixed>>  $daily
     * @param  array<int, string>  $keywords
     */
    protected function countDailyHits(array $daily, array $keywords, string $seed, string $label): int
    {
        $needles = array_values(array_filter(array_unique(array_map(
            fn ($k) => Str::lower(trim((string) $k)),
            array_merge($keywords, [$seed, $label])
        ))));

        $hits = 0;
        foreach ($daily as $item) {
            $hay = Str::lower(trim(
                (string) ($item['title'] ?? '').' '.(string) ($item['keyword'] ?? '').' '.(string) ($item['snippet'] ?? '')
            ));
            if ($hay === '') {
                continue;
            }
            foreach ($needles as $n) {
                if ($n !== '' && mb_strlen($n) >= 3 && str_contains($hay, $n)) {
                    $hits++;
                    break;
                }
            }
        }

        return $hits;
    }

    protected function competition(float $interest, int $avgViews, int $channels): string
    {
        // Banyak channel + views tinggi + interest tinggi = pasar padat.
        $pressure = ($interest * 0.4)
            + (min(100, log10(max(1, $avgViews)) * 20) * 0.4)
            + (min(100, $channels * 20) * 0.2);

        if ($pressure >= 70) {
            return 'high';
        }
        if ($pressure >= 40) {
            return 'mid';
        }

        return 'low';
    }
}
