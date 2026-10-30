<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BrandKit;
use App\Models\ContentBrief;
use App\Services\Blueprint\BlueprintGeneratorService;
use App\Services\Brief\BriefStudioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BriefStudioController extends Controller
{
    public function sources(Request $request, BriefStudioService $studio): JsonResponse
    {
        $filters = $request->validate([
            'topic' => ['nullable', 'string', 'max:255'],
            'trend_signal_ids' => ['nullable', 'array'],
            'trend_signal_ids.*' => ['integer'],
            'angle_finding_id' => ['nullable', 'integer'],
            'analytics_ai_analysis_id' => ['nullable', 'integer'],
        ]);

        try {
            return response()->json([
                'data' => $studio->sources($request->user(), $filters),
            ]);
        } catch (\Throwable $e) {
            report($e);
            $hint = str_contains($e->getMessage(), "doesn't exist") || str_contains($e->getMessage(), 'Base table')
                ? ' Tabel database belum lengkap — di Hostinger jalankan: php artisan migrate --force'
                : '';

            return response()->json([
                'message' => 'Gagal memuat sumber Brief Studio: '.$e->getMessage().$hint,
            ], 500);
        }
    }

    public function index(Request $request, BriefStudioService $studio): JsonResponse
    {
        $rows = $studio->history($request->user(), (int) $request->integer('limit', 20));

        return response()->json([
            'data' => $rows->map(fn (ContentBrief $b) => $b->toBriefArray())->values(),
        ]);
    }

    public function show(Request $request, ContentBrief $contentBrief): JsonResponse
    {
        abort_unless($contentBrief->user_id === $request->user()->id, 403);

        return response()->json(['data' => $contentBrief->toBriefArray()]);
    }

    public function compose(Request $request, BriefStudioService $studio): JsonResponse
    {
        @set_time_limit(120);

        $filters = $request->validate([
            'topic' => ['nullable', 'string', 'max:255'],
            'trend_signal_ids' => ['nullable', 'array', 'max:5'],
            'trend_signal_ids.*' => ['integer', 'exists:trend_signals,id'],
            'angle_finding_id' => ['nullable', 'integer', 'exists:angle_findings,id'],
            'angle_indexes' => ['nullable', 'array', 'max:5'],
            'angle_indexes.*' => ['integer', 'min:0', 'max:20'],
            'analytics_ai_analysis_id' => ['nullable', 'integer', 'exists:analytics_ai_analyses,id'],
        ]);

        try {
            $brief = $studio->compose($request->user(), $filters);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'Gagal compose resume: '.$e->getMessage()], 500);
        }

        return response()->json([
            'message' => $brief->engine === 'gemini'
                ? 'Resume gabungan (Radar × Angle × Analytics) siap.'
                : 'Resume template siap (Gemini tidak tersedia).',
            'data' => $brief->toBriefArray(),
        ], 201);
    }

    public function blueprint(Request $request, ContentBrief $contentBrief, BriefStudioService $studio): JsonResponse
    {
        abort_unless($contentBrief->user_id === $request->user()->id, 403);
        @set_time_limit(180);

        $formatList = implode(',', BlueprintGeneratorService::FORMATS);
        $data = $request->validate([
            'format' => ['nullable', 'in:'.$formatList],
            'planned_date' => ['nullable', 'date'],
            'planned_time' => ['nullable', 'date_format:H:i'],
            'duration_seconds' => ['nullable', 'integer', 'min:5', 'max:1200'],
            'duration_minutes' => ['nullable', 'integer', 'min:3', 'max:20'],
            'prefer_stock' => ['sometimes', 'boolean'],
            'voice_perspective' => ['nullable', 'string', 'max:40'],
            'force' => ['sometimes', 'boolean'],
        ]);

        try {
            $bp = $studio->generateBlueprint($request->user(), $contentBrief, $data);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'Gagal generate blueprint dari resume: '.$e->getMessage()], 500);
        }

        $bp->load(['brandKit', 'analyticsAiAnalysis', 'contentBrief']);

        return response()->json([
            'message' => 'Blueprint dari Brief Studio siap'
                .($bp->engine === 'gemini' ? ' (AI Gemini).' : ' (template).'),
            'data' => $bp,
            'brief' => $contentBrief->fresh()->toBriefArray(),
            'markdown' => app(BlueprintGeneratorService::class)->toMarkdown(
                $bp,
                $bp->brandKit ?? BrandKit::defaultFor($request->user())
            ),
            'engine' => $bp->engine,
            'warning' => app(BlueprintGeneratorService::class)->lastWarning(),
        ], 201);
    }

    public function destroy(Request $request, ContentBrief $contentBrief): JsonResponse
    {
        abort_unless($contentBrief->user_id === $request->user()->id, 403);
        $contentBrief->delete();

        return response()->json(['ok' => true]);
    }
}
