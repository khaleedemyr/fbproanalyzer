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
        @set_time_limit(600);
        @ini_set('memory_limit', '512M');

        $formatList = implode(',', BlueprintGeneratorService::FORMATS);
        try {
            $data = $request->validate([
                'format' => ['nullable', 'in:'.$formatList],
                'planned_date' => ['nullable', 'date'],
                'planned_time' => ['nullable', 'date_format:H:i'],
                'duration_seconds' => ['nullable', 'integer', 'min:5', 'max:3600'],
                'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:60'],
                'visual_beat_seconds' => ['nullable', 'integer', 'min:5', 'max:30'],
                'vo_beat_seconds' => ['nullable', 'integer', 'min:3', 'max:30'],
                'beat_seconds' => ['nullable', 'integer', 'min:3', 'max:30'],
                'prefer_stock' => ['sometimes', 'boolean'],
                'voice_perspective' => ['nullable', 'string', 'max:40'],
                'force' => ['sometimes', 'boolean'],
                'topic' => ['nullable', 'string', 'max:500'],
                'working_title' => ['nullable', 'string', 'max:200'],
                'hook' => ['nullable', 'string', 'max:2000'],
                'angle' => ['nullable', 'string', 'max:4000'],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => collect($e->errors())->flatten()->implode(' '),
                'errors' => $e->errors(),
            ], 422);
        }

        try {
            $bp = $studio->generateBlueprint($request->user(), $contentBrief, $data);
        } catch (\Throwable $e) {
            report($e);
            $hint = $e->getMessage();
            if (str_contains($hint, 'vo_beat_seconds')) {
                $hint .= ' — jalankan di Hostinger: php artisan migrate --force';
            }

            return response()->json(['message' => 'Gagal generate blueprint dari resume: '.$hint], 500);
        }

        $bp->load(['brandKit', 'analyticsAiAnalysis', 'contentBrief']);
        $generator = app(BlueprintGeneratorService::class);
        $sceneCount = count($bp->scenes ?? []);
        // Markdown penuh untuk 100+ scene bikin response timeout di shared hosting.
        $markdown = $sceneCount > 120
            ? ''
            : $generator->toMarkdown($bp, $bp->brandKit ?? BrandKit::defaultFor($request->user()));

        return response()->json([
            'message' => 'Blueprint dari Brief Studio siap'
                .($bp->engine === 'gemini' ? ' (AI Gemini).' : ' (template).'),
            'data' => $bp,
            'brief' => $contentBrief->fresh()->toBriefArray(),
            'markdown' => $markdown,
            'engine' => $bp->engine,
            'warning' => $generator->lastWarning(),
        ], 201);
    }

    public function destroy(Request $request, ContentBrief $contentBrief): JsonResponse
    {
        abort_unless($contentBrief->user_id === $request->user()->id, 403);
        $contentBrief->delete();

        return response()->json(['ok' => true]);
    }
}
