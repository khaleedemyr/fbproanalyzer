<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TrendSignal;
use App\Services\Radar\AutoRadarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RadarController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $days = max(1, min(30, (int) $request->integer('days', 7)));

        $signals = TrendSignal::query()
            ->where('niche', config('radar.niche', 'psikologi'))
            ->where(function ($q) use ($days) {
                $q->where('observed_at', '>=', now()->subDays($days))
                    ->orWhereNull('observed_at');
            })
            ->orderByDesc('viral_score')
            ->limit(50)
            ->get();

        $bySource = TrendSignal::query()
            ->where('niche', config('radar.niche', 'psikologi'))
            ->where('observed_at', '>=', now()->subDays($days))
            ->selectRaw('source, COUNT(*) as total, AVG(viral_score) as avg_score')
            ->groupBy('source')
            ->get();

        return response()->json([
            'niche' => config('radar.niche', 'psikologi'),
            'mode' => 'auto',
            'signals' => $signals,
            'by_source' => $bySource,
            'youtube_configured' => filled(config('radar.youtube_api_key')),
            'last_synced_at' => optional($signals->max('observed_at')),
        ]);
    }

    public function sync(Request $request, AutoRadarService $radar): JsonResponse
    {
        $result = $radar->sync($request->user());

        return response()->json([
            'message' => 'Auto radar berhasil dijalankan.',
            'result' => $result,
        ]);
    }
}
