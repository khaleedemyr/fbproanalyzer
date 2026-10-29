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
     * @return array{synced:int, sources:array<string,int>, ideas:int}
     */
    public function sync(?User $ideaUser = null): array
    {
        $niche = config('radar.niche', 'psikologi');
        $region = config('radar.region', 'ID');
        $keywords = config('radar.keywords', []);

        $counts = [
            'google_trends' => 0,
            'youtube' => 0,
            'seed' => 0,
        ];

        $daily = $this->googleTrends->fetchDaily($region);
        $psychTrends = $this->googleTrends->filterPsychology($daily, $keywords);

        foreach ($psychTrends as $item) {
            $this->upsert('google_trends', $niche, $region, $item);
            $counts['google_trends']++;
        }

        $interest = $this->googleTrends->fetchKeywordInterest($keywords, $region);

        foreach ($keywords as $keyword) {
            $interestScore = (int) ($interest[$keyword] ?? 40);
            // metric_value pakai skala besar agar ranking jelas; viral_score dari interest 0-100.
            $metric = $interestScore * 1000;

            $this->upsert('seed', $niche, $region, [
                'keyword' => $keyword,
                'title' => $keyword,
                'snippet' => 'Minat relatif 7 hari (skor '.$interestScore.'/100). Topik pantauan otomatis niche psikologi.',
                'url' => 'https://trends.google.com/trends/explore?geo='.$region.'&q='.urlencode($keyword),
                'metric_value' => $metric,
                'format' => 'topic',
                'interest' => $interestScore,
                'raw' => [
                    'seed' => true,
                    'interest_7d' => $interestScore,
                ],
            ]);
            $counts['seed']++;
        }

        try {
            $videos = $this->youtubeTrends->fetch($keywords);
            foreach ($videos as $item) {
                $this->upsert('youtube', $niche, $region, $item);
                $counts['youtube']++;
            }
        } catch (\Throwable $e) {
            Log::warning('YouTube radar sync failed: '.$e->getMessage());
        }

        $ideas = 0;
        if ($ideaUser) {
            $ideas = $this->generateIdeas($ideaUser);
        }

        return [
            'synced' => array_sum($counts),
            'sources' => $counts,
            'ideas' => $ideas,
        ];
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

    private function generateIdeas(User $user): int
    {
        $signals = TrendSignal::query()
            ->where('niche', config('radar.niche', 'psikologi'))
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

            $user->contentIdeas()->create([
                'title' => $title,
                'angle' => 'Topik ini sedang naik di radar otomatis ('.$signal->source.'). Buat sudut personal Kea Abbasy: relatable + insight praktis.',
                'format' => 'reel',
                'hook' => 'Kalau kamu lagi ngerasa soal "'.$signal->keyword.'"...',
                'topic_cluster' => Str::slug($signal->keyword, '_'),
                'potential_score' => min(96, (float) $signal->viral_score + 3),
                'status' => 'queued',
                'notes' => 'Auto radar '.$signal->source.' #'.$signal->id,
            ]);
            $created++;
        }

        return $created;
    }
}
