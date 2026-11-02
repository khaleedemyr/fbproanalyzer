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
        $model = (string) config('blueprint.gemini_model', 'gemini-3.8-flash');
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

        try {
            $response = Http::timeout(50)->post(
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

            return $this->index($request->merge(['region' => $region]));
        } catch (\Throwable $e) {
            Log::warning('niche.rank_fail', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Gagal refresh ranking: '.$e->getMessage()], 500);
        }
    }

    protected function rankCacheKey(string $region): string
    {
        return 'niche_new_account_rank_'.$region;
    }
}
