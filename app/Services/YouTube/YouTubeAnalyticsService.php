<?php

namespace App\Services\YouTube;

use App\Models\TrackedYoutubeVideo;
use App\Models\User;
use App\Models\YoutubeAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class YouTubeAnalyticsService
{
    public function __construct(private YouTubeOAuthService $oauth)
    {
    }

    /**
     * @return array{synced:int,warnings:array<int,string>}
     */
    public function syncUser(User $user, int $days = 28): array
    {
        $account = YoutubeAccount::query()->where('user_id', $user->id)->orderByDesc('id')->first();
        if (! $account) {
            return ['synced' => 0, 'warnings' => ['YouTube OAuth belum dihubungkan.']];
        }

        try {
            $account = $this->oauth->ensureFreshToken($account);
        } catch (\Throwable $e) {
            return ['synced' => 0, 'warnings' => [$e->getMessage()]];
        }

        try {
            $rows = $this->videoReport($account, $days);
        } catch (\Throwable $e) {
            Log::warning('YouTube Analytics report: '.$e->getMessage());

            return ['synced' => 0, 'warnings' => ['Analytics: '.$e->getMessage()]];
        }

        $synced = 0;
        foreach ($rows as $row) {
            $videoId = (string) ($row['video'] ?? '');
            if ($videoId === '') {
                continue;
            }

            TrackedYoutubeVideo::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'video_id' => $videoId,
                ],
                [
                    'url' => 'https://www.youtube.com/watch?v='.$videoId,
                    'title' => $row['title'] ?? null,
                    'channel' => $account->channel_title,
                    'format' => 'video',
                    'views' => (int) ($row['views'] ?? 0),
                    'likes' => (int) ($row['likes'] ?? 0),
                    'comments' => (int) ($row['comments'] ?? 0),
                    'avg_watch_seconds' => (float) ($row['averageViewDuration'] ?? 0),
                    'retention_rate' => round(min(100, max(0, (float) ($row['averageViewPercentage'] ?? 0))), 2),
                    'estimated_minutes_watched' => (int) round((float) ($row['estimatedMinutesWatched'] ?? 0)),
                    'last_synced_at' => now(),
                    'raw' => $row,
                ]
            );
            $synced++;
        }

        // Enrich titles for rows missing title via Data API (optional, best-effort).
        $this->enrichTitles($user, $account);

        $account->update(['last_synced_at' => now()]);

        return ['synced' => $synced, 'warnings' => []];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function videoReport(YoutubeAccount $account, int $days = 28): array
    {
        $end = Carbon::yesterday('UTC')->toDateString();
        $start = Carbon::yesterday('UTC')->subDays(max(7, min(90, $days)) - 1)->toDateString();

        $ids = $account->channel_id
            ? 'channel=='.$account->channel_id
            : 'channel==MINE';

        $metricSets = [
            'views,estimatedMinutesWatched,averageViewDuration,averageViewPercentage,likes,comments',
            'views,estimatedMinutesWatched,averageViewDuration,averageViewPercentage',
            'views,averageViewDuration,averageViewPercentage',
            'views',
        ];

        $response = null;
        $lastError = null;
        foreach ($metricSets as $metrics) {
            $response = Http::timeout(45)
                ->withToken((string) $account->access_token)
                ->get('https://youtubeanalytics.googleapis.com/v2/reports', [
                    'ids' => $ids,
                    'startDate' => $start,
                    'endDate' => $end,
                    'metrics' => $metrics,
                    'dimensions' => 'video',
                    'sort' => '-views',
                    'maxResults' => 25,
                ]);

            if ($response->successful()) {
                break;
            }

            $lastError = data_get($response->json(), 'error.message') ?: ('HTTP '.$response->status());
            $response = null;
        }

        if ($response === null) {
            throw new RuntimeException($lastError ?: 'YouTube Analytics report gagal.');
        }

        $headers = $response->json('columnHeaders') ?? [];
        $names = array_map(fn ($h) => $h['name'] ?? '', $headers);
        $rows = [];

        foreach ($response->json('rows') ?? [] as $row) {
            $mapped = [];
            foreach ($names as $i => $name) {
                $mapped[$name] = $row[$i] ?? null;
            }
            $rows[] = $mapped;
        }

        return $rows;
    }

    protected function enrichTitles(User $user, YoutubeAccount $account): void
    {
        $missing = TrackedYoutubeVideo::query()
            ->where('user_id', $user->id)
            ->where(function ($q) {
                $q->whereNull('title')->orWhere('title', '');
            })
            ->limit(40)
            ->pluck('video_id')
            ->all();

        if ($missing === []) {
            return;
        }

        try {
            $response = Http::timeout(25)
                ->withToken((string) $account->access_token)
                ->get('https://www.googleapis.com/youtube/v3/videos', [
                    'part' => 'snippet',
                    'id' => implode(',', $missing),
                ]);
            if (! $response->successful()) {
                return;
            }
            foreach ($response->json('items') ?? [] as $item) {
                $id = (string) ($item['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                TrackedYoutubeVideo::query()
                    ->where('user_id', $user->id)
                    ->where('video_id', $id)
                    ->update([
                        'title' => data_get($item, 'snippet.title'),
                        'channel' => data_get($item, 'snippet.channelTitle', $account->channel_title),
                    ]);
            }
        } catch (\Throwable $e) {
            Log::info('YouTube title enrich skip: '.$e->getMessage());
        }
    }
}
