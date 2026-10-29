<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WatchlistAccount;
use App\Models\WatchlistPost;
use App\Services\ViralScoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WatchlistController extends Controller
{
    public function __construct(private ViralScoreService $scorer)
    {
    }

    public function accounts(Request $request): JsonResponse
    {
        $accounts = $request->user()
            ->watchlistAccounts()
            ->withCount('posts')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $accounts]);
    }

    public function storeAccount(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'facebook_url' => ['nullable', 'url', 'max:255'],
            'meta_id' => ['nullable', 'string', 'max:80'],
            'niche' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $account = $request->user()->watchlistAccounts()->create([
            ...$data,
            'niche' => $data['niche'] ?? 'psikologi',
        ]);

        return response()->json(['data' => $account], 201);
    }

    public function destroyAccount(Request $request, WatchlistAccount $watchlistAccount): JsonResponse
    {
        abort_unless($watchlistAccount->user_id === $request->user()->id, 403);
        $watchlistAccount->delete();

        return response()->json(['ok' => true]);
    }

    public function posts(Request $request): JsonResponse
    {
        $posts = WatchlistPost::query()
            ->where('user_id', $request->user()->id)
            ->with('account:id,name,niche')
            ->when($request->filled('account_id'), fn ($q) => $q->where('watchlist_account_id', $request->integer('account_id')))
            ->orderByDesc('estimated_score')
            ->paginate(20);

        return response()->json($posts);
    }

    public function storePost(Request $request): JsonResponse
    {
        $data = $request->validate([
            'watchlist_account_id' => ['required', 'exists:watchlist_accounts,id'],
            'title' => ['nullable', 'string', 'max:160'],
            'caption' => ['nullable', 'string'],
            'post_url' => ['nullable', 'url', 'max:255'],
            'format' => ['nullable', 'string', 'max:40'],
            'likes' => ['nullable', 'integer', 'min:0'],
            'comments' => ['nullable', 'integer', 'min:0'],
            'shares' => ['nullable', 'integer', 'min:0'],
            'hook_note' => ['nullable', 'string', 'max:255'],
        ]);

        $account = WatchlistAccount::query()->findOrFail($data['watchlist_account_id']);
        abort_unless($account->user_id === $request->user()->id, 403);

        $metrics = [
            'reach' => max(1, ((int) ($data['likes'] ?? 0)) * 8),
            'likes' => (int) ($data['likes'] ?? 0),
            'comments' => (int) ($data['comments'] ?? 0),
            'shares' => (int) ($data['shares'] ?? 0),
            'saves' => 0,
        ];

        $post = WatchlistPost::query()->create([
            ...$data,
            'user_id' => $request->user()->id,
            'format' => $data['format'] ?? 'reel',
            'estimated_score' => $this->scorer->score($metrics),
            'observed_at' => now(),
        ]);

        return response()->json(['data' => $post->load('account:id,name')], 201);
    }
}
