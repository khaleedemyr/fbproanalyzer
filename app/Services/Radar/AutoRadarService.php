<?php

namespace App\Services\Radar;

use App\Models\ContentIdea;
use App\Models\TrendSignal;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AutoRadarService
{
    public function __construct(
        private GoogleTrendsService $googleTrends,
        private YouTubeTrendsService $youtubeTrends,
    ) {
    }

    /**
     * @param  array{region?:string,niche?:string,keywords?:array<int,string>}|null  $options
     * @return array{synced:int, sources:array<string,int>, ideas:int, region:string, niche:string}
     */
    public function sync(?User $ideaUser = null, ?array $options = null): array
    {
        $region = strtoupper(trim((string) ($options['region'] ?? config('radar.region', 'ID'))));
        if (! in_array($region, ['ID', 'US'], true)) {
            $region = 'ID';
        }
        $niche = trim((string) ($options['niche'] ?? config('radar.niche', 'psikologi'))) ?: 'psikologi';
        $keywords = $options['keywords'] ?? null;
        if (! is_array($keywords) || $keywords === []) {
            $keywords = $this->keywordsForNiche($niche, $region);
        }
        $keywords = array_values(array_filter(array_map('strval', $keywords)));

        $counts = [
            'google_trends' => 0,
            'youtube' => 0,
            'keyword_radar' => 0,
        ];
        $warnings = [];

        // Hapus label lama "seed" agar tidak dobel dengan keyword_radar.
        TrendSignal::query()
            ->where('niche', $niche)
            ->where('region', $region)
            ->where('source', 'seed')
            ->delete();

        $daily = $this->googleTrends->fetchDaily($region);
        $psychTrends = $this->googleTrends->filterPsychology($daily, $keywords);

        foreach ($psychTrends as $item) {
            $this->upsert('google_trends', $niche, $region, $item);
            $counts['google_trends']++;
        }

        $interest = $this->googleTrends->fetchKeywordInterest($keywords, $region);

        foreach ($keywords as $keyword) {
            $interestScore = (int) ($interest[$keyword] ?? 40);
            $metric = $interestScore * 1000;

            $this->upsert('keyword_radar', $niche, $region, [
                'keyword' => $keyword,
                'title' => $keyword,
                'snippet' => 'Google minat relatif 7 hari: '.$interestScore.'/100 · region '.$region.' · niche '.$niche.'.',
                'url' => 'https://trends.google.com/trends/explore?geo='.$region.'&q='.urlencode($keyword),
                'metric_value' => $metric,
                'format' => 'topic',
                'interest' => $interestScore,
                'raw' => [
                    'platform' => 'google',
                    'interest_7d' => $interestScore,
                    'region' => $region,
                ],
            ]);
            $counts['keyword_radar']++;
        }

        $youtube = $this->youtubeTrends->fetchWithStatus($keywords, null, $region, $niche);
        foreach ($youtube['items'] as $item) {
            $this->upsert('youtube', $niche, $region, $item);
            $counts['youtube']++;
        }
        if ($youtube['error']) {
            $warnings[] = 'YouTube: '.$youtube['error'];
            Log::warning('YouTube radar: '.$youtube['error']);
        }

        $ideas = 0;
        if ($ideaUser) {
            $ideas = $this->generateIdeas($ideaUser, $niche, $region);
        }

        return [
            'synced' => array_sum($counts),
            'sources' => $counts,
            'ideas' => $ideas,
            'warnings' => $warnings,
            'region' => $region,
            'niche' => $niche,
            'youtube_configured' => filled(trim((string) config('radar.youtube_api_key'))),
        ];
    }

    /**
     * @return list<string>
     */
    public function keywordsForNiche(string $niche, string $region = 'ID'): array
    {
        $region = in_array(strtoupper($region), ['ID', 'US'], true) ? strtoupper($region) : 'ID';
        $catalog = config('niches.'.$region, []);
        $needle = Str::lower($niche);
        foreach ($catalog as $row) {
            $slug = Str::lower((string) ($row['slug'] ?? ''));
            $label = Str::lower((string) ($row['label'] ?? ''));
            if ($slug === $needle || str_contains($label, $needle) || str_contains($needle, $slug)) {
                $kw = array_values(array_filter(array_map('strval', $row['keywords'] ?? [])));
                if ($kw !== []) {
                    return $kw;
                }
            }
        }

        return array_values(array_filter(array_map('strval', config('radar.keywords', []))));
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function upsert(string $source, string $niche, string $region, array $item): void
    {
        $keyword = (string) ($item['keyword'] ?? '');
        $title = (string) ($item['title'] ?? $keyword);
        $url = (string) ($item['url'] ?? '');
        $metric = (int) ($item['metric_value'] ?? 0);
        $fingerprint = sha1($source.'|'.Str::lower($keyword).'|'.Str::lower($title).'|'.$url);

        TrendSignal::query()->updateOrCreate(
            ['fingerprint' => $fingerprint],
            [
                'source' => $source,
                'niche' => $niche,
                'keyword' => $keyword,
                'title' => $title,
                'snippet' => $item['snippet'] ?? null,
                'url' => $url ?: null,
                'format' => $item['format'] ?? 'topic',
                'metric_value' => $metric,
                'viral_score' => $this->score($source, $metric, isset($item['interest']) ? (int) $item['interest'] : null),
                'region' => $region,
                'raw' => $item['raw'] ?? null,
                'observed_at' => now(),
            ]
        );
    }

    private function score(string $source, int $metric, ?int $interest = null): float
    {
        if ($interest !== null) {
            // Interest Google Trends 0-100 → skor viral 35-95
            return round(35 + ($interest * 0.6), 2);
        }

        $base = match ($source) {
            'youtube' => min(95, log(max(1, $metric)) * 6),
            'google_trends' => min(90, log(max(1, $metric)) * 5 + 20),
            default => min(70, 40 + log(max(1, $metric)) * 2),
        };

        return round($base, 2);
    }

    private function generateIdeas(User $user, ?string $niche = null, ?string $region = null): int
    {
        $niche = $niche ?: config('radar.niche', 'psikologi');
        $region = $region ?: config('radar.region', 'ID');
        $signals = TrendSignal::query()
            ->where('niche', $niche)
            ->where('region', $region)
            ->where('observed_at', '>=', now()->subDays(3))
            ->orderByDesc('viral_score')
            ->limit(8)
            ->get();

        $created = 0;
        foreach ($signals as $signal) {
            $title = 'Konten FB: '.Str::limit($signal->title ?: $signal->keyword, 70);
            $exists = $user->contentIdeas()
                ->where('title', $title)
                ->where('status', 'queued')
                ->exists();

            if ($exists) {
                continue;
            }

            $keyword = (string) ($signal->keyword ?: $signal->title ?: 'psikologi');

            $user->contentIdeas()->create([
                'title' => $title,
                'angle' => 'Kupas sisi gelap "'.$keyword.'" yang orang jarang akui: pola kontrol, validasi, dan jebakan emosional sehari-hari.',
                'format' => 'reel',
                'hook' => 'Kalau kamu lagi ngerasa soal "'.$keyword.'"...',
                'topic_cluster' => Str::slug($keyword, '_'),
                'potential_score' => min(96, (float) $signal->viral_score + 3),
                'status' => 'queued',
                'notes' => 'Auto radar '.$signal->source.' #'.$signal->id.' — tulis ulang sudut personal Kea Abbasy (jangan dibaca mentah di VO).',
            ]);
            $created++;
        }

        return $created;
    }
}
