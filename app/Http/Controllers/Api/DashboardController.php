<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContentIdea;
use App\Models\ContentPost;
use App\Models\WatchlistPost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $days = (int) $request->integer('days', 30);
        $since = now()->subDays(max(7, min(90, $days)));

        $posts = ContentPost::query()
            ->where('user_id', $user->id)
            ->where(function ($q) use ($since) {
                $q->where('published_at', '>=', $since)
                    ->orWhereNull('published_at');
            })
            ->orderByDesc('viral_score')
            ->limit(10)
            ->with('metaAccount:id,name,type,niche')
            ->get();

        $formatStats = ContentPost::query()
            ->where('user_id', $user->id)
            ->selectRaw('format, COUNT(*) as total, AVG(viral_score) as avg_score, AVG(engagement_rate) as avg_engagement')
            ->groupBy('format')
            ->orderByDesc('avg_score')
            ->get();

        $topicStats = ContentPost::query()
            ->where('user_id', $user->id)
            ->whereNotNull('topic_cluster')
            ->selectRaw('topic_cluster, COUNT(*) as total, AVG(viral_score) as avg_score')
            ->groupBy('topic_cluster')
            ->orderByDesc('avg_score')
            ->limit(8)
            ->get();

        return response()->json([
            'stats' => [
                'accounts' => $user->metaAccounts()->count(),
                'posts' => $user->contentPosts()->count(),
                'watchlist' => $user->watchlistAccounts()->count(),
                'ideas' => $user->contentIdeas()->where('status', 'queued')->count(),
                'avg_viral_score' => round((float) $user->contentPosts()->avg('viral_score'), 2),
            ],
            'top_posts' => $posts,
            'format_stats' => $formatStats,
            'topic_stats' => $topicStats,
            'recent_watchlist' => WatchlistPost::query()
                ->where('user_id', $user->id)
                ->with('account:id,name')
                ->orderByDesc('estimated_score')
                ->limit(5)
                ->get(),
            'idea_queue' => ContentIdea::query()
                ->where('user_id', $user->id)
                ->where('status', 'queued')
                ->orderByDesc('potential_score')
                ->limit(5)
                ->get(),
        ]);
    }
}
