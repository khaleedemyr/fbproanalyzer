<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContentPost;
use App\Models\MetaAccount;
use App\Services\MetaGraphService;
use App\Services\ViralScoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class MetaAccountController extends Controller
{
    public function __construct(
        private MetaGraphService $meta,
        private ViralScoreService $scorer,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $accounts = $request->user()
            ->metaAccounts()
            ->withCount('posts')
            ->orderByDesc('is_primary')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $accounts]);
    }

    public function update(Request $request, MetaAccount $metaAccount): JsonResponse
    {
        abort_unless($metaAccount->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'niche' => ['sometimes', 'string', 'max:80'],
            'is_primary' => ['sometimes', 'boolean'],
        ]);

        if (($data['is_primary'] ?? false) === true) {
            $request->user()->metaAccounts()->update(['is_primary' => false]);
        }

        $metaAccount->update($data);

        return response()->json(['data' => $metaAccount->fresh()]);
    }

    public function sync(Request $request, MetaAccount $metaAccount): JsonResponse
    {
        abort_unless($metaAccount->user_id === $request->user()->id, 403);

        if ($metaAccount->type !== 'page' || blank($metaAccount->access_token)) {
            return response()->json([
                'message' => 'Sync Insights saat ini tersedia untuk Page yang punya token. Profile Personal Pro menyusul setelah permission siap.',
            ], 422);
        }

        $posts = $this->meta->pagePosts($metaAccount->meta_id, $metaAccount->access_token);
        $synced = 0;

        foreach ($posts as $post) {
            $likes = (int) data_get($post, 'likes.summary.total_count', 0);
            $comments = (int) data_get($post, 'comments.summary.total_count', 0);
            $shares = (int) data_get($post, 'shares.count', 0);
            $insights = $this->meta->postInsights($post['id'], $metaAccount->access_token);

            $reach = (int) ($insights['post_impressions_unique'] ?? 0);
            $impressions = (int) ($insights['post_impressions'] ?? 0);
            if ($reach <= 0) {
                $reach = max(1, $likes + $comments + $shares);
            }

            $metrics = compact('reach', 'likes', 'comments', 'shares') + ['saves' => 0];

            ContentPost::query()->updateOrCreate(
                ['meta_post_id' => $post['id']],
                [
                    'user_id' => $request->user()->id,
                    'meta_account_id' => $metaAccount->id,
                    'permalink' => $post['permalink_url'] ?? null,
                    'format' => $this->scorer->detectFormat($post['status_type'] ?? null, $post['message'] ?? null),
                    'message' => $post['message'] ?? null,
                    'media_url' => $post['full_picture'] ?? null,
                    'published_at' => isset($post['created_time']) ? Carbon::parse($post['created_time']) : null,
                    'reach' => $reach,
                    'impressions' => $impressions,
                    'likes' => $likes,
                    'comments' => $comments,
                    'shares' => $shares,
                    'engagement_rate' => $this->scorer->engagementRate($metrics),
                    'viral_score' => $this->scorer->score($metrics),
                    'topic_cluster' => $this->scorer->detectTopicCluster($post['message'] ?? null),
                    'raw_insights' => $insights,
                ]
            );

            $synced++;
        }

        $metaAccount->update(['last_synced_at' => now()]);

        return response()->json([
            'synced' => $synced,
            'account' => $metaAccount->fresh()->loadCount('posts'),
        ]);
    }
}
