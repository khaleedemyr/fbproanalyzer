<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BrandKit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class BrandKitController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $kit = BrandKit::defaultFor($request->user());
        $key = trim((string) config('blueprint.gemini_api_key', ''), " \t\n\r\0\x0B\"'");

        return response()->json([
            'data' => $kit,
            'gemini_configured' => $key !== '',
            'gemini_model' => config('blueprint.gemini_model'),
            'gemini_key_hint' => $key !== '' ? (substr($key, 0, 6).'…'.substr($key, -4)) : null,
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

    public function update(Request $request): JsonResponse
    {
        $kit = BrandKit::defaultFor($request->user());

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
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
        ]);

        $kit->update($data);

        return response()->json(['data' => $kit->fresh()]);
    }
}
