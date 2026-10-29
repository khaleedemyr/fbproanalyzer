<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AngleFinding;
use App\Models\ContentIdea;
use App\Services\Angle\AngleFinderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AngleFinderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = AngleFinding::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return response()->json(['data' => $items]);
    }

    public function show(Request $request, AngleFinding $angleFinding): JsonResponse
    {
        abort_unless($angleFinding->user_id === $request->user()->id, 403);

        return response()->json(['data' => $angleFinding]);
    }

    public function analyze(Request $request, AngleFinderService $finder): JsonResponse
    {
        $data = $request->validate([
            'topic' => ['required', 'string', 'max:180'],
        ]);

        $finding = $finder->analyze($request->user(), $data['topic']);

        return response()->json([
            'message' => $finder->lastEngine() === 'gemini'
                ? 'Angle Finder siap (AI Gemini + komentar YouTube).'
                : 'Angle Finder siap (template / partial).',
            'data' => $finding,
            'engine' => $finder->lastEngine(),
            'warning' => $finder->lastWarning(),
            'youtube_configured' => filled(config('radar.youtube_api_key')),
            'gemini_configured' => filled(config('blueprint.gemini_api_key')),
        ], 201);
    }

    public function saveIdea(Request $request, AngleFinding $angleFinding): JsonResponse
    {
        abort_unless($angleFinding->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'angle_index' => ['required', 'integer', 'min:0'],
        ]);

        $angles = $angleFinding->angles ?? [];
        $idx = (int) $data['angle_index'];
        abort_unless(isset($angles[$idx]), 422, 'Angle tidak ditemukan.');

        $row = $angles[$idx];
        $idea = ContentIdea::query()->create([
            'user_id' => $request->user()->id,
            'title' => (string) ($row['title'] ?? 'Sudut dari Angle Finder'),
            'angle' => (string) ($row['angle'] ?? ''),
            'hook' => (string) ($row['hook'] ?? ''),
            'format' => 'reel',
            'topic_cluster' => $angleFinding->topic,
            'potential_score' => max(50, min(95, (int) ($row['controversy_score'] ?? 70))),
            'status' => 'queued',
            'notes' => 'Angle Finder #'.$angleFinding->id
                .' · emosi: '.($row['emotion'] ?? '-')
                .' · '.($row['why_it_works'] ?? ''),
        ]);

        return response()->json([
            'message' => 'Sudut disimpan ke Ide Konten.',
            'data' => $idea,
        ], 201);
    }

    public function destroy(Request $request, AngleFinding $angleFinding): JsonResponse
    {
        abort_unless($angleFinding->user_id === $request->user()->id, 403);
        $angleFinding->delete();

        return response()->json(['ok' => true]);
    }
}
