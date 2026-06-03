<?php

namespace Tests\Feature\Auth;

use App\Enums\ConfigKey;
use App\Models\Config;
use App\Models\OauthIdentity;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use App\Services\CasdoorOidcService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CasdoorOauthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_shows_casdoor_button_when_enabled()
    {
        config(['services.casdoor.enabled' => true]);

        $this->get('/login')
            ->assertStatus(200)
            ->assertSee('使用 Casdoor 登录');
    }

    public function test_bound_casdoor_identity_can_login_directly()
    {
        $user = User::factory()->create();
        $user->oauthIdentities()->create([
            'provider' => OauthIdentity::PROVIDER_CASDOOR,
            'provider_user_id' => 'casdoor-sub-1',
            'email' => $user->email,
            'name' => $user->name,
        ]);

        $oidc = $this->mock(CasdoorOidcService::class);
        $oidc->shouldReceive('getUserFromCallback')->once()->andReturn([
            'provider' => OauthIdentity::PROVIDER_CASDOOR,
            'provider_user_id' => 'casdoor-sub-1',
            'email' => $user->email,
            'email_verified' => true,
            'name' => $user->name,
            'avatar' => null,
            'raw' => ['sub' => 'casdoor-sub-1'],
        ]);

        $response = $this->get('/auth/casdoor/callback?code=code&state=state');

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(RouteServiceProvider::HOME);
    }

    public function test_unbound_casdoor_identity_shows_callback_choice_page()
    {
        $oidc = $this->mock(CasdoorOidcService::class);
        $identity = $this->verifiedIdentity();
        $oidc->shouldReceive('getUserFromCallback')->once()->andReturn($identity);
        $oidc->shouldReceive('putPendingIdentity')->once()->with($identity);

        $this->get('/auth/casdoor/callback?code=code&state=state')
            ->assertRedirect(route('casdoor.confirm'));
    }

    public function test_casdoor_confirm_page_shows_create_and_bind_options()
    {
        config(['services.casdoor.enabled' => true]);
        session([CasdoorOidcService::SESSION_PENDING => array_merge($this->verifiedIdentity(), [
            'expires_at' => now()->addMinutes(10)->timestamp,
        ])]);

        $this->get('/auth/casdoor/confirm')
            ->assertStatus(200)
            ->assertSee('完成 Casdoor 登录')
            ->assertSee('创建并登录')
            ->assertSee('绑定并登录');
    }

    public function test_pending_verified_casdoor_identity_can_create_user_when_registration_enabled()
    {
        $this->enableRegistration(true);
        session([CasdoorOidcService::SESSION_PENDING => array_merge($this->verifiedIdentity(), [
            'expires_at' => now()->addMinutes(10)->timestamp,
        ])]);

        $response = $this->post('/auth/casdoor/create', ['name' => 'OAuth User']);

        $user = User::query()->where('email', 'oauth@example.com')->first();
        $this->assertNotNull($user);
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('oauth_identities', [
            'user_id' => $user->id,
            'provider' => OauthIdentity::PROVIDER_CASDOOR,
            'provider_user_id' => 'casdoor-sub-new',
        ]);
        $response->assertRedirect(RouteServiceProvider::HOME);
    }

    public function test_casdoor_create_respects_registration_switch()
    {
        $this->enableRegistration(false);
        session([CasdoorOidcService::SESSION_PENDING => array_merge($this->verifiedIdentity(), [
            'expires_at' => now()->addMinutes(10)->timestamp,
        ])]);

        $this->post('/auth/casdoor/create', ['name' => 'OAuth User'])
            ->assertSessionHasErrors('oauth');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'oauth@example.com']);
    }

    public function test_casdoor_create_requires_verified_email()
    {
        $this->enableRegistration(true);
        session([CasdoorOidcService::SESSION_PENDING => array_merge($this->verifiedIdentity([
            'email_verified' => false,
        ]), [
            'expires_at' => now()->addMinutes(10)->timestamp,
        ])]);

        $this->post('/auth/casdoor/create', ['name' => 'OAuth User'])
            ->assertSessionHasErrors('oauth');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'oauth@example.com']);
    }

    public function test_pending_casdoor_identity_can_bind_existing_local_user()
    {
        $user = User::factory()->create([
            'email' => 'local@example.com',
            'password' => Hash::make('secret-password'),
        ]);
        session([CasdoorOidcService::SESSION_PENDING => array_merge($this->verifiedIdentity(), [
            'expires_at' => now()->addMinutes(10)->timestamp,
        ])]);

        $response = $this->post('/auth/casdoor/bind', [
            'email' => 'local@example.com',
            'password' => 'secret-password',
        ]);

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('oauth_identities', [
            'user_id' => $user->id,
            'provider' => OauthIdentity::PROVIDER_CASDOOR,
            'provider_user_id' => 'casdoor-sub-new',
        ]);
        $response->assertRedirect(RouteServiceProvider::HOME);
    }

    public function test_casdoor_bind_rejects_wrong_password()
    {
        User::factory()->create([
            'email' => 'local@example.com',
            'password' => Hash::make('secret-password'),
        ]);
        session([CasdoorOidcService::SESSION_PENDING => array_merge($this->verifiedIdentity(), [
            'expires_at' => now()->addMinutes(10)->timestamp,
        ])]);

        $this->post('/auth/casdoor/bind', [
            'email' => 'local@example.com',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseMissing('oauth_identities', [
            'provider' => OauthIdentity::PROVIDER_CASDOOR,
            'provider_user_id' => 'casdoor-sub-new',
        ]);
    }

    private function verifiedIdentity(array $overrides = []): array
    {
        return array_merge([
            'provider' => OauthIdentity::PROVIDER_CASDOOR,
            'provider_user_id' => 'casdoor-sub-new',
            'email' => 'oauth@example.com',
            'email_verified' => true,
            'name' => 'OAuth User',
            'avatar' => null,
            'raw' => ['sub' => 'casdoor-sub-new'],
        ], $overrides);
    }

    private function enableRegistration(bool $enabled): void
    {
        Config::query()->where('name', ConfigKey::IsEnableRegistration)->update([
            'value' => $enabled ? 1 : 0,
        ]);
        cache()->forget('configs');
    }
}
