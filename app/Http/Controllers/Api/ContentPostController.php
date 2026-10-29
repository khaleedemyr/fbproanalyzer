<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContentPost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContentPostController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ContentPost::query()
            ->where('user_id', $request->user()->id)
            ->with('metaAccount:id,name,type,niche');

        if ($request->filled('account_id')) {
            $query->where('meta_account_id', $request->integer('account_id'));
        }

        if ($request->filled('format')) {
            $query->where('format', $request->string('format'));
        }

        if ($request->filled('days')) {
            $query->where('published_at', '>=', now()->subDays((int) $request->integer('days')));
        }

        $posts = $query
            ->orderByDesc($request->string('sort', 'viral_score')->toString())
            ->paginate(20);

        return response()->json($posts);
    }
}
