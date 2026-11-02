<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BrandKit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NicheController extends Controller
{
    /**
     * Katalog niche pasar + ranking "layak untuk akun baru" (bukan niche yang sudah diisi user).
     */
    public function index(Request $request): JsonResponse
    {
        $region = BrandKit::normalizeRegion($request->string('region')->toString() ?: 'ID');
        $catalog = config('niches.'.$region, config('niches.ID', []));
        $ranked = Cache::get($this->rankCacheKey($region));

        $items = collect($catalog)->map(function (array $row) use ($ranked) {
            $slug = (string) ($row['slug'] ?? '');
            $rank = is_array($ranked) ? ($ranked[$slug] ?? null) : null;
            $hasRank = is_array($rank) && array_key_exists('score', $rank);

            return [
                'slug' => $slug,
                'label' => (string) ($row['label'] ?? $slug),
                'hashtags' => (string) ($row['hashtags'] ?? ''),
                'keywords' => array_values($row['keywords'] ?? []),
                'viral_score' => $hasRank ? round((float) $rank['score'], 1) : null,
                'score_source' => $hasRank ? 'gemini' : null,
                'competition' => $hasRank ? (string) ($rank['competition'] ?? '') : null,
                'fit_for_new_account' => $hasRank ? (string) ($rank['fit_for_new_account'] ?? '') : null,
                'why' => $hasRank
                    ? (string) ($rank['why'] ?? '')
                    : 'Klik “Cek niche untuk akun baru” agar AI ranking niche pasar (psikologi, komedi, kuliner, …).',
            ];
        });

        if (is_array($ranked) && $ranked !== []) {
            $items = $items->sortByDesc(fn ($i) => (float) ($i['viral_score'] ?? -1))->values();
        } else {
            $items = $items->values();
        }

        $top = $items->filter(fn ($i) => $i['viral_score'] !== null)->take(5)->values();

        return response()->json([
            'region' => $region,
            'purpose' => 'new_account_niche',
            'data' => $items,
            'ranked_at' => Cache::get($this->rankCacheKey($region).'_at'),
            'top' => $top,
            'message' => is_array($ranked) && $ranked !== []
                ? 'Ranking niche pasar untuk akun baru (cache 24 jam).'
                : 'Belum ada ranking. Jalankan cek niche untuk akun baru.',
        ]);
    }

    /**
     * Ranking niche pasar via Gemini: mana yang layak dipilih untuk AKUN BARU.
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
        $lang = $region === 'US' ? 'English' : 'Bahasa Indonesia';
        $prompt = <<<PROMPT
Kamu strategist growth short-form (Reels / TikTok / Shorts) untuk pasar {$geo}.
Tugas: bantu creator memilih NICHE untuk AKUN BARU (bukan topik video harian).

Kandidat niche pasar:
{$labels}

Skorkan tiap slug 0–100 seberapa LAYAK dipilih sebagai niche akun baru minggu ini.
Pertimbangkan: demand/viralitas saat ini, kompetisi creator, kemudahan konsisten upload, potensi monetisasi/brand deal, saturasi.

Jawab JSON saja (field why & fit_for_new_account dalam {$lang}):
{"ranks":[{"slug":"...","score":80,"competition":"low|mid|high","why":"1 kalimat kenapa niche ini hot/layak","fit_for_new_account":"1 kalimat saran sudut untuk akun baru"}]}
PROMPT;

        // Model di .env server sering masih gemini-1.5-flash (sudah hang/timeout).
        // Coba primary + fallbacks; model 1.5 dipindah ke belakang.
        $models = array_values(array_unique(array_filter([
            (string) config('blueprint.gemini_model', 'gemini-3.8-flash'),
            ...((array) config('blueprint.gemini_model_fallbacks', [])),
            'gemini-2.5-flash',
            'gemini-flash-latest',
        ])));
        usort($models, function (string $a, string $b): int {
            $score = static fn (string $m): int => str_contains($m, '1.5') || str_contains($m, '1.0') ? 1 : 0;

            return $score($a) <=> $score($b);
        });

        $tried = [];
        $text = '';
        $usedModel = null;

        foreach ($models as $model) {
            try {
                $response = Http::timeout(28)->post(
                    "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}",
                    [
                        'contents' => [['parts' => [['text' => $prompt]]]],
                        'generationConfig' => [
                            'temperature' => 0.35,
                            'responseMimeType' => 'application/json',
                            'maxOutputTokens' => 4096,
                        ],
                    ]
                );
            } catch (\Throwable $e) {
                $tried[] = $model.': '.$e->getMessage();
                Log::warning('niche.rank_model_fail', ['model' => $model, 'error' => $e->getMessage()]);

                continue;
            }

            if (! $response->successful()) {
                $tried[] = $model.': '.(data_get($response->json(), 'error.message') ?: 'HTTP '.$response->status());

                continue;
            }

            $chunk = '';
            foreach ((array) data_get($response->json(), 'candidates.0.content.parts', []) as $part) {
                if (is_array($part) && isset($part['text'])) {
                    $chunk .= (string) $part['text'];
                }
            }
            if ($chunk === '') {
                $tried[] = $model.': empty response';

                continue;
            }

            $text = $chunk;
            $usedModel = $model;
            break;
        }

        if ($text === '' || $usedModel === null) {
            return response()->json([
                'message' => 'Gemini timeout/gagal. Update .env: GEMINI_MODEL=gemini-2.5-flash (bukan 1.5), lalu config:clear. Tried: '.implode(' | ', array_slice($tried, 0, 3)),
            ], 422);
        }

        try {
            $decoded = json_decode($text, true);
            if (! is_array($decoded)) {
                $decoded = json_decode(preg_replace('/^[\s\S]*?(\{[\s\S]*\})[\s\S]*$/', '$1', $text) ?? '', true);
            }
            $ranks = $decoded['ranks'] ?? null;
            if (! is_array($ranks)) {
                return response()->json(['message' => 'Respons ranking tidak valid dari '.$usedModel.'.'], 422);
            }

            $map = [];
            foreach ($ranks as $row) {
                if (! is_array($row) || empty($row['slug'])) {
                    continue;
                }
                $comp = strtolower(trim((string) ($row['competition'] ?? 'mid')));
                if (! in_array($comp, ['low', 'mid', 'high'], true)) {
                    $comp = 'mid';
                }
                $map[(string) $row['slug']] = [
                    'score' => (float) ($row['score'] ?? 0),
                    'why' => (string) ($row['why'] ?? ''),
                    'competition' => $comp,
                    'fit_for_new_account' => (string) ($row['fit_for_new_account'] ?? ''),
                ];
            }

            Cache::put($this->rankCacheKey($region), $map, now()->addDay());
            Cache::put($this->rankCacheKey($region).'_at', now()->toDateTimeString(), now()->addDay());

            $payload = $this->index($request->merge(['region' => $region]));
            $json = $payload->getData(true);
            $json['model'] = $usedModel;

            return response()->json($json);
        } catch (\Throwable $e) {
            Log::warning('niche.rank_fail', ['error' => $e->getMessage(), 'model' => $usedModel]);

            return response()->json(['message' => 'Gagal refresh ranking: '.$e->getMessage()], 500);
        }
    }

    protected function rankCacheKey(string $region): string
    {
        return 'niche_new_account_rank_'.$region;
    }
}
