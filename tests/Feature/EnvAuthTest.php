<?php

namespace LogInsight\Tests\Feature;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use LogInsight\Livewire\LogBrowser;
use LogInsight\LogInsight;
use LogInsight\Models\AuditLog;
use LogInsight\Security\EnvAuth;
use LogInsight\Tests\TestCase;

class EnvAuthTest extends TestCase
{
    private const PASSWORD = 'correct horse battery staple';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('log-insight.auth.mode', 'env');
        config()->set('log-insight.auth.credentials', [
            'username' => 'admin',
            'password' => null,
            'password_hash' => Hash::make(self::PASSWORD),
        ]);
        RateLimiter::clear('log-insight-login-ip:127.0.0.1');
        // The test base runs as "local", where Laravel enforces CSRF: use real tokens, like a browser would.
        $this->startSession();
    }

    private function postWithToken(string $uri, array $data = [])
    {
        return $this->post($uri, $data + ['_token' => session()->token()]);
    }

    private function login(string $user = 'admin', string $pass = self::PASSWORD)
    {
        return $this->postWithToken('/log-insight/login', ['username' => $user, 'password' => $pass]);
    }

    public function test_everything_redirects_to_the_login_form_until_signed_in(): void
    {
        foreach (['/log-insight', '/log-insight/logs', '/log-insight/issues', '/log-insight/tags', '/log-insight/audit'] as $url) {
            $this->get($url)->assertRedirect(route('log-insight.login'));
        }

        $this->get('/log-insight/login')->assertOk()->assertSee('name="password"', false);
    }

    public function test_correct_credentials_sign_in_and_unlock_every_page(): void
    {
        $this->login()->assertRedirect(route('log-insight.dashboard'));

        foreach (['/log-insight', '/log-insight/logs', '/log-insight/issues', '/log-insight/tags', '/log-insight/audit'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/log-insight/login')->assertRedirect(route('log-insight.dashboard'));
        $this->assertDatabaseHas('li_audit_logs', ['action' => 'login_success', 'target' => 'admin']);
    }

    public function test_wrong_password_or_username_is_rejected_with_one_generic_message_and_audited(): void
    {
        $this->from('/log-insight/login')->login('admin', 'wrong')->assertRedirect('/log-insight/login')->assertSessionHasErrors('username');
        $this->login('nobody', self::PASSWORD)->assertSessionHasErrors('username');

        $this->assertNull(session(EnvAuth::SESSION_KEY));
        $this->get('/log-insight')->assertRedirect(route('log-insight.login'));
        $this->assertSame(2, AuditLog::where('action', 'login_failed')->count());
        // The attempted password is never stored.
        $this->assertStringNotContainsString('wrong', json_encode(AuditLog::all()->toArray()));
        // One generic message for bad user and bad password (no username enumeration).
        $generic = __('log-insight::messages.login.invalid');
        $this->login('admin', 'wrong')->assertSessionHasErrors(['username' => $generic]);
        $this->login('nobody', self::PASSWORD)->assertSessionHasErrors(['username' => $generic]);
    }

    public function test_failed_login_does_not_echo_the_password_back(): void
    {
        $this->from('/log-insight/login')->login('admin', 'sup3rsecret-wrong');
        $html = $this->get('/log-insight/login')->getContent();

        $this->assertStringNotContainsString('sup3rsecret-wrong', $html);
    }

    public function test_plain_env_password_works_only_when_long_enough(): void
    {
        config()->set('log-insight.auth.credentials', ['username' => 'admin', 'password' => 'a-long-passphrase', 'password_hash' => null]);
        $this->login('admin', 'a-long-passphrase')->assertRedirect(route('log-insight.dashboard'));
        $this->postWithToken('/log-insight/logout');

        config()->set('log-insight.auth.credentials', ['username' => 'admin', 'password' => 'short', 'password_hash' => null]);
        $this->assertTrue(EnvAuth::misconfigured());
        $this->login('admin', 'short')->assertSessionHasErrors('username');
        $this->get('/log-insight')->assertRedirect(route('log-insight.login'));

        config()->set('log-insight.auth.credentials', ['username' => 'admin', 'password' => null, 'password_hash' => null]);
        $this->login('admin', '')->assertSessionHasErrors();
    }

    public function test_repeated_failures_lock_the_login_even_for_the_right_password(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->login('admin', 'nope'.$i)->assertSessionHasErrors('username');
        }

        $this->login()->assertSessionHasErrors('username');
        $this->get('/log-insight')->assertRedirect(route('log-insight.login'));
        $this->assertSame(1, AuditLog::where('action', 'login_throttled')->count());
    }

    public function test_one_username_cannot_be_used_to_lock_out_a_different_one(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->login('guesser', 'nope'.$i);
        }

        // Different username, same IP: still allowed (the IP bucket is 4x larger).
        $this->login()->assertRedirect(route('log-insight.dashboard'));
    }

    public function test_logout_ends_the_session(): void
    {
        $this->login();
        $this->get('/log-insight')->assertOk();

        $this->postWithToken('/log-insight/logout')->assertRedirect(route('log-insight.login'));

        $this->get('/log-insight')->assertRedirect(route('log-insight.login'));
        $this->assertDatabaseHas('li_audit_logs', ['action' => 'logout']);
    }

    public function test_session_expires_after_inactivity_and_after_the_absolute_limit(): void
    {
        $this->login();
        $this->get('/log-insight')->assertOk();

        $this->travel(59)->minutes();
        $this->get('/log-insight')->assertOk();              // activity keeps it alive
        $this->travel(61)->minutes();
        $this->get('/log-insight')->assertRedirect(route('log-insight.login'));

        $this->login();
        config()->set('log-insight.auth.idle_minutes', 600);
        for ($i = 0; $i < 9; $i++) {
            $this->travel(1)->hours();
            $this->get('/log-insight');
        }
        $this->get('/log-insight')->assertRedirect(route('log-insight.login'), 'absolute 8h limit');
    }

    public function test_login_and_logout_require_a_csrf_token(): void
    {
        $this->post('/log-insight/login', ['username' => 'admin', 'password' => self::PASSWORD])->assertStatus(419);
        $this->post('/log-insight/logout')->assertStatus(419);
        $this->get('/log-insight')->assertRedirect(route('log-insight.login'));
    }

    public function test_session_id_changes_on_login(): void
    {
        $this->get('/log-insight/login');
        $before = session()->getId();

        $this->login();

        $this->assertNotSame($before, session()->getId());
    }

    public function test_host_app_users_and_callbacks_do_not_bypass_the_env_login(): void
    {
        $this->app['env'] = 'local';
        config()->set('log-insight.allowed_emails', ['dev@example.com']);
        LogInsight::authUsing(fn () => true);

        $user = new \Illuminate\Foundation\Auth\User;
        $user->forceFill(['id' => 1, 'email' => 'dev@example.com']);

        $this->actingAs($user)->get('/log-insight')->assertRedirect(route('log-insight.login'));
        $this->assertFalse(LogInsight::can($user, 'view'));
    }

    public function test_livewire_actions_require_the_env_session_too(): void
    {
        Livewire::test(LogBrowser::class)->assertForbidden();

        $this->login();
        Livewire::test(LogBrowser::class)->assertOk();
    }

    public function test_login_ignores_any_redirect_parameter(): void
    {
        $this->postWithToken('/log-insight/login?redirect=https://evil.example', [
            'username' => 'admin', 'password' => self::PASSWORD, 'redirect' => 'https://evil.example', 'next' => '//evil.example',
        ])->assertRedirect(route('log-insight.dashboard'));
    }

    public function test_login_routes_404_in_app_mode_and_the_language_switch_works_logged_out(): void
    {
        $this->get('/log-insight/locale/ar')->assertRedirect();
        $this->get('/log-insight/login')->assertSee('dir="rtl"', false)->assertSee('تسجيل الدخول');

        config()->set('log-insight.auth.mode', 'app');
        $this->get('/log-insight/login')->assertNotFound();
        $this->postWithToken('/log-insight/login', ['username' => 'a', 'password' => 'b'])->assertNotFound();
    }

    public function test_dashboard_shows_a_sign_out_button_only_in_env_mode(): void
    {
        $this->login();
        $this->get('/log-insight')->assertSee(route('log-insight.logout'), false);

        config()->set('log-insight.auth.mode', 'app');
        $this->app['env'] = 'local';
        $this->get('/log-insight')->assertDontSee(route('log-insight.logout'), false);
    }
}
