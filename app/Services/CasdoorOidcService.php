<?php

namespace App\Services;

use App\Enums\ConfigKey;
use App\Models\OauthIdentity;
use App\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class CasdoorOidcService
{
    public const SESSION_STATE = 'casdoor_oauth_state';
    public const SESSION_PENDING = 'pending_oauth_identity';

    private const DISCOVERY_CACHE_KEY = 'casdoor_oidc_discovery';

    public function enabled(): bool
    {
        return (bool) $this->setting('enabled', false);
    }

    public function getAuthorizationUrl(): string
    {
        $this->ensureEnabled();

        $state = Str::random(40);
        session()->put(self::SESSION_STATE, $state);

        return $this->buildUrl($this->discovery('authorization_endpoint'), [
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => $this->setting('scope', 'openid profile email'),
            'state' => $state,
        ]);
    }

    public function getUserFromCallback(string $code, string $state): array
    {
        $this->ensureEnabled();

        $expectedState = session()->pull(self::SESSION_STATE);
        if (! $expectedState || ! hash_equals($expectedState, $state)) {
            throw new RuntimeException('OAuth state 校验失败，请重新登录。');
        }

        $token = $this->exchangeCodeForToken($code);
        $user = $this->fetchUserInfo($token);
        $normalized = $this->normalizeUser($user);

        if (! $normalized['provider_user_id']) {
            throw new RuntimeException('Casdoor 未返回用户唯一标识 sub。');
        }

        return $normalized;
    }

    public function putPendingIdentity(array $identity): void
    {
        session()->put(self::SESSION_PENDING, array_merge($identity, [
            'expires_at' => now()->addMinutes($this->pendingTtl())->timestamp,
        ]));
    }

    public function getPendingIdentity(): array
    {
        $identity = session()->get(self::SESSION_PENDING);

        if (! is_array($identity) || ($identity['expires_at'] ?? 0) < now()->timestamp) {
            session()->forget(self::SESSION_PENDING);
            throw new RuntimeException('OAuth 登录状态已过期，请重新发起 Casdoor 登录。');
        }

        unset($identity['expires_at']);

        return $identity;
    }

    public function pullPendingIdentity(): array
    {
        $identity = $this->getPendingIdentity();
        session()->forget(self::SESSION_PENDING);

        return $identity;
    }

    public function keepPendingIdentity(array $identity): void
    {
        $this->putPendingIdentity($identity);
    }

    public function canCreateUser(array $identity): bool
    {
        return ! empty($identity['email']) && ($identity['email_verified'] ?? false);
    }

    public function identityAttributes(array $identity): array
    {
        return [
            'provider' => OauthIdentity::PROVIDER_CASDOOR,
            'provider_user_id' => $identity['provider_user_id'],
            'email' => $identity['email'] ?? null,
            'name' => $identity['name'] ?? null,
            'avatar' => $identity['avatar'] ?? null,
            'raw' => $identity['raw'] ?? [],
        ];
    }

    private function exchangeCodeForToken(string $code): array
    {
        $response = Http::asForm()->post($this->discovery('token_endpoint'), [
            'grant_type' => 'authorization_code',
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'redirect_uri' => $this->redirectUri(),
            'code' => $code,
        ]);

        if (! $response->successful()) {
            Log::warning('Casdoor token exchange failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException('Casdoor 授权换取 Token 失败。');
        }

        return $response->json();
    }

    private function fetchUserInfo(array $token): array
    {
        $accessToken = $token['access_token'] ?? null;
        if (! $accessToken) {
            throw new RuntimeException('Casdoor 未返回 access_token。');
        }

        $response = Http::withToken($accessToken)->get($this->discovery('userinfo_endpoint'));

        if (! $response->successful()) {
            Log::warning('Casdoor userinfo failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException('读取 Casdoor 用户信息失败。');
        }

        return $response->json();
    }

    private function normalizeUser(array $user): array
    {
        $emailVerified = Arr::get($user, 'email_verified', false);
        if (is_string($emailVerified)) {
            $emailVerified = in_array(strtolower($emailVerified), ['1', 'true', 'yes'], true);
        }

        return [
            'provider' => OauthIdentity::PROVIDER_CASDOOR,
            'provider_user_id' => (string) Arr::get($user, 'sub', ''),
            'email' => Arr::get($user, 'email'),
            'email_verified' => (bool) $emailVerified,
            'name' => Arr::get($user, 'name') ?: Arr::get($user, 'displayName') ?: Arr::get($user, 'preferred_username'),
            'avatar' => Arr::get($user, 'picture') ?: Arr::get($user, 'avatar'),
            'raw' => $user,
        ];
    }

    private function discovery(string $key): string
    {
        $metadata = cache()->remember(self::DISCOVERY_CACHE_KEY.':'.md5($this->issuer()), now()->addHour(), function () {
            $response = Http::get(rtrim($this->issuer(), '/').'/.well-known/openid-configuration');

            if (! $response->successful()) {
                throw new ConnectionException('读取 Casdoor OIDC discovery 失败。');
            }

            return $response->json();
        });

        if (empty($metadata[$key])) {
            throw new RuntimeException("Casdoor OIDC discovery 缺少 {$key}。");
        }

        return $metadata[$key];
    }

    private function ensureEnabled(): void
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Casdoor 登录未启用。');
        }

        foreach (['issuer', 'client_id', 'client_secret', 'redirect'] as $key) {
            if (! $this->setting($key)) {
                throw new RuntimeException("Casdoor 配置缺少 {$key}。");
            }
        }
    }

    private function buildUrl(string $url, array $query): string
    {
        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function issuer(): string
    {
        return (string) $this->setting('issuer');
    }

    private function clientId(): string
    {
        return (string) $this->setting('client_id');
    }

    private function clientSecret(): string
    {
        return (string) $this->setting('client_secret');
    }

    private function redirectUri(): string
    {
        return (string) ($this->setting('redirect') ?: url('/auth/casdoor/callback'));
    }

    private function pendingTtl(): int
    {
        return (int) $this->setting('pending_ttl', 10);
    }

    private function setting(string $key, mixed $default = null): mixed
    {
        return Utils::config(ConfigKey::Casdoor, collect(config('convention.app.'.ConfigKey::Casdoor, [])))->get($key, $default);
    }
}
