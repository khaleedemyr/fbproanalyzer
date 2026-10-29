<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContentIdea;
use App\Models\WatchlistPost;
use App\Services\ViralScoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __construct(private ViralScoreService $scorer)
    {
    }

    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();

        $viralPosts = WatchlistPost::query()
            ->where('user_id', $user->id)
            ->with('account:id,name,niche')
            ->orderByDesc('estimated_score')
            ->limit(10)
            ->get();

        $formatStats = WatchlistPost::query()
            ->where('user_id', $user->id)
            ->selectRaw('format, COUNT(*) as total, AVG(estimated_score) as avg_score')
            ->groupBy('format')
            ->orderByDesc('avg_score')
            ->get();

        $topicBuckets = [];
        foreach ($viralPosts as $post) {
            $cluster = $this->scorer->detectTopicCluster(($post->title ?? '').' '.($post->caption ?? '').' '.($post->hook_note ?? ''))
                ?: 'umum';
            if (! isset($topicBuckets[$cluster])) {
                $topicBuckets[$cluster] = ['topic_cluster' => $cluster, 'total' => 0, 'avg_score' => 0.0, 'sum' => 0.0];
            }
            $topicBuckets[$cluster]['total']++;
            $topicBuckets[$cluster]['sum'] += (float) $post->estimated_score;
        }
        $topicStats = collect($topicBuckets)
            ->map(function (array $row) {
                $row['avg_score'] = $row['total'] > 0 ? round($row['sum'] / $row['total'], 2) : 0;
                unset($row['sum']);

                return $row;
            })
            ->sortByDesc('avg_score')
            ->values();

        $topCreators = WatchlistPost::query()
            ->where('watchlist_posts.user_id', $user->id)
            ->join('watchlist_accounts', 'watchlist_accounts.id', '=', 'watchlist_posts.watchlist_account_id')
            ->select([
                'watchlist_accounts.id',
                'watchlist_accounts.name',
                DB::raw('COUNT(watchlist_posts.id) as posts_count'),
                DB::raw('AVG(watchlist_posts.estimated_score) as avg_score'),
                DB::raw('MAX(watchlist_posts.estimated_score) as max_score'),
            ])
            ->groupBy('watchlist_accounts.id', 'watchlist_accounts.name')
            ->orderByDesc('avg_score')
            ->limit(8)
            ->get();

        return response()->json([
            'focus' => 'viral_niche_radar',
            'niche' => 'psikologi',
            'stats' => [
                'creators' => $user->watchlistAccounts()->count(),
                'viral_posts' => WatchlistPost::query()->where('user_id', $user->id)->count(),
                'ideas' => $user->contentIdeas()->where('status', 'queued')->count(),
                'avg_viral_score' => round((float) WatchlistPost::query()->where('user_id', $user->id)->avg('estimated_score'), 2),
            ],
            'viral_board' => $viralPosts,
            'format_stats' => $formatStats,
            'topic_stats' => $topicStats,
            'top_creators' => $topCreators,
            'idea_queue' => ContentIdea::query()
                ->where('user_id', $user->id)
                ->where('status', 'queued')
                ->orderByDesc('potential_score')
                ->limit(6)
                ->get(),
            'next_actions' => [
                'Tambah 10–15 creator psikologi ke Watchlist',
                'Setiap hari catat 3–5 post yang lagi panas (like/komen/share)',
                'Generate ide dari pola viral sebelum produksi',
            ],
        ]);
    }
}
