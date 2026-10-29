<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BrandKit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrandKitController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $kit = BrandKit::defaultFor($request->user());

        return response()->json([
            'data' => $kit,
            'gemini_configured' => filled(config('blueprint.gemini_api_key')),
        ]);
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
