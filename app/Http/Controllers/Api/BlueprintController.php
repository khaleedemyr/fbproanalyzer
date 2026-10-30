<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnalyticsAiAnalysis;
use App\Models\BrandKit;
use App\Models\ContentBlueprint;
use App\Models\ContentIdea;
use App\Models\TrendSignal;
use App\Services\Blueprint\BlueprintGeneratorService;
use App\Support\BlueprintProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BlueprintController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ContentBlueprint::query()
            ->where('user_id', $request->user()->id)
            ->with([
                'brandKit:id,name,character_name',
                'analyticsAiAnalysis:id,title,engine,created_at',
            ])
            ->orderByDesc('updated_at');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('from') || $request->filled('to')) {
            $query->whereNotNull('planned_date');
            if ($request->filled('from')) {
                $query->whereDate('planned_date', '>=', $request->date('from'));
            }
            if ($request->filled('to')) {
                $query->whereDate('planned_date', '<=', $request->date('to'));
            }
            $query->reorder()
                ->orderBy('planned_date')
                ->orderBy('planned_time');
        }

        return response()->json(['data' => $query->limit(100)->get()]);
    }

    public function show(Request $request, ContentBlueprint $blueprint): JsonResponse
    {
        abort_unless($blueprint->user_id === $request->user()->id, 403);

        $blueprint->load(['brandKit', 'contentIdea', 'trendSignal', 'analyticsAiAnalysis']);

        $markdown = '';
        if ($blueprint->status !== 'generating' && count($blueprint->scenes ?? []) <= 120) {
            $markdown = app(BlueprintGeneratorService::class)->toMarkdown(
                $blueprint,
                $blueprint->brandKit ?? BrandKit::defaultFor($request->user())
            );
        }

        return response()->json([
            'data' => $blueprint,
            'markdown' => $markdown,
            'progress' => BlueprintProgress::get($blueprint->id),
        ]);
    }

    public function progress(Request $request, ContentBlueprint $blueprint): JsonResponse
    {
        abort_unless($blueprint->user_id === $request->user()->id, 403);

        return response()->json([
            'data' => BlueprintProgress::get($blueprint->id),
            'status' => $blueprint->fresh()->status,
        ]);
    }

    /**
     * Selesaikan blueprint yang stuck di status generating (job background dibunuh hosting).
     */
    public function finishGenerate(Request $request, ContentBlueprint $blueprint, BlueprintGeneratorService $generator): JsonResponse
    {
        abort_unless($blueprint->user_id === $request->user()->id, 403);
        @set_time_limit(180);
        @ini_set('memory_limit', '512M');
        ignore_user_abort(true);

        $lockKey = 'blueprint_finish_lock_'.$blueprint->id;
        if (! Cache::add($lockKey, 1, now()->addMinutes(3))) {
            $blueprint->refresh();
            $blueprint->load(['brandKit', 'analyticsAiAnalysis']);

            return response()->json([
                'message' => 'Generate sedang berjalan — tunggu sebentar.',
                'data' => $blueprint,
                'progress' => BlueprintProgress::get($blueprint->id),
                'busy' => true,
            ]);
        }

        try {
            if (! in_array($blueprint->status, ['generating', 'failed'], true)) {
                $blueprint->load(['brandKit', 'analyticsAiAnalysis']);

                return response()->json([
                    'message' => 'Blueprint sudah selesai.',
                    'data' => $blueprint,
                    'progress' => BlueprintProgress::get($blueprint->id),
                ]);
            }

            $data = $request->validate([
                'use_ai' => ['sometimes', 'boolean'],
            ]);

            // Reels panjang: AI wajib — tidak ada jalur template.
            $useAi = $blueprint->format === BlueprintGeneratorService::FORMAT_REELS_PANJANG
                ? true
                : (array_key_exists('use_ai', $data)
                    ? (bool) $data['use_ai']
                    : ($blueprint->engine === 'pending_ai'));

            Log::info('blueprint.finish.start', [
                'blueprint_id' => $blueprint->id,
                'use_ai' => $useAi,
                'engine' => $blueprint->engine,
            ]);

            BlueprintProgress::set(
                $blueprint->id,
                10,
                $useAi ? 'Menyelesaikan dengan AI Gemini…' : 'Menyelesaikan generate…',
                'generating'
            );

            try {
                $bp = $generator->finishGeneratingBlueprint($blueprint, $request->user(), [
                    'use_ai' => $useAi,
                ]);
            } catch (\Throwable $e) {
                report($e);
                Log::error('blueprint.finish.fail', [
                    'blueprint_id' => $blueprint->id,
                    'use_ai' => $useAi,
                    'error' => $e->getMessage(),
                ]);
                BlueprintProgress::set($blueprint->id, 0, 'Gagal: '.$e->getMessage(), 'failed');
                $blueprint->refresh();

                return response()->json([
                    'message' => $useAi
                        ? ('AI Gemini gagal: '.$e->getMessage().' — tidak memakai template. Klik “Coba AI lagi”.')
                        : ('Gagal menyelesaikan generate: '.$e->getMessage()),
                    'data' => $blueprint,
                    'progress' => BlueprintProgress::get($blueprint->id),
                    'can_retry_ai' => $useAi,
                ], 500);
            }

            // AI diminta tapi hasilnya bukan gemini → anggap gagal (jangan simpan sebagai template).
            if ($useAi && $bp->engine !== 'gemini') {
                $bp->forceFill([
                    'status' => 'failed',
                    'engine' => 'failed',
                    'story_concept' => 'AI Gemini tidak menghasilkan kerangka. Tidak memakai template.',
                    'scenes' => [],
                ])->save();
                BlueprintProgress::set($bp->id, 0, 'AI gagal — tidak pakai template', 'failed');

                return response()->json([
                    'message' => 'AI Gemini tidak menghasilkan kerangka — tidak memakai template. Klik “Coba AI lagi”.',
                    'data' => $bp->fresh(),
                    'progress' => BlueprintProgress::get($bp->id),
                    'can_retry_ai' => true,
                ], 500);
            }

            $bp->load(['brandKit', 'analyticsAiAnalysis']);
            BlueprintProgress::done($bp->id, 'Blueprint siap');

            Log::info('blueprint.finish.ok', [
                'blueprint_id' => $bp->id,
                'engine' => $bp->engine,
                'scenes' => count($bp->scenes ?? []),
            ]);

            return response()->json([
                'message' => $bp->engine === 'gemini'
                    ? 'Blueprint panjang siap (AI Gemini + expand timeline).'
                    : 'Blueprint panjang siap.',
                'data' => $bp,
                'engine' => $bp->engine,
                'warning' => $generator->lastWarning(),
                'progress' => BlueprintProgress::get($bp->id),
            ]);
        } finally {
            Cache::forget($lockKey);
        }
    }

    public function generate(Request $request, BlueprintGeneratorService $generator): JsonResponse
    {
        @set_time_limit(600);
        @ini_set('memory_limit', '512M');
        $formatList = implode(',', BlueprintGeneratorService::FORMATS);

        $data = $request->validate([
            'topic' => ['nullable', 'string', 'max:500'],
            'content_idea_id' => ['nullable', 'exists:content_ideas,id'],
            'trend_signal_id' => ['nullable', 'exists:trend_signals,id'],
            'planned_date' => ['nullable', 'date'],
            'planned_time' => ['nullable', 'date_format:H:i'],
            'format' => ['nullable', 'in:'.$formatList],
            'force' => ['sometimes', 'boolean'],
            'duration_seconds' => ['nullable', 'integer', 'min:5', 'max:3600'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:60'],
            'visual_beat_seconds' => ['nullable', 'integer', 'min:5', 'max:30'],
            'vo_beat_seconds' => ['nullable', 'integer', 'min:3', 'max:30'],
            'beat_seconds' => ['nullable', 'integer', 'min:3', 'max:30'],
            'prefer_stock' => ['sometimes', 'boolean'],
            'voice_perspective' => ['nullable', 'string', 'max:40'],
            'analytics_ai_analysis_id' => ['nullable', 'exists:analytics_ai_analyses,id'],
        ]);

        $user = $request->user();
        $planned = $data['planned_date'] ?? null;
        $format = $data['format'] ?? BlueprintGeneratorService::FORMAT_REELS_VO;
        $force = (bool) ($data['force'] ?? false);

        $analysis = null;
        if (! empty($data['analytics_ai_analysis_id'])) {
            $analysis = AnalyticsAiAnalysis::query()->findOrFail($data['analytics_ai_analysis_id']);
            abort_unless($analysis->user_id === $user->id, 403);
        }

        $options = array_filter([
            'duration_seconds' => $data['duration_seconds'] ?? null,
            'duration_minutes' => $data['duration_minutes'] ?? null,
            'visual_beat_seconds' => $data['visual_beat_seconds'] ?? $data['beat_seconds'] ?? null,
            'vo_beat_seconds' => $data['vo_beat_seconds'] ?? null,
            'beat_seconds' => $data['visual_beat_seconds'] ?? $data['beat_seconds'] ?? null,
            'prefer_stock' => array_key_exists('prefer_stock', $data) ? (bool) $data['prefer_stock'] : null,
            'voice_perspective' => $data['voice_perspective'] ?? null,
            'planned_time' => $data['planned_time'] ?? null,
            'force' => $force,
            'analytics_ai_analysis' => $analysis,
        ], fn ($v) => $v !== null);

        if (! empty($data['content_idea_id'])) {
            $idea = ContentIdea::query()->findOrFail($data['content_idea_id']);
            abort_unless($idea->user_id === $user->id, 403);
            $bp = $generator->generateFromIdea($user, $idea, $planned, $format, $force, $options);
        } elseif (! empty($data['trend_signal_id'])) {
            $signal = TrendSignal::query()->findOrFail($data['trend_signal_id']);
            $bp = $generator->generateFromSignal($user, $signal, $planned, $format, $force, $options);
        } else {
            abort_unless(filled($data['topic'] ?? null), 422, 'topic, content_idea_id, atau trend_signal_id wajib.');
            $bp = $generator->generateFromTopic($user, (string) $data['topic'], null, null, null, $planned, $format, $force, $options);
        }

        $bp->load(['brandKit', 'analyticsAiAnalysis']);

        $duplicate = $generator->wasDuplicate();
        $engine = $generator->lastEngine();
        $warning = $generator->lastWarning();

        if ($duplicate) {
            $label = 'Judul blueprint sudah ada — dibuka yang lama (tema bebas, judul tidak diduplikasi).';
        } else {
            $label = match ($format) {
                BlueprintGeneratorService::FORMAT_REELS_PANJANG => 'Blueprint Reels Panjang siap.',
                BlueprintGeneratorService::FORMAT_REELS_TEXT => 'Blueprint Reels Text Tempel siap.',
                BlueprintGeneratorService::FORMAT_FOTO => 'Blueprint Postingan Foto siap.',
                default => 'Blueprint Reels VO siap.',
            };
            $label .= $engine === 'gemini' ? ' (AI Gemini)' : ' (template)';
        }

        return response()->json([
            'message' => $label,
            'data' => $bp,
            'markdown' => $generator->toMarkdown($bp, $bp->brandKit ?? BrandKit::defaultFor($user)),
            'engine' => $engine,
            'duplicate' => $duplicate,
            'gemini_configured' => filled(config('blueprint.gemini_api_key')),
            'warning' => $warning,
        ], $duplicate ? 200 : 201);
    }

    public function update(Request $request, ContentBlueprint $blueprint): JsonResponse
    {
        abort_unless($blueprint->user_id === $request->user()->id, 403);

        $formatList = implode(',', BlueprintGeneratorService::FORMATS);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:180'],
            'cover_theme' => ['nullable', 'string', 'max:180'],
            'story_concept' => ['nullable', 'string'],
            'status' => ['nullable', 'in:draft,ready,scheduled,published'],
            'format' => ['nullable', 'in:'.$formatList],
            'scenes' => ['nullable', 'array'],
            'thumbnail_prompt' => ['nullable', 'string'],
            'caption' => ['nullable', 'string'],
            'title_options' => ['nullable', 'array'],
            'planned_date' => ['nullable', 'date'],
            'planned_time' => ['nullable', 'date_format:H:i'],
            'unschedule' => ['sometimes', 'boolean'],
        ]);

        if (! empty($data['unschedule'])) {
            $data['planned_date'] = null;
            $data['planned_time'] = null;
            $data['status'] = 'ready';
            unset($data['unschedule']);
        } elseif (array_key_exists('planned_date', $data)) {
            if ($data['planned_date']) {
                $data['status'] = $data['status'] ?? 'scheduled';
                if (array_key_exists('planned_time', $data) && $data['planned_time']) {
                    $data['planned_time'] = strlen($data['planned_time']) === 5
                        ? $data['planned_time'].':00'
                        : $data['planned_time'];
                }
            } else {
                $data['planned_time'] = null;
                if (empty($data['status'])) {
                    $data['status'] = 'ready';
                }
            }
            unset($data['unschedule']);
        } else {
            if (array_key_exists('planned_time', $data) && $data['planned_time'] && strlen((string) $data['planned_time']) === 5) {
                $data['planned_time'] = $data['planned_time'].':00';
            }
            unset($data['unschedule']);
        }

        $blueprint->update($data);

        return response()->json(['data' => $blueprint->fresh()]);
    }

    public function destroy(Request $request, ContentBlueprint $blueprint): JsonResponse
    {
        abort_unless($blueprint->user_id === $request->user()->id, 403);
        $blueprint->delete();

        return response()->json(['ok' => true]);
    }

    public function export(Request $request, ContentBlueprint $blueprint, BlueprintGeneratorService $generator): Response
    {
        abort_unless($blueprint->user_id === $request->user()->id, 403);

        $kit = $blueprint->brandKit ?? BrandKit::defaultFor($request->user());
        $md = $generator->toMarkdown($blueprint, $kit);
        $filename = 'blueprint-'.(Str::slug(Str::limit($blueprint->title, 60, '')) ?: 'reels').'.md';

        return response($md, 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
