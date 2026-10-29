<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContentIdea;
use App\Models\ContentPost;
use App\Models\WatchlistPost;
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

        $topOwn = ContentPost::query()
            ->where('user_id', $user->id)
            ->orderByDesc('viral_score')
            ->limit(5)
            ->get();

        $topWatch = WatchlistPost::query()
            ->where('user_id', $user->id)
            ->orderByDesc('estimated_score')
            ->limit(5)
            ->get();

        $generated = [];

        foreach ($topOwn as $post) {
            $cluster = $post->topic_cluster ?: 'psikologi';
            $generated[] = $user->contentIdeas()->create([
                'meta_account_id' => $post->meta_account_id,
                'title' => 'Remix: '.str($post->message ?: 'Konten menang')->limit(60),
                'angle' => 'Ambil pola performa tinggi dari kontenmu sendiri, buat sudut baru yang lebih spesifik.',
                'format' => $post->format === 'status' ? 'reel' : $post->format,
                'hook' => 'Kalau kamu sering merasakan ini...',
                'topic_cluster' => $cluster,
                'potential_score' => min(95, (float) $post->viral_score + 5),
                'status' => 'queued',
                'notes' => 'Dihasilkan dari post sendiri #'.$post->id,
            ]);
        }

        foreach ($topWatch as $post) {
            $generated[] = $user->contentIdeas()->create([
                'title' => 'Adaptasi kompetitor: '.($post->title ?: str($post->caption ?: 'Pola viral')->limit(50)),
                'angle' => 'Jangan copy. Ambil hook/struktur, tulis ulang dengan gaya Kea Abbasy + insight psikologi praktis.',
                'format' => $post->format ?: 'reel',
                'hook' => $post->hook_note ?: 'Ini yang jarang dibahas soal...',
                'topic_cluster' => 'psikologi',
                'potential_score' => min(92, (float) $post->estimated_score + 3),
                'status' => 'queued',
                'notes' => 'Dari watchlist post #'.$post->id,
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
                    'notes' => 'Seed niche psikologi',
                ]);
            }
        }

        return response()->json([
            'created' => count($generated),
            'data' => $generated,
        ]);
    }
}
