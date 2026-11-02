<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BrandKit;
use App\Services\Radar\NicheMarketScoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class NicheController extends Controller
{
    public function __construct(
        private NicheMarketScoreService $marketScores,
    ) {
    }

    /**
     * Katalog niche pasar + ranking berbasis data (Google Trends + YouTube).
     */
    public function index(Request $request): JsonResponse
    {
        $region = BrandKit::normalizeRegion($request->string('region')->toString() ?: 'ID');
        $catalog = config('niches.'.$region, config('niches.ID', []));
        $ranked = Cache::get($this->rankCacheKey($region));
        $rankedAt = is_array($ranked) ? ($ranked['ranked_at'] ?? Cache::get($this->rankCacheKey($region).'_at')) : null;
        $ranksMap = is_array($ranked) ? ($ranked['ranks'] ?? []) : [];

        $items = collect($catalog)->map(function (array $row) use ($ranksMap) {
            $slug = (string) ($row['slug'] ?? '');
            $rank = is_array($ranksMap) ? ($ranksMap[$slug] ?? null) : null;
            $hasRank = is_array($rank) && array_key_exists('score', $rank);

            return [
                'slug' => $slug,
                'label' => (string) ($row['label'] ?? $slug),
                'hashtags' => (string) ($row['hashtags'] ?? ''),
                'keywords' => array_values($row['keywords'] ?? []),
                'viral_score' => $hasRank ? round((float) $rank['score'], 1) : null,
                'score_source' => $hasRank ? (string) ($rank['score_source'] ?? 'data') : null,
                'competition' => $hasRank ? (string) ($rank['competition'] ?? '') : null,
                'fit_for_new_account' => $hasRank ? (string) ($rank['fit_for_new_account'] ?? '') : null,
                'why' => $hasRank
                    ? (string) ($rank['why'] ?? '')
                    : 'Belum di-rank. Klik “Cek niche (data)” — skor dari Google Trends + YouTube, bukan opini AI.',
                'metrics' => $hasRank ? ($rank['metrics'] ?? null) : null,
            ];
        });

        if (is_array($ranksMap) && $ranksMap !== []) {
            $items = $items->sortByDesc(fn ($i) => (float) ($i['viral_score'] ?? -1))->values();
        } else {
            $items = $items->values();
        }

        $top = $items->filter(fn ($i) => $i['viral_score'] !== null)->take(5)->values();

        return response()->json([
            'region' => $region,
            'purpose' => 'new_account_niche',
            'data' => $items,
            'ranked_at' => $rankedAt,
            'source' => is_array($ranked) ? ($ranked['source'] ?? null) : null,
            'warnings' => is_array($ranked) ? ($ranked['warnings'] ?? []) : [],
            'top' => $top,
            'message' => is_array($ranksMap) && $ranksMap !== []
                ? 'Ranking niche berbasis Google Trends + YouTube (cache 12 jam).'
                : 'Belum ada ranking data. Jalankan cek niche (data).',
        ]);
    }

    /**
     * Hitung ulang ranking niche dari data Google Trends + YouTube.
     */
    public function refreshRank(Request $request): JsonResponse
    {
        $region = BrandKit::normalizeRegion($request->input('region', 'ID'));
        $catalog = config('niches.'.$region, []);
        if ($catalog === []) {
            return response()->json(['message' => 'Katalog niche kosong.'], 422);
        }

        try {
            $result = $this->marketScores->rank($region, force: true);

            $map = [];
            foreach ($result['ranks'] as $slug => $row) {
                if (! is_array($row)) {
                    continue;
                }
                $map[(string) $slug] = [
                    'score' => (float) ($row['score'] ?? 0),
                    'why' => (string) ($row['why'] ?? ''),
                    'competition' => (string) ($row['competition'] ?? 'mid'),
                    'fit_for_new_account' => (string) ($row['fit_for_new_account'] ?? ''),
                    'metrics' => $row['metrics'] ?? null,
                    'score_source' => 'data',
                ];
            }

            $payload = [
                'ranks' => $map,
                'ranked_at' => $result['ranked_at'],
                'source' => $result['source'],
                'warnings' => $result['warnings'],
            ];

            Cache::put($this->rankCacheKey($region), $payload, now()->addHours(12));
            Cache::put($this->rankCacheKey($region).'_at', $result['ranked_at'], now()->addHours(12));

            $response = $this->index($request->merge(['region' => $region]));
            $json = $response->getData(true);
            $json['source'] = $result['source'];
            $json['warnings'] = $result['warnings'];

            return response()->json($json);
        } catch (\Throwable $e) {
            Log::warning('niche.rank_fail', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Gagal hitung ranking data: '.$e->getMessage(),
            ], 500);
        }
    }

    protected function rankCacheKey(string $region): string
    {
        return 'niche_new_account_rank_'.$region;
    }
}
