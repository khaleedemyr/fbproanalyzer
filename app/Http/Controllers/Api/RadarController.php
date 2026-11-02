<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BrandKit;
use App\Models\TrendSignal;
use App\Services\Radar\AutoRadarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RadarController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $days = max(1, min(30, (int) $request->integer('days', 7)));
        $kit = BrandKit::activeFor($request->user());
        $region = BrandKit::normalizeRegion($request->input('region', $kit->region ?: 'ID'));
        $niche = trim((string) $request->input('niche', $kit->niche ?: config('radar.niche', 'psikologi')))
            ?: config('radar.niche', 'psikologi');

        $base = TrendSignal::query()
            ->where('niche', $niche)
            ->where('region', $region);

        $signals = (clone $base)
            ->where(function ($q) use ($days) {
                $q->where('observed_at', '>=', now()->subDays($days))
                    ->orWhereNull('observed_at');
            })
            ->orderByDesc('viral_score')
            ->limit(50)
            ->get();

        $bySource = (clone $base)
            ->where('observed_at', '>=', now()->subDays($days))
            ->selectRaw('source, COUNT(*) as total, AVG(viral_score) as avg_score')
            ->groupBy('source')
            ->get();

        return response()->json([
            'niche' => $niche,
            'region' => $region,
            'mode' => 'auto',
            'signals' => $signals,
            'by_source' => $bySource,
            'youtube_configured' => filled(config('radar.youtube_api_key')),
            'last_synced_at' => optional($signals->max('observed_at')),
            'active_brand_kit' => [
                'id' => $kit->id,
                'name' => $kit->name,
                'niche' => $kit->niche,
                'region' => $kit->region,
            ],
        ]);
    }

    public function sync(Request $request, AutoRadarService $radar): JsonResponse
    {
        $kit = BrandKit::activeFor($request->user());
        $data = $request->validate([
            'region' => ['nullable', 'in:ID,US'],
            'niche' => ['nullable', 'string', 'max:80'],
        ]);

        $region = BrandKit::normalizeRegion($data['region'] ?? $kit->region ?: 'ID');
        $niche = trim((string) ($data['niche'] ?? $kit->niche ?: config('radar.niche', 'psikologi')))
            ?: 'psikologi';
        $keywords = $radar->keywordsForNiche($niche, $region);

        $result = $radar->sync($request->user(), [
            'region' => $region,
            'niche' => $niche,
            'keywords' => $keywords,
        ]);

        return response()->json([
            'message' => 'Auto radar berhasil dijalankan ('.$region.' · '.$niche.').',
            'result' => $result,
        ]);
    }
}
