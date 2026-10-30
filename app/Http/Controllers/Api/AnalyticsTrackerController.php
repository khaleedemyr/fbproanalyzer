<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnalyticsAiAnalysis;
use App\Models\TrackedYoutubeVideo;
use App\Services\Analytics\AnalyticsTrackerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsTrackerController extends Controller
{
    public function index(Request $request, AnalyticsTrackerService $tracker): JsonResponse
    {
        $days = (int) $request->integer('days', 30);

        return response()->json([
            'data' => $tracker->dashboard($request->user(), $days),
        ]);
    }

    public function sync(Request $request, AnalyticsTrackerService $tracker): JsonResponse
    {
        @set_time_limit(180);
        $days = (int) $request->integer('days', 30);

        try {
            $result = $tracker->syncAll($request->user());
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Gagal sync analytics: '.$e->getMessage(),
                'result' => [
                    'meta_synced' => 0,
                    'youtube_synced' => 0,
                    'snapshots' => 0,
                    'warnings' => [$e->getMessage()],
                    'accounts' => 0,
                ],
                'data' => null,
            ], 500);
        }

        try {
            $dashboard = $tracker->dashboard($request->user(), $days);
        } catch (\Throwable $e) {
            report($e);
            $dashboard = null;
            $result['warnings'][] = 'Sync data ok, tapi dashboard gagal di-render: '.$e->getMessage();
        }

        $syncedSomething = (($result['youtube_synced'] ?? 0) + ($result['snapshots'] ?? 0)) > 0;
        $warnings = $result['warnings'] ?? [];

        return response()->json([
            'message' => $syncedSomething
                ? 'Analytics sync selesai.'
                : (! empty($warnings)
                    ? 'Sync tidak menarik data baru — cek peringatan.'
                    : 'Tidak ada data untuk di-sync.'),
            'result' => $result,
            'data' => $dashboard,
        ]);
    }

    public function aiAnalyze(Request $request, AnalyticsTrackerService $tracker): JsonResponse
    {
        @set_time_limit(120);

        try {
            $analysis = $tracker->aiViralAnalysis($request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'Gagal analisa AI: '.$e->getMessage()], 500);
        }

        return response()->json([
            'message' => ($analysis['engine'] ?? '') === 'gemini'
                ? 'Analisa AI (Gemini) disimpan ke history.'
                : 'Analisa template disimpan (Gemini tidak tersedia — cek GEMINI_API_KEY).',
            'data' => $analysis,
        ], 201);
    }

    public function aiHistory(Request $request, AnalyticsTrackerService $tracker): JsonResponse
    {
        $rows = $tracker->analysisHistory($request->user(), (int) $request->integer('limit', 20));

        return response()->json([
            'data' => $rows->map(fn (AnalyticsAiAnalysis $row) => $row->toAnalysisArray())->values(),
        ]);
    }

    public function aiShow(Request $request, AnalyticsAiAnalysis $analyticsAiAnalysis): JsonResponse
    {
        abort_unless($analyticsAiAnalysis->user_id === $request->user()->id, 403);

        return response()->json(['data' => $analyticsAiAnalysis->toAnalysisArray()]);
    }

    public function aiDestroy(Request $request, AnalyticsAiAnalysis $analyticsAiAnalysis): JsonResponse
    {
        abort_unless($analyticsAiAnalysis->user_id === $request->user()->id, 403);
        $analyticsAiAnalysis->delete();

        return response()->json(['ok' => true]);
    }

    public function trackYoutube(Request $request, AnalyticsTrackerService $tracker): JsonResponse
    {
        $data = $request->validate([
            'url' => ['required', 'string', 'max:500'],
            'formula_tag' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $row = $tracker->trackYoutube($request->user(), $data['url'], $data['formula_tag'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Video YouTube ditambahkan ke tracker.',
            'data' => $row,
        ], 201);
    }

    public function destroyYoutube(Request $request, TrackedYoutubeVideo $trackedYoutubeVideo): JsonResponse
    {
        abort_unless($trackedYoutubeVideo->user_id === $request->user()->id, 403);
        $trackedYoutubeVideo->delete();

        return response()->json(['ok' => true]);
    }
}
