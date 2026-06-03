<?php

namespace App\Http\Controllers\Auth;

use App\Enums\ConfigKey;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\OauthIdentity;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use App\Services\CasdoorOidcService;
use App\Utils;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class CasdoorController extends Controller
{
    public function redirect(CasdoorOidcService $oidc): RedirectResponse
    {
        try {
            return redirect()->away($oidc->getAuthorizationUrl());
        } catch (Throwable $e) {
            Utils::e($e, '发起 Casdoor 登录时发生异常');
            return redirect()->route('login')->with('status', $e->getMessage());
        }
    }

    public function callback(Request $request, CasdoorOidcService $oidc): RedirectResponse|View
    {
        try {
            if ($request->has('error')) {
                throw new \RuntimeException($request->query('error_description') ?: $request->query('error'));
            }

            $request->validate([
                'code' => ['required', 'string'],
                'state' => ['required', 'string'],
            ]);

            $identity = $oidc->getUserFromCallback($request->query('code'), $request->query('state'));

            /** @var OauthIdentity|null $existing */
            $existing = OauthIdentity::query()
                ->with('user')
                ->where('provider', OauthIdentity::PROVIDER_CASDOOR)
                ->where('provider_user_id', $identity['provider_user_id'])
                ->first();

            if ($existing) {
                Auth::login($existing->user);
                $request->session()->regenerate();

                return redirect()->intended(RouteServiceProvider::HOME);
            }

            $oidc->putPendingIdentity($identity);

            return redirect()->route('casdoor.confirm');
        } catch (Throwable $e) {
            Utils::e($e, 'Casdoor 回调处理时发生异常');
            return redirect()->route('login')->with('status', $e->getMessage());
        }
    }

    public function confirm(CasdoorOidcService $oidc): RedirectResponse|View
    {
        try {
            $identity = $oidc->getPendingIdentity();

            return view('auth.casdoor-callback', [
                'identity' => $identity,
                'canCreate' => $oidc->canCreateUser($identity),
                'registrationEnabled' => Utils::config(ConfigKey::IsEnableRegistration),
            ]);
        } catch (Throwable $e) {
            return redirect()->route('login')->with('status', $e->getMessage());
        }
    }

    public function create(Request $request, CasdoorOidcService $oidc): RedirectResponse
    {
        $identity = $this->pendingIdentity($oidc);

        if (! Utils::config(ConfigKey::IsEnableRegistration)) {
            throw ValidationException::withMessages([
                'oauth' => '站点管理员关闭了注册功能，请绑定已有账号。',
            ]);
        }

        if (! $oidc->canCreateUser($identity)) {
            throw ValidationException::withMessages([
                'oauth' => 'Casdoor 未返回已验证邮箱，不能直接创建账号，请绑定已有账号。',
            ]);
        }

        $request->merge([
            'name' => $request->input('name') ?: ($identity['name'] ?? Str::before($identity['email'], '@')),
            'email' => $identity['email'],
        ]);

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
        ]);

        $user = DB::transaction(function () use ($request, $identity, $oidc) {
            $user = User::create([
                'name' => $request->input('name'),
                'email' => $identity['email'],
                'password' => Hash::make(Str::random(32)),
                'registered_ip' => $request->ip(),
                'status' => UserStatus::Normal,
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            $this->bindIdentity($user, $identity, $oidc);

            return $user;
        });

        $oidc->pullPendingIdentity();
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(RouteServiceProvider::HOME);
    }

    public function bind(Request $request, CasdoorOidcService $oidc): RedirectResponse
    {
        $identity = $this->pendingIdentity($oidc);

        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        /** @var User|null $user */
        $user = User::query()->where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        DB::transaction(fn () => $this->bindIdentity($user, $identity, $oidc));
        $oidc->pullPendingIdentity();
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(RouteServiceProvider::HOME);
    }

    private function pendingIdentity(CasdoorOidcService $oidc): array
    {
        try {
            return $oidc->getPendingIdentity();
        } catch (Throwable $e) {
            throw ValidationException::withMessages([
                'oauth' => $e->getMessage(),
            ]);
        }
    }

    private function bindIdentity(User $user, array $identity, CasdoorOidcService $oidc): OauthIdentity
    {
        $attributes = $oidc->identityAttributes($identity);

        /** @var OauthIdentity|null $existing */
        $existing = OauthIdentity::query()
            ->where('provider', $attributes['provider'])
            ->where('provider_user_id', $attributes['provider_user_id'])
            ->first();

        if ($existing) {
            if ($existing->user_id !== $user->id) {
                throw ValidationException::withMessages([
                    'oauth' => '该 Casdoor 账号已经绑定到其他用户。',
                ]);
            }

            $existing->update($attributes);
            return $existing;
        }

        return $user->oauthIdentities()->create($attributes);
    }
}
