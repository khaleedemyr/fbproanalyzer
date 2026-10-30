<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContentBrief;
use App\Services\Blueprint\BlueprintGeneratorService;
use App\Services\Brief\BriefStudioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
        @set_time_limit(120);
        @ini_set('memory_limit', '512M');

        Log::info('brief_studio.blueprint.start', [
            'brief_id' => $contentBrief->id,
            'user_id' => $request->user()->id,
            'format' => $request->input('format'),
            'duration_minutes' => $request->input('duration_minutes'),
            'use_ai' => $request->input('use_ai'),
        ]);

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
                'use_ai' => ['sometimes', 'boolean'],
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
            Log::error('brief_studio.blueprint.fail', [
                'brief_id' => $contentBrief->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Gagal generate blueprint dari resume: '.$e->getMessage(),
            ], 500);
        }

        $async = $bp->status === 'generating';

        Log::info('brief_studio.blueprint.accepted', [
            'brief_id' => $contentBrief->id,
            'blueprint_id' => $bp->id,
            'status' => $bp->status,
            'async' => $async,
        ]);

        return response()->json([
            'message' => $async
                ? 'Blueprint 5–60 menit mulai disusun di background (bisa sampai 1 jam). Halaman detail akan update otomatis.'
                : ('Blueprint dari Brief Studio siap'
                    .($bp->engine === 'gemini' ? ' (AI Gemini).' : ' (template).')),
            'data' => [
                'id' => $bp->id,
                'title' => $bp->title,
                'cover_theme' => $bp->cover_theme,
                'format' => $bp->format,
                'engine' => $bp->engine,
                'duration_seconds' => $bp->duration_seconds,
                'beat_seconds' => $bp->beat_seconds,
                'vo_beat_seconds' => $bp->vo_beat_seconds,
                'status' => $bp->status,
            ],
            'brief' => $contentBrief->fresh()->toBriefArray(),
            'markdown' => '',
            'engine' => $bp->engine,
            'async' => $async,
            'warning' => null,
        ], 201);
    }

    public function destroy(Request $request, ContentBrief $contentBrief): JsonResponse
    {
        abort_unless($contentBrief->user_id === $request->user()->id, 403);
        $contentBrief->delete();

        return response()->json(['message' => 'Resume dihapus.']);
    }
}
