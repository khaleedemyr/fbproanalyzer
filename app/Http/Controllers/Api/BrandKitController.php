<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BrandKit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;

class BrandKitController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        BrandKit::defaultFor($user); // ensure at least one

        $kits = BrandKit::query()
            ->where('user_id', $user->id)
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $kits,
            'active' => BrandKit::activeFor($user),
        ]);
    }

    /** Alias: kit aktif (backward compatible). */
    public function show(Request $request): JsonResponse
    {
        $kit = BrandKit::activeFor($request->user());
        $key = trim((string) config('blueprint.gemini_api_key', ''), " \t\n\r\0\x0B\"'");

        return response()->json([
            'data' => $kit,
            'gemini_configured' => $key !== '',
            'gemini_model' => config('blueprint.gemini_model'),
            'gemini_key_hint' => $key !== '' ? (substr($key, 0, 6).'…'.substr($key, -4)) : null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $this->validatedKit($request, $user->id);

        $kit = BrandKit::query()->create(array_merge(
            BrandKit::defaultAttributes(),
            $data,
            [
                'user_id' => $user->id,
                'region' => BrandKit::normalizeRegion($data['region'] ?? 'ID'),
                'is_active' => false,
            ]
        ));

        if (! BrandKit::query()->where('user_id', $user->id)->where('is_active', true)->exists()) {
            $kit->activate();
        }

        return response()->json(['data' => $kit->fresh(), 'message' => 'Profil Brand Kit dibuat.'], 201);
    }

    public function showOne(Request $request, BrandKit $brandKit): JsonResponse
    {
        abort_unless($brandKit->user_id === $request->user()->id, 403);

        return response()->json(['data' => $brandKit]);
    }

    public function updateOne(Request $request, BrandKit $brandKit): JsonResponse
    {
        abort_unless($brandKit->user_id === $request->user()->id, 403);
        $data = $this->validatedKit($request, $request->user()->id, $brandKit->id);
        if (isset($data['region'])) {
            $data['region'] = BrandKit::normalizeRegion($data['region']);
        }
        $brandKit->update($data);

        return response()->json(['data' => $brandKit->fresh(), 'message' => 'Brand Kit disimpan.']);
    }

    /** Alias update kit aktif. */
    public function update(Request $request): JsonResponse
    {
        $kit = BrandKit::activeFor($request->user());
        $data = $this->validatedKit($request, $request->user()->id, $kit->id);
        if (isset($data['region'])) {
            $data['region'] = BrandKit::normalizeRegion($data['region']);
        }
        $kit->update($data);

        return response()->json(['data' => $kit->fresh()]);
    }

    public function activate(Request $request, BrandKit $brandKit): JsonResponse
    {
        abort_unless($brandKit->user_id === $request->user()->id, 403);
        $brandKit->activate();

        return response()->json([
            'data' => $brandKit->fresh(),
            'message' => 'Profil diaktifkan: '.$brandKit->name,
        ]);
    }

    public function destroy(Request $request, BrandKit $brandKit): JsonResponse
    {
        abort_unless($brandKit->user_id === $request->user()->id, 403);

        $count = BrandKit::query()->where('user_id', $request->user()->id)->count();
        if ($count <= 1) {
            return response()->json(['message' => 'Tidak bisa hapus — minimal satu Brand Kit.'], 422);
        }

        $wasActive = $brandKit->is_active;
        $brandKit->delete();

        if ($wasActive) {
            BrandKit::activeFor($request->user());
        }

        return response()->json([
            'message' => 'Brand Kit dihapus.',
            'active' => BrandKit::activeFor($request->user()),
        ]);
    }

    public function testGemini(Request $request): JsonResponse
    {
        $key = trim((string) config('blueprint.gemini_api_key', ''), " \t\n\r\0\x0B\"'");
        if ($key === '') {
            return response()->json([
                'ok' => false,
                'message' => 'GEMINI_API_KEY belum terbaca. Isi di .env lalu php artisan config:clear.',
            ], 422);
        }

        $models = array_values(array_unique(array_filter([
            (string) config('blueprint.gemini_model', 'gemini-3.8-flash'),
            ...((array) config('blueprint.gemini_model_fallbacks', [])),
        ])));

        $tried = [];
        foreach ($models as $model) {
            try {
                $response = Http::timeout(30)->post(
                    "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}",
                    [
                        'contents' => [[
                            'parts' => [['text' => 'Balas JSON: {"ok":true}']],
                        ]],
                        'generationConfig' => [
                            'temperature' => 0.1,
                            'responseMimeType' => 'application/json',
                        ],
                    ]
                );
            } catch (\Throwable $e) {
                $tried[] = ['model' => $model, 'error' => $e->getMessage()];

                continue;
            }

            if ($response->successful()) {
                return response()->json([
                    'ok' => true,
                    'message' => "Gemini OK via {$model}.",
                    'model' => $model,
                    'tried' => $tried,
                ]);
            }

            $tried[] = [
                'model' => $model,
                'error' => data_get($response->json(), 'error.message') ?: ('HTTP '.$response->status()),
            ];
        }

        return response()->json([
            'ok' => false,
            'message' => 'Semua model Gemini gagal. Cek key / model di .env.',
            'tried' => $tried,
        ], 422);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validatedKit(Request $request, int $userId, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:80',
                Rule::unique('brand_kits', 'name')->where(fn ($q) => $q->where('user_id', $userId))->ignore($ignoreId),
            ],
            'niche' => ['sometimes', 'string', 'max:80'],
            'character_name' => ['sometimes', 'string', 'max:80'],
            'character_visual' => ['nullable', 'string'],
            'visual_style' => ['nullable', 'string'],
            'aspect_ratio' => ['nullable', 'string', 'max:20'],
            'on_screen_font' => ['nullable', 'string', 'max:40'],
            'voice_perspective' => ['nullable', 'string', 'max:40'],
            'caption_hashtags' => ['nullable', 'string'],
            'image_tool' => ['nullable', 'string', 'max:60'],
            'video_tool' => ['nullable', 'string', 'max:60'],
            'region' => ['nullable', 'in:ID,US'],
        ]);
    }
}
