<?php

namespace App\Services\Analytics;

use App\Models\AnalyticsSnapshot;
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
                'avg_retention' => round((float) $yt->avg('retention_rate'), 2),
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
                'retention_rate' => (float) ($v->retention_rate ?? 0),
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
                    'avg_retention' => round((float) $group->avg('retention_rate'), 2),
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
