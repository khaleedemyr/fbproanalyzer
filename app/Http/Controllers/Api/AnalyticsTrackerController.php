<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
        $result = $tracker->syncAll($request->user());

        return response()->json([
            'message' => 'Analytics sync selesai.',
            'result' => $result,
            'data' => $tracker->dashboard($request->user(), (int) $request->integer('days', 30)),
        ]);
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
