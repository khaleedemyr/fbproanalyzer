<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\YoutubeAccount;
use App\Services\YouTube\YouTubeAnalyticsService;
use App\Services\YouTube\YouTubeOAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class YouTubeAuthController extends Controller
{
    public function __construct(
        private YouTubeOAuthService $oauth,
        private YouTubeAnalyticsService $analytics,
    ) {
    }

    public function status(Request $request): JsonResponse
    {
        $account = YoutubeAccount::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->first();

        return response()->json([
            'configured' => $this->oauth->configured(),
            'connected' => (bool) $account,
            'data' => $account ? [
                'id' => $account->id,
                'channel_id' => $account->channel_id,
                'channel_title' => $account->channel_title,
                'email' => $account->email,
                'picture_url' => $account->picture_url,
                'last_synced_at' => optional($account->last_synced_at)?->toDateTimeString(),
                'token_expires_at' => optional($account->token_expires_at)?->toDateTimeString(),
            ] : null,
            'redirect_uri' => config('youtube.redirect_uri'),
        ]);
    }

    public function redirect(Request $request): JsonResponse
    {
        if (! $this->oauth->configured()) {
            return response()->json([
                'message' => 'YOUTUBE_CLIENT_ID / YOUTUBE_CLIENT_SECRET belum diisi di .env.',
            ], 422);
        }

        $state = Str::random(40);
        $request->session()->put('youtube_oauth_state', $state);

        return response()->json([
            'url' => $this->oauth->authUrl($state),
            'redirect_uri' => config('youtube.redirect_uri'),
        ]);
    }

    public function callback(Request $request): RedirectResponse
    {
        $base = rtrim((string) config('app.url'), '/').'/analytics';

        if ($request->string('error')->isNotEmpty()) {
            return redirect($base.'?youtube=error');
        }

        $state = $request->string('state')->toString();
        if (! hash_equals((string) $request->session()->pull('youtube_oauth_state'), $state)) {
            return redirect($base.'?youtube=invalid_state');
        }

        $user = $request->user();
        if (! $user) {
            return redirect(rtrim((string) config('app.url'), '/').'/login?youtube=login_required');
        }

        try {
            $tokenPayload = $this->oauth->exchangeCode($request->string('code')->toString());
            $this->oauth->connectFromTokenPayload($user, $tokenPayload);
            $this->analytics->syncUser($user);
        } catch (\Throwable $e) {
            return redirect($base.'?youtube=token_failed&msg='.urlencode(Str::limit($e->getMessage(), 120, '')));
        }

        return redirect($base.'?youtube=connected');
    }

    public function disconnect(Request $request): JsonResponse
    {
        YoutubeAccount::query()->where('user_id', $request->user()->id)->delete();

        return response()->json(['ok' => true, 'message' => 'YouTube OAuth diputus.']);
    }
}
