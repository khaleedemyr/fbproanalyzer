<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MetaAccount;
use App\Services\Analytics\AnalyticsTrackerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MetaAccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $accounts = $request->user()
            ->metaAccounts()
            ->withCount('posts')
            ->orderByDesc('is_primary')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $accounts]);
    }

    public function update(Request $request, MetaAccount $metaAccount): JsonResponse
    {
        abort_unless($metaAccount->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'niche' => ['sometimes', 'string', 'max:80'],
            'is_primary' => ['sometimes', 'boolean'],
        ]);

        if (($data['is_primary'] ?? false) === true) {
            $request->user()->metaAccounts()->update(['is_primary' => false]);
        }

        $metaAccount->update($data);

        return response()->json(['data' => $metaAccount->fresh()]);
    }

    public function sync(Request $request, MetaAccount $metaAccount, AnalyticsTrackerService $tracker): JsonResponse
    {
        abort_unless($metaAccount->user_id === $request->user()->id, 403);

        if ($metaAccount->type !== 'page' || blank($metaAccount->access_token)) {
            return response()->json([
                'message' => 'Sync Insights saat ini tersedia untuk Page yang punya token. Profile Personal Pro menyusul setelah permission siap.',
            ], 422);
        }

        try {
            $synced = $tracker->syncMetaAccount($request->user(), $metaAccount);
            $tracker->snapshotToday($request->user());
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'synced' => $synced,
            'account' => $metaAccount->fresh()->loadCount('posts'),
        ]);
    }
}
