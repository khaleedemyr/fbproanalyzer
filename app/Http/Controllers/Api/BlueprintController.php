<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BrandKit;
use App\Models\ContentBlueprint;
use App\Models\ContentIdea;
use App\Models\TrendSignal;
use App\Services\Blueprint\BlueprintGeneratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class BlueprintController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ContentBlueprint::query()
            ->where('user_id', $request->user()->id)
            ->with(['brandKit:id,name,character_name'])
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
            $query->reorder()->orderBy('planned_date');
        }

        return response()->json(['data' => $query->limit(100)->get()]);
    }

    public function show(Request $request, ContentBlueprint $blueprint): JsonResponse
    {
        abort_unless($blueprint->user_id === $request->user()->id, 403);

        $blueprint->load(['brandKit', 'contentIdea', 'trendSignal']);

        return response()->json([
            'data' => $blueprint,
            'markdown' => app(BlueprintGeneratorService::class)->toMarkdown(
                $blueprint,
                $blueprint->brandKit ?? BrandKit::defaultFor($request->user())
            ),
        ]);
    }

    public function generate(Request $request, BlueprintGeneratorService $generator): JsonResponse
    {
        $data = $request->validate([
            'topic' => ['nullable', 'string', 'max:500'],
            'content_idea_id' => ['nullable', 'exists:content_ideas,id'],
            'trend_signal_id' => ['nullable', 'exists:trend_signals,id'],
            'planned_date' => ['nullable', 'date'],
        ]);

        $user = $request->user();
        $planned = $data['planned_date'] ?? null;

        if (! empty($data['content_idea_id'])) {
            $idea = ContentIdea::query()->findOrFail($data['content_idea_id']);
            abort_unless($idea->user_id === $user->id, 403);
            $bp = $generator->generateFromIdea($user, $idea, $planned);
        } elseif (! empty($data['trend_signal_id'])) {
            $signal = TrendSignal::query()->findOrFail($data['trend_signal_id']);
            $bp = $generator->generateFromSignal($user, $signal, $planned);
        } else {
            abort_unless(filled($data['topic'] ?? null), 422, 'topic, content_idea_id, atau trend_signal_id wajib.');
            $bp = $generator->generateFromTopic($user, (string) $data['topic'], null, null, null, $planned);
        }

        $bp->load('brandKit');

        return response()->json([
            'message' => 'Blueprint cinematic siap.',
            'data' => $bp,
            'markdown' => $generator->toMarkdown($bp, $bp->brandKit ?? BrandKit::defaultFor($user)),
            'engine' => filled(config('blueprint.gemini_api_key')) ? 'gemini' : 'template',
        ], 201);
    }

    public function update(Request $request, ContentBlueprint $blueprint): JsonResponse
    {
        abort_unless($blueprint->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:180'],
            'cover_theme' => ['nullable', 'string', 'max:180'],
            'story_concept' => ['nullable', 'string'],
            'status' => ['nullable', 'in:draft,ready,scheduled,published'],
            'scenes' => ['nullable', 'array'],
            'thumbnail_prompt' => ['nullable', 'string'],
            'caption' => ['nullable', 'string'],
            'planned_date' => ['nullable', 'date'],
        ]);

        if (array_key_exists('planned_date', $data) && $data['planned_date'] && empty($data['status'])) {
            $data['status'] = 'scheduled';
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
