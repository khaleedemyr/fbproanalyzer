<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BrandKit;
use App\Models\TrendSignal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NicheController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $region = BrandKit::normalizeRegion($request->string('region')->toString() ?: 'ID');
        $catalog = config('niches.'.$region, config('niches.ID', []));

        $ranked = Cache::get($this->rankCacheKey($region));
        $heat = $this->radarHeatByNiche($region, $catalog);

        $items = collect($catalog)->map(function (array $row) use ($ranked, $heat) {
            $slug = (string) ($row['slug'] ?? '');
            $rank = is_array($ranked) ? ($ranked[$slug] ?? null) : null;
            $radar = $heat[$slug] ?? ['signals' => 0, 'avg_score' => 0.0, 'heat' => 0.0];
            $geminiScore = is_array($rank) ? ($rank['score'] ?? null) : null;
            // Tampilkan skor viral: Gemini dulu, fallback heat dari sinyal Radar.
            $viralScore = $geminiScore !== null ? (float) $geminiScore : (float) $radar['heat'];

            return [
                'slug' => $slug,
                'label' => (string) ($row['label'] ?? $slug),
                'hashtags' => (string) ($row['hashtags'] ?? ''),
                'keywords' => array_values($row['keywords'] ?? []),
                'viral_score' => round($viralScore, 1),
                'score_source' => $geminiScore !== null ? 'gemini' : ($radar['signals'] > 0 ? 'radar' : 'catalog'),
                'radar_signals' => (int) $radar['signals'],
                'why' => is_array($rank) && ! empty($rank['why'])
                    ? (string) $rank['why']
                    : ($radar['signals'] > 0
                        ? 'Ada '.$radar['signals'].' sinyal Radar · avg skor '.round((float) $radar['avg_score'], 1)
                        : 'Belum ada sinyal Radar — jalankan Auto Radar atau Refresh ranking Gemini'),
            ];
        })->sortByDesc(fn ($i) => (float) ($i['viral_score'] ?? 0))->values();

        return response()->json([
            'region' => $region,
            'data' => $items,
            'ranked_at' => Cache::get($this->rankCacheKey($region).'_at'),
            'top' => $items->take(5)->values(),
        ]);
    }

    /**
     * Heat niche dari TrendSignal di region (match keyword / niche slug).
     *
     * @param  array<int, array<string, mixed>>  $catalog
     * @return array<string, array{signals:int, avg_score:float, heat:float}>
     */
    protected function radarHeatByNiche(string $region, array $catalog): array
    {
        $signals = TrendSignal::query()
            ->where('region', $region)
            ->where('observed_at', '>=', now()->subDays(14))
            ->orderByDesc('observed_at')
            ->limit(500)
            ->get(['niche', 'keyword', 'title', 'viral_score']);

        $out = [];
        foreach ($catalog as $row) {
            $slug = (string) ($row['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $needles = array_map('mb_strtolower', array_merge(
                [$slug, (string) ($row['label'] ?? '')],
                array_values($row['keywords'] ?? [])
            ));
            $needles = array_values(array_filter(array_unique($needles)));

            $matched = $signals->filter(function ($s) use ($slug, $needles) {
                $hay = mb_strtolower(trim(implode(' ', [
                    (string) $s->niche,
                    (string) $s->keyword,
                    (string) $s->title,
                ])));
                if ($hay === '') {
                    return false;
                }
                if ($s->niche && mb_strtolower((string) $s->niche) === $slug) {
                    return true;
                }
                foreach ($needles as $n) {
                    if ($n !== '' && str_contains($hay, $n)) {
                        return true;
                    }
                }

                return false;
            });

            $count = $matched->count();
            $avg = $count > 0 ? (float) $matched->avg('viral_score') : 0.0;
            // Heat 0–100: volume + kualitas skor sinyal.
            $heat = $count === 0 ? 0.0 : min(100, round(($avg * 0.65) + min(35, $count * 4), 1));

            $out[$slug] = [
                'signals' => $count,
                'avg_score' => $avg,
                'heat' => $heat,
            ];
        }

        return $out;
    }

    /**
     * Refresh ranking singkat via Gemini (cache 24 jam).
     */
    public function refreshRank(Request $request): JsonResponse
    {
        $region = BrandKit::normalizeRegion($request->input('region', 'ID'));
        $catalog = config('niches.'.$region, []);
        if ($catalog === []) {
            return response()->json(['message' => 'Katalog niche kosong.'], 422);
        }

        $key = trim((string) config('blueprint.gemini_api_key', ''), " \t\n\r\0\x0B\"'");
        if ($key === '') {
            return response()->json(['message' => 'GEMINI_API_KEY belum diset.'], 422);
        }

        $labels = collect($catalog)->map(fn ($r) => ($r['slug'] ?? '').': '.($r['label'] ?? ''))->implode("\n");
        $geo = $region === 'US' ? 'United States' : 'Indonesia';
        $model = (string) config('blueprint.gemini_model', 'gemini-3.8-flash');
        $prompt = <<<PROMPT
Kamu strategist konten short-form untuk pasar {$geo}.
Berikut daftar niche:
{$labels}

Skorkan potensi viral hari ini (0-100) untuk tiap slug. JSON saja:
{"ranks":[{"slug":"...","score":80,"why":"1 kalimat"}]}
PROMPT;

        try {
            $response = Http::timeout(40)->post(
                "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}",
                [
                    'contents' => [['parts' => [['text' => $prompt]]]],
                    'generationConfig' => [
                        'temperature' => 0.4,
                        'responseMimeType' => 'application/json',
                        'maxOutputTokens' => 2048,
                    ],
                ]
            );
            if (! $response->successful()) {
                return response()->json([
                    'message' => 'Gemini gagal: '.(data_get($response->json(), 'error.message') ?: 'HTTP '.$response->status()),
                ], 422);
            }

            $text = '';
            foreach ((array) data_get($response->json(), 'candidates.0.content.parts', []) as $part) {
                if (is_array($part) && isset($part['text'])) {
                    $text .= (string) $part['text'];
                }
            }
            $decoded = json_decode($text, true);
            if (! is_array($decoded)) {
                $decoded = json_decode(preg_replace('/^[\s\S]*?(\{[\s\S]*\})[\s\S]*$/', '$1', $text) ?? '', true);
            }
            $ranks = $decoded['ranks'] ?? null;
            if (! is_array($ranks)) {
                return response()->json(['message' => 'Respons ranking tidak valid.'], 422);
            }

            $map = [];
            foreach ($ranks as $row) {
                if (! is_array($row) || empty($row['slug'])) {
                    continue;
                }
                $map[(string) $row['slug']] = [
                    'score' => (float) ($row['score'] ?? 0),
                    'why' => (string) ($row['why'] ?? ''),
                ];
            }

            Cache::put($this->rankCacheKey($region), $map, now()->addDay());
            Cache::put($this->rankCacheKey($region).'_at', now()->toDateTimeString(), now()->addDay());

            return $this->index($request->merge(['region' => $region]));
        } catch (\Throwable $e) {
            Log::warning('niche.rank_fail', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Gagal refresh ranking: '.$e->getMessage()], 500);
        }
    }

    protected function rankCacheKey(string $region): string
    {
        return 'niche_rank_'.$region;
    }
}
