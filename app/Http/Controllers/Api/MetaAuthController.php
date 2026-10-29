<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MetaAccount;
use App\Services\MetaGraphService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MetaAuthController extends Controller
{
    public function __construct(private MetaGraphService $meta)
    {
    }

    public function redirect(Request $request): JsonResponse
    {
        if (blank(config('meta.app_id')) || blank(config('meta.app_secret'))) {
            return response()->json([
                'message' => 'META_APP_ID / META_APP_SECRET belum diisi di .env server.',
            ], 422);
        }

        $state = Str::random(40);
        $request->session()->put('meta_oauth_state', $state);

        return response()->json([
            'url' => $this->meta->authUrl($state),
            'redirect_uri' => config('meta.redirect_uri'),
        ]);
    }

    public function callback(Request $request): RedirectResponse
    {
        $frontend = rtrim(config('app.url'), '/').'/accounts?meta=connected';

        if ($request->string('error')->isNotEmpty()) {
            return redirect(rtrim(config('app.url'), '/').'/accounts?meta=error');
        }

        $state = $request->string('state')->toString();
        if (! hash_equals((string) $request->session()->pull('meta_oauth_state'), $state)) {
            return redirect(rtrim(config('app.url'), '/').'/accounts?meta=invalid_state');
        }

        $user = $request->user();
        if (! $user) {
            return redirect(rtrim(config('app.url'), '/').'/login?meta=login_required');
        }

        $tokenPayload = $this->meta->exchangeCode($request->string('code')->toString());
        $shortToken = $tokenPayload['access_token'] ?? null;

        if (! $shortToken) {
            return redirect(rtrim(config('app.url'), '/').'/accounts?meta=token_failed');
        }

        $longLived = $this->meta->exchangeLongLivedToken($shortToken);
        $userToken = $longLived['access_token'] ?? $shortToken;
        $expiresIn = (int) ($longLived['expires_in'] ?? 0);

        $profile = $this->meta->me($userToken);
        $pages = $this->meta->pages($userToken);

        DB::transaction(function () use ($user, $profile, $pages, $userToken, $expiresIn) {
            $user->forceFill([
                'meta_user_id' => $profile['id'] ?? $user->meta_user_id,
                'avatar' => $profile['picture']['data']['url'] ?? $user->avatar,
            ])->save();

            // Store user token as a profile account row for Personal Pro sync later.
            MetaAccount::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'meta_id' => (string) ($profile['id'] ?? 'profile'),
                ],
                [
                    'name' => $profile['name'] ?? $user->name,
                    'type' => 'profile',
                    'niche' => 'psikologi',
                    'picture_url' => $profile['picture']['data']['url'] ?? null,
                    'access_token' => $userToken,
                    'token_expires_at' => $expiresIn > 0 ? now()->addSeconds($expiresIn) : null,
                    'is_primary' => true,
                ]
            );

            foreach ($pages as $page) {
                MetaAccount::query()->updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'meta_id' => (string) $page['id'],
                    ],
                    [
                        'name' => $page['name'] ?? 'Untitled Page',
                        'username' => $page['username'] ?? null,
                        'type' => 'page',
                        'niche' => 'psikologi',
                        'category' => $page['category'] ?? null,
                        'picture_url' => $page['picture']['data']['url'] ?? null,
                        'access_token' => $page['access_token'] ?? null,
                        'token_expires_at' => null,
                        'is_primary' => false,
                    ]
                );
            }
        });

        return redirect($frontend);
    }
}
