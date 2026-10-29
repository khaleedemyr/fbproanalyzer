<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContentIdea;
use App\Models\WatchlistPost;
use App\Services\ViralScoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContentIdeaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $ideas = $request->user()
            ->contentIdeas()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByDesc('potential_score')
            ->paginate(20);

        return response()->json($ideas);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'angle' => ['nullable', 'string'],
            'format' => ['nullable', 'string', 'max:40'],
            'hook' => ['nullable', 'string', 'max:255'],
            'topic_cluster' => ['nullable', 'string', 'max:80'],
            'potential_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string'],
            'meta_account_id' => ['nullable', 'exists:meta_accounts,id'],
        ]);

        $idea = $request->user()->contentIdeas()->create([
            ...$data,
            'format' => $data['format'] ?? 'reel',
            'potential_score' => $data['potential_score'] ?? 50,
            'status' => 'queued',
        ]);

        return response()->json(['data' => $idea], 201);
    }

    public function update(Request $request, ContentIdea $contentIdea): JsonResponse
    {
        abort_unless($contentIdea->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:180'],
            'angle' => ['nullable', 'string'],
            'format' => ['nullable', 'string', 'max:40'],
            'hook' => ['nullable', 'string', 'max:255'],
            'topic_cluster' => ['nullable', 'string', 'max:80'],
            'potential_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'status' => ['nullable', 'in:queued,in_progress,published,rejected'],
            'notes' => ['nullable', 'string'],
        ]);

        $contentIdea->update($data);

        return response()->json(['data' => $contentIdea->fresh()]);
    }

    public function destroy(Request $request, ContentIdea $contentIdea): JsonResponse
    {
        abort_unless($contentIdea->user_id === $request->user()->id, 403);
        $contentIdea->delete();

        return response()->json(['ok' => true]);
    }

    public function generate(Request $request): JsonResponse
    {
        $user = $request->user();
        $scorer = app(ViralScoreService::class);

        $topWatch = WatchlistPost::query()
            ->where('user_id', $user->id)
            ->orderByDesc('estimated_score')
            ->limit(8)
            ->get();

        $generated = [];

        foreach ($topWatch as $post) {
            $cluster = $scorer->detectTopicCluster(($post->title ?? '').' '.($post->caption ?? '').' '.($post->hook_note ?? ''))
                ?: 'psikologi';

            $generated[] = $user->contentIdeas()->create([
                'title' => 'Versi Kea Abbasy: '.($post->title ?: str($post->caption ?: 'Pola viral niche')->limit(50)),
                'angle' => 'Jangan copy. Ambil hook/struktur dari konten viral niche, tulis ulang dengan gaya Kea Abbasy + insight praktis.',
                'format' => $post->format ?: 'reel',
                'hook' => $post->hook_note ?: 'Kalau ini terasa familiar, kamu tidak sendiri...',
                'topic_cluster' => $cluster,
                'potential_score' => min(95, (float) $post->estimated_score + 4),
                'status' => 'queued',
                'notes' => 'Dari viral watchlist #'.$post->id,
            ]);
        }

        if ($generated === []) {
            $seed = [
                ['title' => 'Self-check: 5 tanda overthinking yang disamarkan sebagai teliti', 'cluster' => 'emotion'],
                ['title' => 'Mitos vs fakta: “cuek = kuat secara mental”', 'cluster' => 'self-worth'],
                ['title' => 'Kenapa validasi dari orang lain terasa seperti oksigen', 'cluster' => 'relationship'],
            ];

            foreach ($seed as $item) {
                $generated[] = $user->contentIdeas()->create([
                    'title' => $item['title'],
                    'angle' => 'Hook emosional + insight praktis + CTA simpan/share.',
                    'format' => 'reel',
                    'hook' => 'Kalau ini terasa familiar, kamu tidak sendiri.',
                    'topic_cluster' => $item['cluster'],
                    'potential_score' => 68,
                    'status' => 'queued',
                    'notes' => 'Seed niche psikologi — isi Watchlist agar ide lebih tajam',
                ]);
            }
        }

        return response()->json([
            'created' => count($generated),
            'data' => $generated,
        ]);
    }
}
