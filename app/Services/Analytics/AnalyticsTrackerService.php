<?php

namespace App\Services\Analytics;

use App\Models\AnalyticsSnapshot;
use App\Models\BrandKit;
use App\Models\ContentPost;
use App\Models\MetaAccount;
use App\Models\TrackedYoutubeVideo;
use App\Models\User;
use App\Models\YoutubeAccount;
use App\Services\MetaGraphService;
use App\Services\ViralScoreService;
use App\Services\YouTube\YouTubeAnalyticsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AnalyticsTrackerService
{
    public function __construct(
        private MetaGraphService $meta,
        private ViralScoreService $scorer,
        private YouTubeAnalyticsService $youtubeAnalytics,
    ) {
    }

    /**
     * Analytics Tracker syncs YouTube only (channel OAuth + tracked videos).
     *
     * @return array{meta_synced:int,youtube_synced:int,snapshots:int,warnings:array<int,string>,accounts:int}
     */
    public function syncAll(User $user): array
    {
        $warnings = [];

        $ytOauth = $this->youtubeAnalytics->syncUser($user);
        $warnings = array_merge($warnings, $ytOauth['warnings']);

        $yt = $this->syncYoutubeTracked($user);
        $warnings = array_merge($warnings, $yt['warnings']);

        $snapshots = 0;
        try {
            $snapshots = $this->snapshotToday($user);
        } catch (\Throwable $e) {
            $warnings[] = 'Snapshot: '.$e->getMessage();
            Log::warning('Analytics snapshot: '.$e->getMessage());
        }

        return [
            'meta_synced' => 0,
            'youtube_synced' => $ytOauth['synced'] + $yt['synced'],
            'youtube_analytics_synced' => $ytOauth['synced'],
            'snapshots' => $snapshots,
            'warnings' => $warnings,
            'accounts' => 0,
        ];
    }

    public function syncMetaAccount(User $user, MetaAccount $metaAccount): int
    {
        if ($metaAccount->type !== 'page' || blank($metaAccount->access_token)) {
            throw new \RuntimeException('Sync hanya untuk Page dengan access token.');
        }

        $posts = $this->meta->pagePosts($metaAccount->meta_id, $metaAccount->access_token, 12);
        $synced = 0;

        foreach ($posts as $post) {
            try {
                $synced += $this->upsertMetaPost($user, $metaAccount, $post) ? 1 : 0;
            } catch (\Throwable $e) {
                Log::warning('Analytics meta post skip '.$post['id'].': '.$e->getMessage());
            }
        }

        $metaAccount->update(['last_synced_at' => now()]);

        return $synced;
    }

    /**
     * @param  array<string, mixed>  $post
     */
    protected function upsertMetaPost(User $user, MetaAccount $metaAccount, array $post): bool
    {
        $likes = (int) data_get($post, 'likes.summary.total_count', 0);
        $comments = (int) data_get($post, 'comments.summary.total_count', 0);
        $shares = (int) data_get($post, 'shares.count', 0);

        try {
            $insights = $this->meta->postInsights($post['id'], $metaAccount->access_token);
        } catch (\Throwable) {
            $insights = [];
        }

        $reach = (int) ($insights['post_impressions_unique'] ?? 0);
        $impressions = (int) ($insights['post_impressions'] ?? 0);
        $views = (int) ($insights['post_video_views'] ?? $insights['post_video_views_organic'] ?? 0);
        if ($views <= 0) {
            $views = $impressions > 0 ? $impressions : max($reach, $likes + $comments + $shares);
        }
        if ($reach <= 0) {
            $reach = max(1, $likes + $comments + $shares);
        }

        [$avgWatch, $retention] = $this->deriveWatchMetrics($insights);

        $format = $this->scorer->detectFormat($post['status_type'] ?? null, $post['message'] ?? null);
        $topic = $this->scorer->detectTopicCluster($post['message'] ?? null);
        $formula = $this->scorer->formulaKey($format, $topic);
        $metrics = compact('reach', 'likes', 'comments', 'shares') + ['saves' => 0, 'views' => $views];

        ContentPost::query()->updateOrCreate(
            ['meta_post_id' => $post['id']],
            [
                'user_id' => $user->id,
                'meta_account_id' => $metaAccount->id,
                'permalink' => $post['permalink_url'] ?? null,
                'format' => $format,
                'message' => $post['message'] ?? null,
                'media_url' => $post['full_picture'] ?? null,
                'published_at' => isset($post['created_time']) ? Carbon::parse($post['created_time']) : null,
                'reach' => $reach,
                'impressions' => $impressions,
                'views' => $views,
                'likes' => $likes,
                'comments' => $comments,
                'shares' => $shares,
                'avg_watch_seconds' => $avgWatch,
                'retention_rate' => $retention,
                'engagement_rate' => $this->scorer->engagementRate($metrics),
                'viral_score' => $this->scorer->score($metrics),
                'topic_cluster' => $topic,
                'formula_key' => $formula,
                'raw_insights' => $insights,
            ]
        );

        return true;
    }

    /**
     * @return array{synced:int,warnings:array<int,string>}
     */
    public function syncYoutubeTracked(User $user): array
    {
        $apiKey = trim((string) config('radar.youtube_api_key', ''), " \t\n\r\0\x0B\"'");
        $videos = TrackedYoutubeVideo::query()->where('user_id', $user->id)->get();
        if ($videos->isEmpty()) {
            return ['synced' => 0, 'warnings' => []];
        }
        if ($apiKey === '') {
            return ['synced' => 0, 'warnings' => ['YOUTUBE_API_KEY kosong — skip sync YouTube tracked.']];
        }

        $synced = 0;
        $warnings = [];
        $ids = $videos->pluck('video_id')->filter()->values()->all();
        foreach (array_chunk($ids, 40) as $chunk) {
            try {
                $response = Http::timeout(25)
                    ->withHeaders($this->ytHeaders())
                    ->get('https://www.googleapis.com/youtube/v3/videos', [
                        'part' => 'snippet,statistics',
                        'id' => implode(',', $chunk),
                        'key' => $apiKey,
                    ]);
                if (! $response->successful()) {
                    $warnings[] = 'YouTube: '.(data_get($response->json(), 'error.message') ?: 'HTTP '.$response->status());
                    continue;
                }
                foreach ($response->json('items') ?? [] as $item) {
                    $vid = (string) ($item['id'] ?? '');
                    $row = $videos->firstWhere('video_id', $vid);
                    if (! $row) {
                        continue;
                    }
                    $row->update([
                        'title' => data_get($item, 'snippet.title', $row->title),
                        'channel' => data_get($item, 'snippet.channelTitle', $row->channel),
                        'published_at' => data_get($item, 'snippet.publishedAt')
                            ? Carbon::parse(data_get($item, 'snippet.publishedAt'))
                            : $row->published_at,
                        'views' => (int) data_get($item, 'statistics.viewCount', 0),
                        'likes' => (int) data_get($item, 'statistics.likeCount', 0),
                        'comments' => (int) data_get($item, 'statistics.commentCount', 0),
                        'last_synced_at' => now(),
                        'raw' => $item,
                    ]);
                    $synced++;
                }
            } catch (\Throwable $e) {
                $warnings[] = 'YouTube: '.$e->getMessage();
            }
        }

        return ['synced' => $synced, 'warnings' => $warnings];
    }

    public function snapshotToday(User $user): int
    {
        $today = now()->toDateString();
        $count = 0;

        foreach (TrackedYoutubeVideo::query()->where('user_id', $user->id)->cursor() as $yt) {
            $prev = AnalyticsSnapshot::query()
                ->where('user_id', $user->id)
                ->where('platform', 'youtube')
                ->where('external_id', $yt->video_id)
                ->where('snapshot_date', '<', $today)
                ->orderByDesc('snapshot_date')
                ->first();

            $reach = max(1, (int) $yt->views);
            $metrics = [
                'reach' => $reach,
                'likes' => (int) $yt->likes,
                'comments' => (int) $yt->comments,
                'shares' => 0,
                'saves' => 0,
                'views' => (int) $yt->views,
            ];
            $topic = $yt->formula_tag ?: $this->scorer->detectTopicCluster($yt->title);
            $formula = $this->scorer->formulaKey($yt->format ?: 'video', $topic);

            AnalyticsSnapshot::query()->updateOrCreate(
                [
                    'platform' => 'youtube',
                    'external_id' => $yt->video_id,
                    'snapshot_date' => $today,
                ],
                [
                    'user_id' => $user->id,
                    'content_post_id' => null,
                    'views' => (int) $yt->views,
                    'impressions' => (int) $yt->views,
                    'reach' => $reach,
                    'likes' => (int) $yt->likes,
                    'comments' => (int) $yt->comments,
                    'shares' => 0,
                    'saves' => 0,
                    'avg_watch_seconds' => (float) ($yt->avg_watch_seconds ?? 0),
                    'retention_rate' => (float) ($yt->retention_rate ?? 0),
                    'engagement_rate' => $this->scorer->engagementRate($metrics),
                    'viral_score' => $this->scorer->score($metrics),
                    'delta_views' => (int) $yt->views - (int) ($prev->views ?? 0),
                    'delta_shares' => 0,
                    'format' => $yt->format ?: 'video',
                    'topic_cluster' => $topic,
                    'formula_key' => $formula,
                    'raw' => [
                        'title' => $yt->title,
                        'url' => $yt->url,
                        'estimated_minutes_watched' => $yt->estimated_minutes_watched ?? 0,
                    ],
                ]
            );
            $count++;
        }

        return $count;
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(User $user, int $days = 30): array
    {
        $from = now()->subDays(max(7, min(90, $days)))->startOfDay();
        $monthStart = now()->startOfMonth();
        $prevMonthStart = now()->subMonthNoOverflow()->startOfMonth();
        $prevMonthEnd = (clone $monthStart)->subSecond();

        $yt = TrackedYoutubeVideo::query()->where('user_id', $user->id)->orderByDesc('views')->get();

        $todaySnaps = AnalyticsSnapshot::query()
            ->where('user_id', $user->id)
            ->where('platform', 'youtube')
            ->whereDate('snapshot_date', now()->toDateString())
            ->get();

        $trend = AnalyticsSnapshot::query()
            ->where('user_id', $user->id)
            ->where('platform', 'youtube')
            ->where('snapshot_date', '>=', $from->toDateString())
            ->selectRaw('snapshot_date, SUM(views) as views, SUM(shares) as shares, SUM(likes) as likes, AVG(retention_rate) as retention_avg, SUM(delta_views) as delta_views')
            ->groupBy('snapshot_date')
            ->orderBy('snapshot_date')
            ->get();

        $formulasThis = $this->formulaLeaderboard($user, $monthStart, now());
        $formulasPrev = $this->formulaLeaderboard($user, $prevMonthStart, $prevMonthEnd);
        $prevMap = collect($formulasPrev)->keyBy('formula_key');

        $winning = collect($formulasThis)->map(function ($row) use ($prevMap) {
            $prev = $prevMap->get($row['formula_key']);
            $prevScore = (float) ($prev['avg_viral_score'] ?? 0);
            $delta = round($row['avg_viral_score'] - $prevScore, 2);

            return $row + [
                'prev_avg_viral_score' => $prevScore,
                'score_delta' => $delta,
                'trend' => $delta > 3 ? 'naik' : ($delta < -3 ? 'turun' : 'stabil'),
            ];
        })->sortByDesc('avg_viral_score')->values()->all();

        $movers = $todaySnaps
            ->sortByDesc(fn ($s) => abs((int) $s->delta_views))
            ->take(12)
            ->values()
            ->map(fn ($s) => [
                'platform' => 'youtube',
                'external_id' => $s->external_id,
                'format' => $s->format,
                'formula_key' => $s->formula_key,
                'views' => (int) $s->views,
                'shares' => (int) $s->shares,
                'retention_rate' => (float) $s->retention_rate,
                'delta_views' => (int) $s->delta_views,
                'delta_shares' => (int) $s->delta_shares,
                'viral_score' => (float) $s->viral_score,
                'title' => data_get($s->raw, 'title') ?: $s->external_id,
                'permalink' => data_get($s->raw, 'url'),
            ]);

        $scoredYt = $yt->map(function ($v) {
            $reach = max(1, (int) $v->views);
            $metrics = [
                'reach' => $reach,
                'likes' => (int) $v->likes,
                'comments' => (int) $v->comments,
                'shares' => 0,
                'saves' => 0,
                'views' => (int) $v->views,
            ];

            return [
                'viral_score' => $this->scorer->score($metrics),
                'engagement_rate' => $this->scorer->engagementRate($metrics),
                'retention_rate' => (float) ($v->retention_rate ?? 0),
            ];
        });

        return [
            'summary' => [
                'meta_posts' => 0,
                'youtube_tracked' => $yt->count(),
                'total_views' => (int) $yt->sum('views'),
                'total_shares' => 0,
                'avg_retention' => round(min(100, (float) $yt->avg('retention_rate')), 2),
                'avg_viral_score' => round((float) $scoredYt->avg('viral_score'), 2),
                'delta_views_today' => (int) $todaySnaps->sum('delta_views'),
            ],
            'winning_formulas' => $winning,
            'daily_trend' => $trend,
            'movers' => $movers,
            'top_meta' => [],
            'youtube' => $yt->map(fn ($v) => [
                'id' => $v->id,
                'video_id' => $v->video_id,
                'title' => $v->title,
                'url' => $v->url,
                'views' => (int) $v->views,
                'likes' => (int) $v->likes,
                'comments' => (int) $v->comments,
                'avg_watch_seconds' => (float) ($v->avg_watch_seconds ?? 0),
                'retention_rate' => (float) ($v->retention_rate ?? 0),
                'estimated_minutes_watched' => (int) ($v->estimated_minutes_watched ?? 0),
                'formula_tag' => $v->formula_tag,
                'last_synced_at' => optional($v->last_synced_at)?->toDateTimeString(),
            ]),
            'insight' => $this->buildInsight($winning),
            'youtube_configured' => filled(config('radar.youtube_api_key')),
            'youtube_oauth_configured' => filled(config('youtube.client_id')) && filled(config('youtube.client_secret')),
            'youtube_oauth' => $this->youtubeOauthSummary($user),
            'meta_pages' => 0,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function youtubeOauthSummary(User $user): ?array
    {
        $account = YoutubeAccount::query()->where('user_id', $user->id)->orderByDesc('id')->first();
        if (! $account) {
            return null;
        }

        return [
            'id' => $account->id,
            'channel_id' => $account->channel_id,
            'channel_title' => $account->channel_title,
            'email' => $account->email,
            'picture_url' => $account->picture_url,
            'last_synced_at' => optional($account->last_synced_at)?->toDateTimeString(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function formulaLeaderboard(User $user, Carbon $from, Carbon $to): array
    {
        $videos = TrackedYoutubeVideo::query()
            ->where('user_id', $user->id)
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('published_at', [$from, $to])
                    ->orWhere(function ($q2) use ($from, $to) {
                        $q2->whereNull('published_at')
                            ->whereBetween('updated_at', [$from, $to]);
                    });
            })
            ->get();

        if ($videos->isEmpty()) {
            $videos = TrackedYoutubeVideo::query()->where('user_id', $user->id)->get();
        }

        $rows = $videos->map(function ($v) {
            $topic = $v->formula_tag ?: $this->scorer->detectTopicCluster($v->title);
            $format = $v->format ?: 'video';
            $reach = max(1, (int) $v->views);
            $metrics = [
                'reach' => $reach,
                'likes' => (int) $v->likes,
                'comments' => (int) $v->comments,
                'shares' => 0,
                'saves' => 0,
                'views' => (int) $v->views,
            ];

            return (object) [
                'formula_key' => $this->scorer->formulaKey($format, $topic),
                'format' => $format,
                'topic_cluster' => $topic,
                'views' => (int) $v->views,
                'likes' => (int) $v->likes,
                'retention_rate' => min(100, max(0, (float) ($v->retention_rate ?? 0))),
                'viral_score' => $this->scorer->score($metrics),
                'engagement_rate' => $this->scorer->engagementRate($metrics),
            ];
        });

        return $rows
            ->groupBy('formula_key')
            ->map(function ($group, $key) {
                [$format, $topic] = array_pad(explode('|', (string) $key, 2), 2, 'umum');

                return [
                    'formula_key' => $key,
                    'format' => $format,
                    'topic_cluster' => $topic,
                    'posts' => $group->count(),
                    'avg_viral_score' => round((float) $group->avg('viral_score'), 2),
                    'avg_engagement' => round((float) $group->avg('engagement_rate'), 2),
                    'avg_retention' => round(min(100, (float) $group->avg('retention_rate')), 2),
                    'total_views' => (int) $group->sum('views'),
                    'total_shares' => 0,
                    'share_rate' => 0,
                ];
            })
            ->sortByDesc('avg_viral_score')
            ->values()
            ->all();
    }

    public function trackYoutube(User $user, string $urlOrId, ?string $formulaTag = null): TrackedYoutubeVideo
    {
        $videoId = $this->extractYoutubeId($urlOrId);
        if ($videoId === null) {
            throw new \InvalidArgumentException('URL / ID YouTube tidak valid.');
        }

        $row = TrackedYoutubeVideo::query()->firstOrNew([
            'user_id' => $user->id,
            'video_id' => $videoId,
        ]);
        $row->url = 'https://www.youtube.com/watch?v='.$videoId;
        $row->formula_tag = $formulaTag ?: $row->formula_tag;
        $row->format = $row->format ?: 'video';
        $row->save();

        $this->syncYoutubeTracked($user);

        return $row->fresh();
    }

    /**
     * AI playbook: how to push this channel toward millions of views.
     *
     * @return array{engine:string,diagnosis:string,whats_working:array<int,string>,whats_blocking:array<int,string>,million_view_playbook:array<int,array<string,mixed>>,next_7_days:array<int,string>,title_hooks:array<int,string>,avoid:array<int,string>}
     */
    public function aiViralAnalysis(User $user): array
    {
        $dashboard = $this->dashboard($user, 30);
        $videos = collect($dashboard['youtube'] ?? []);
        if ($videos->isEmpty()) {
            throw new \RuntimeException('Belum ada video YouTube. Hubungkan channel / sync dulu.');
        }

        $kit = BrandKit::query()->where('user_id', $user->id)->orderByDesc('id')->first()
            ?: BrandKit::defaultFor($user);

        $top = $videos->sortByDesc('views')->take(8)->values();
        $highRet = $videos->filter(fn ($v) => (float) ($v['retention_rate'] ?? 0) > 0)
            ->sortByDesc('retention_rate')
            ->take(5)
            ->values();
        $low = $videos->sortBy('views')->take(5)->values();

        $fmtVideo = function ($v) {
            return sprintf(
                '- "%s" | views %s | likes %s | comments %s | retention %s%% | avg watch %ss | tag %s',
                Str::limit((string) ($v['title'] ?? $v['video_id']), 90, ''),
                number_format((int) ($v['views'] ?? 0)),
                number_format((int) ($v['likes'] ?? 0)),
                number_format((int) ($v['comments'] ?? 0)),
                round(min(100, (float) ($v['retention_rate'] ?? 0)), 1),
                round((float) ($v['avg_watch_seconds'] ?? 0), 1),
                $v['formula_tag'] ?: '-'
            );
        };

        $formulaBlock = collect($dashboard['winning_formulas'] ?? [])
            ->take(8)
            ->map(function ($f) {
                return sprintf(
                    '- %s × %s | videos %d | viral %.1f | views %s | retention %.1f%% | trend %s (Δ %s)',
                    strtoupper((string) ($f['format'] ?? 'video')),
                    $f['topic_cluster'] ?? 'umum',
                    (int) ($f['posts'] ?? 0),
                    (float) ($f['avg_viral_score'] ?? 0),
                    number_format((int) ($f['total_views'] ?? 0)),
                    min(100, (float) ($f['avg_retention'] ?? 0)),
                    $f['trend'] ?? 'stabil',
                    $f['score_delta'] ?? 0
                );
            })
            ->implode("\n");

        $summary = $dashboard['summary'] ?? [];
        $channel = data_get($dashboard, 'youtube_oauth.channel_title') ?: 'channel YouTube';
        $niche = trim((string) ($kit->niche ?: 'psikologi gelap / self-awareness'));
        $brand = trim((string) ($kit->name ?: 'Kea Abbasy'));
        $character = trim((string) ($kit->character_name ?: 'Kea'));

        $prompt = <<<PROMPT
Kamu strategist pertumbuhan YouTube (Shorts + long-form) untuk brand {$brand} niche {$niche}, karakter {$character}.
Channel: {$channel}
Target: tembus JUTAAN views (bukan tips generik).

DATA REAL CHANNEL:
Total views tracked: {$summary['total_views']}
Video tracked: {$summary['youtube_tracked']}
Avg retention: {$summary['avg_retention']}%
Avg viral score: {$summary['avg_viral_score']}
Insight sistem: {$dashboard['insight']}

FORMULA (format × topik):
{$formulaBlock}

TOP VIEWS:
{$top->map($fmtVideo)->implode("\n")}

RETENTION TERTINGGI:
{$highRet->map($fmtVideo)->implode("\n")}

VIEWS RENDAH (pelajaran gagal):
{$low->map($fmtVideo)->implode("\n")}

Tugas: analisa data di atas lalu buat playbook konkret biar channel ini naik ke skala jutaan views.
Fokus niche psikologi Indonesia (emosi, trauma, toxic, attachment, overthinking) — jangan saran umum "posting konsisten".

Keluarkan JSON SAJA:
{
  "diagnosis": "3-5 kalimat diagnosis tajam: kenapa channel masih di skala ini + apa sinyal viral yang sudah ada",
  "whats_working": ["pola yang sudah jalan di data, max 5"],
  "whats_blocking": ["penghambat tembus jutaan dari data, max 5"],
  "million_view_playbook": [
    {
      "priority": 1,
      "title": "aksi prioritas pendek",
      "why": "kenapa ini yang paling impact dari data",
      "how": "langkah eksekusi 2-4 kalimat sangat konkret",
      "example_title": "contoh judul YouTube siap pakai",
      "expected_lift": "perkiraan dampak (views / retention / share)"
    }
  ],
  "next_7_days": ["checklist hari 1-7, max 7 item"],
  "title_hooks": ["8-10 judul/hook siap uji A/B untuk niche ini"],
  "avoid": ["hal yang harus dihentikan berdasarkan data lemah, max 5"]
}

WAJIB 5 item di million_view_playbook, urut priority 1 = paling penting.
Bahasa Indonesia, langsung, tanpa basa-basi motivational.
PROMPT;

        $ai = $this->callGeminiViralJson($prompt);
        if ($ai !== null) {
            return $ai + ['engine' => 'gemini'];
        }

        return $this->fallbackViralAnalysis($dashboard, $top, $highRet) + ['engine' => 'template'];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function callGeminiViralJson(string $prompt): ?array
    {
        $apiKey = trim((string) config('blueprint.gemini_api_key', ''), " \t\n\r\0\x0B\"'");
        if ($apiKey === '') {
            return null;
        }

        $models = array_values(array_unique(array_filter([
            (string) config('blueprint.gemini_model', 'gemini-3.8-flash'),
            ...((array) config('blueprint.gemini_model_fallbacks', [])),
        ])));

        foreach ($models as $model) {
            try {
                $response = Http::timeout(90)
                    ->withHeaders(['Content-Type' => 'application/json'])
                    ->post(
                        "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}",
                        [
                            'contents' => [['parts' => [['text' => $prompt]]]],
                            'generationConfig' => [
                                'temperature' => 0.7,
                                'responseMimeType' => 'application/json',
                            ],
                        ]
                    );

                if (! $response->successful()) {
                    Log::warning("Analytics AI Gemini {$model}: HTTP ".$response->status());
                    continue;
                }

                $text = (string) data_get($response->json(), 'candidates.0.content.parts.0.text', '');
                if ($text === '') {
                    continue;
                }
                if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $text, $m)) {
                    $text = trim($m[1]);
                }

                $decoded = json_decode($text, true);
                if (! is_array($decoded) || blank($decoded['diagnosis'] ?? null)) {
                    continue;
                }

                $playbook = [];
                foreach ($decoded['million_view_playbook'] ?? [] as $i => $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $playbook[] = [
                        'priority' => (int) ($row['priority'] ?? ($i + 1)),
                        'title' => Str::limit(trim((string) ($row['title'] ?? '')), 120, ''),
                        'why' => trim((string) ($row['why'] ?? '')),
                        'how' => trim((string) ($row['how'] ?? '')),
                        'example_title' => trim((string) ($row['example_title'] ?? '')),
                        'expected_lift' => trim((string) ($row['expected_lift'] ?? '')),
                    ];
                }
                $playbook = array_values(array_filter($playbook, fn ($p) => $p['title'] !== ''));
                if ($playbook === []) {
                    continue;
                }

                $strList = fn ($key, int $max) => array_values(array_slice(array_filter(array_map(
                    fn ($x) => is_string($x) ? trim($x) : '',
                    $decoded[$key] ?? []
                )), 0, $max));

                return [
                    'diagnosis' => trim((string) $decoded['diagnosis']),
                    'whats_working' => $strList('whats_working', 5),
                    'whats_blocking' => $strList('whats_blocking', 5),
                    'million_view_playbook' => array_slice($playbook, 0, 5),
                    'next_7_days' => $strList('next_7_days', 7),
                    'title_hooks' => $strList('title_hooks', 10),
                    'avoid' => $strList('avoid', 5),
                ];
            } catch (\Throwable $e) {
                Log::warning("Analytics AI Gemini {$model}: ".$e->getMessage());
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $dashboard
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $top
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $highRet
     * @return array<string, mixed>
     */
    protected function fallbackViralAnalysis(array $dashboard, $top, $highRet): array
    {
        $bestFormula = $dashboard['winning_formulas'][0] ?? null;
        $bestLabel = $bestFormula
            ? strtoupper((string) ($bestFormula['format'] ?? 'video')).' × '.($bestFormula['topic_cluster'] ?? 'umum')
            : 'VIDEO × emotion';
        $topTitle = $top->first()['title'] ?? 'video terbaikmu';
        $retTitle = $highRet->first()['title'] ?? $topTitle;

        return [
            'diagnosis' => 'Data channel menunjukkan skala masih ribuan–puluhan ribu views. Sinyal positif ada di retention dan formula '.$bestLabel.', tapi volume outbound (judul/hook/packaging) belum cukup agresif untuk tembus jutaan. Double-down pada pola yang sudah menang, potong topik “umum” yang tidak emosional.',
            'whats_working' => array_values(array_filter([
                'Formula terkuat saat ini: '.$bestLabel,
                'Video tinggi views: '.$topTitle,
                'Retention kuat di: '.$retTitle,
            ])),
            'whats_blocking' => [
                'Terlalu banyak judul generik / topik “umum” yang tidak memicu identitas penonton.',
                'Belum ada serialisasi sudut emosional yang diulang sampai algoritma mengunci audiens.',
                'Hook 3 detik pertama dan packaging thumbnail/title belum diuji A/B secara sistematis.',
            ],
            'million_view_playbook' => [
                [
                    'priority' => 1,
                    'title' => 'Dobelkan formula retention tertinggi',
                    'why' => 'Retention tinggi = sinyal kuat ke algoritma Shorts/YouTube.',
                    'how' => 'Ambil 5 judul dari video retention tertinggi, tulis ulang sudutnya jadi 10 versi baru. Publish 1/hari selama 10 hari.',
                    'example_title' => 'Kalau kamu selalu merasa bersalah setelah bilang “tidak”, ini penyebabnya',
                    'expected_lift' => 'Naikkan avg retention & push ke rekomendasi mirip',
                ],
                [
                    'priority' => 2,
                    'title' => 'Ganti “umum” jadi emosi spesifik',
                    'why' => 'Cluster umum punya volume tapi daya tembak viral lebih lemah.',
                    'how' => 'Setiap naskah harus menyentuh 1 luka: anxiety, toxic parents, attachment, overthinking, gaslight.',
                    'example_title' => 'Overthinkingmu bukan kepintaran — itu trauma yang belum selesai',
                    'expected_lift' => 'CTR + share intent naik',
                ],
                [
                    'priority' => 3,
                    'title' => 'Hook konfrontatif di 2 detik pertama',
                    'why' => 'Tanpa hook tajam, retention bagus pun jarang scale ke jutaan.',
                    'how' => 'Mulai dengan tuduhan/identitas: “Kamu bukan terlalu sensitif…” lalu baru penjelasan.',
                    'example_title' => 'Kamu bukan drama — kamu baru sadar batasmu dilanggar',
                    'expected_lift' => 'Swipe-away turun di detik awal',
                ],
                [
                    'priority' => 4,
                    'title' => 'Bikin 1 serial 7 episode',
                    'why' => 'Serial menaikkan returning viewers & session time.',
                    'how' => 'Pilih 1 luka (mis. toxic relationship) → 7 sudut: tanda, pola, gaslight, keluar, guilt, relapse, healing.',
                    'example_title' => 'Episode 3: kenapa kamu selalu balik ke orang yang menyakitimu',
                    'expected_lift' => 'Compounding views antar episode',
                ],
                [
                    'priority' => 5,
                    'title' => 'Publish cadence 1–2 Shorts/hari',
                    'why' => 'Dengan data 25 video, frekuensi uji masih kurang untuk temukan outlier jutaan.',
                    'how' => 'Batch tulis 14 naskah seminggu, upload konsisten slot yang biasanya naik di analytics.',
                    'example_title' => '(pakai title_hooks di bawah)',
                    'expected_lift' => 'Lebih banyak “lottery ticket” viral',
                ],
            ],
            'next_7_days' => [
                'Hari 1: list 10 judul dari pola retention tertinggi',
                'Hari 2–3: tulis 7 naskah serial 1 topik emosional',
                'Hari 4–7: publish 1 Short/hari + catat retention 24 jam',
            ],
            'title_hooks' => [
                'Kalau kamu takut kecewain orang, ini yang sebenarnya kamu takuti',
                'Dia bukan sibuk — dia memilih tidak memprioritaskanmu',
                'Overthinking muncul saat kamu tidak merasa aman',
                'Kamu terus explain karena dulu penjelasanmu tidak pernah cukup',
                'Bukan kamu yang “terlalu banyak merasa” — kamu hanya tidak dibolehkan punya perasaan',
                'Tanda kamu trauma-bonded, bukan jatuh cinta',
                'Kenapa orang toxic selalu bilang kamu yang berlebihan',
                'Kalau kamu capek jadi “yang mengerti”, dengarkan ini',
            ],
            'avoid' => [
                'Judul edukatif datar tanpa luka emosional',
                'Konten “tips umum” tanpa sudut kontroversial',
                'Mengulang format yang views-nya sudah jelas lemah',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $insights
     * @return array{0:float,1:float}
     */
    protected function deriveWatchMetrics(array $insights): array
    {
        $avg = (float) ($insights['post_video_avg_time_watched'] ?? 0);
        // Meta kadang kirim milidetik.
        if ($avg > 600) {
            $avg = $avg / 1000;
        }

        $length = (float) ($insights['post_video_length'] ?? 0);
        if ($length > 600) {
            $length = $length / 1000;
        }

        $retention = 0.0;
        if ($avg > 0 && $length > 0) {
            $retention = round(min(100, ($avg / $length) * 100), 2);
        }

        return [round($avg, 2), $retention];
    }

    /**
     * @param  array<int, array<string, mixed>>  $winning
     */
    protected function buildInsight(array $winning): string
    {
        if ($winning === []) {
            return 'Belum ada data cukup. Hubungkan YouTube / tambah video, lalu sync lagi besok untuk lihat delta harian.';
        }

        $top = $winning[0];
        $rising = collect($winning)->firstWhere('trend', 'naik');

        $line = 'Bulan ini performa YouTube paling kuat di formula '
            .strtoupper((string) $top['format']).' × '.($top['topic_cluster'] ?? 'umum')
            .' (skor viral rata-rata '.$top['avg_viral_score']
            .', retention rata-rata '.$top['avg_retention'].'%).';

        if ($rising) {
            $line .= ' Yang sedang naik vs bulan lalu: '
                .strtoupper((string) $rising['format']).' × '.($rising['topic_cluster'] ?? 'umum')
                .' (Δ '.$rising['score_delta'].').';
        }

        return $line;
    }

    protected function extractYoutubeId(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^[a-zA-Z0-9_-]{11}$/', $value)) {
            return $value;
        }
        if (preg_match('/(?:v=|youtu\.be\/|shorts\/)([a-zA-Z0-9_-]{11})/', $value, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    protected function ytHeaders(): array
    {
        $referer = rtrim((string) config('radar.youtube_http_referer'), '/') ?: 'https://heng.justusku.co.id';

        return [
            'Referer' => $referer.'/',
            'Origin' => $referer,
            'User-Agent' => 'Mozilla/5.0 (compatible; FBProAnalytics/1.0)',
        ];
    }
}
