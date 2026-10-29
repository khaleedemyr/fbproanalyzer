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

    public function seedPsychology(Request $request): JsonResponse
    {
        $seeds = [
            ['name' => 'Psikologi Populer Indo', 'notes' => 'Seed: ganti URL ke creator nyata yang kamu pantau', 'facebook_url' => null],
            ['name' => 'Self-worth & Healing', 'notes' => 'Seed niche harga diri / luka batin', 'facebook_url' => null],
            ['name' => 'Relationship Talk', 'notes' => 'Seed niche hubungan / toxic pattern', 'facebook_url' => null],
            ['name' => 'Anxiety & Overthinking', 'notes' => 'Seed niche cemas / overthinking', 'facebook_url' => null],
            ['name' => 'Trauma Informed Edu', 'notes' => 'Seed edukasi trauma ringan', 'facebook_url' => null],
            ['name' => 'Psikolog Creator A', 'notes' => 'Ganti nama + URL creator yang sering muncul di FYP', 'facebook_url' => null],
            ['name' => 'Psikolog Creator B', 'notes' => 'Ganti nama + URL creator yang sering muncul di FYP', 'facebook_url' => null],
            ['name' => 'Relatable Story Page', 'notes' => 'Konten cerita + insight psikologi', 'facebook_url' => null],
        ];

        $created = [];
        foreach ($seeds as $seed) {
            $exists = $request->user()->watchlistAccounts()
                ->where('name', $seed['name'])
                ->exists();

            if ($exists) {
                continue;
            }

            $created[] = $request->user()->watchlistAccounts()->create([
                ...$seed,
                'niche' => 'psikologi',
                'is_active' => true,
            ]);
        }

        return response()->json([
            'created' => count($created),
            'data' => $request->user()->watchlistAccounts()->withCount('posts')->orderBy('name')->get(),
            'message' => count($created)
                ? 'Seed creator psikologi ditambahkan. Ganti nama/URL ke akun nyata yang kamu pantau.'
                : 'Seed sudah ada. Tambah creator nyata atau catat post viral.',
        ]);
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
            ->when($request->filled('format'), fn ($q) => $q->where('format', $request->string('format')))
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
            'post_url' => ['nullable', 'url', 'max:2048'],
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

    public function destroyPost(Request $request, WatchlistPost $watchlistPost): JsonResponse
    {
        abort_unless($watchlistPost->user_id === $request->user()->id, 403);
        $watchlistPost->delete();

        return response()->json(['ok' => true]);
    }
}
