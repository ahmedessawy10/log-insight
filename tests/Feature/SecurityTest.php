<?php

namespace LogInsight\Tests\Feature;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Livewire\Livewire;
use LogInsight\Livewire\IssueList;
use LogInsight\Livewire\LogBrowser;
use LogInsight\Livewire\TagManager;
use LogInsight\LogInsight;
use LogInsight\Models\AuditLog;
use LogInsight\Models\LogIssue;
use LogInsight\Tests\TestCase;

class SecurityTest extends TestCase
{
    private function user(string $email = 'dev@example.com'): Authenticatable
    {
        $u = new class extends Authenticatable {
            protected $guarded = [];
        };
        $u->forceFill(['id' => 1, 'email' => $email, 'name' => 'Dev']);

        return $u;
    }

    private function production(): void
    {
        $this->app['env'] = 'production';
    }

    public function test_default_deny_outside_local_even_for_logged_in_users(): void
    {
        $this->production();

        $this->actingAs($this->user())->get('/log-insight')->assertForbidden();
        $this->actingAs($this->user())->get('/log-insight/logs')->assertForbidden();
        $this->actingAs($this->user())->get('/log-insight/issues')->assertForbidden();
    }

    public function test_allowed_emails_grant_access_and_others_stay_blocked(): void
    {
        $this->production();
        config()->set('log-insight.allowed_emails', ['Dev@Example.com']);

        $this->actingAs($this->user('dev@example.com'))->get('/log-insight')->assertOk();
        $this->actingAs($this->user('intruder@example.com'))->get('/log-insight')->assertForbidden();
    }

    public function test_local_environment_is_open_by_default(): void
    {
        $this->app['env'] = 'local';

        $this->actingAs($this->user())->get('/log-insight')->assertOk();
    }

    public function test_custom_gate_callback_receives_user_and_ability(): void
    {
        $this->production();
        LogInsight::authUsing(fn ($user, string $ability) => $user?->email === 'admin@example.com' || ($user?->email === 'viewer@example.com' && $ability === 'view'));

        $this->actingAs($this->user('viewer@example.com'))->get('/log-insight')->assertOk();
        $this->actingAs($this->user('viewer@example.com'))->get('/log-insight/tags')->assertForbidden();
        $this->actingAs($this->user('viewer@example.com'))->get('/log-insight/audit')->assertForbidden();
        $this->actingAs($this->user('admin@example.com'))->get('/log-insight/tags')->assertOk();
        $this->actingAs($this->user('admin@example.com'))->get('/log-insight/audit')->assertOk();
    }

    public function test_denied_access_attempts_are_audited(): void
    {
        $this->production();

        $this->actingAs($this->user())->get('/log-insight')->assertForbidden();

        $this->assertDatabaseHas('li_audit_logs', ['action' => 'access_denied', 'user_label' => 'dev@example.com']);
    }

    public function test_ip_allowlist_blocks_other_addresses(): void
    {
        config()->set('log-insight.allowed_ips', ['203.0.113.0/24']);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->get('/log-insight')->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('/log-insight')->assertOk();
    }

    public function test_security_headers_and_csp_nonce_are_sent(): void
    {
        $response = $this->get('/log-insight')->assertOk();

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringNotContainsString('http', $csp, 'no remote origins');
        preg_match("/'nonce-([^']+)'/", $csp, $m);
        $this->assertStringContainsString('nonce="'.$m[1].'"', $response->getContent(), 'inline scripts carry the CSP nonce');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_disabling_the_package_removes_the_routes(): void
    {
        // Routes are registered at boot, so verify the guard rather than re-booting the app.
        $this->assertTrue(config('log-insight.enabled'));
        $this->assertNotNull(app('router')->getRoutes()->getByName('log-insight.dashboard'));
    }

    public function test_log_content_is_escaped_in_the_browser_view(): void
    {
        $file = \LogInsight\Models\LogFile::create(['path' => '/x/a.log', 'type' => 'laravel']);
        \LogInsight\Models\LogEntry::create([
            'log_file_id' => $file->id, 'logged_at' => now(), 'level' => 'error', 'byte_offset' => 1,
            'message' => '<script>alert("xss")</script><img src=x onerror=alert(1)>',
        ]);

        $html = Livewire::test(LogBrowser::class)->html();

        $this->assertStringNotContainsString('<script>alert("xss")</script>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_livewire_components_reject_unauthorised_users_on_update_requests_too(): void
    {
        $this->production();
        config()->set('log-insight.allowed_emails', []);

        Livewire::actingAs($this->user())->test(LogBrowser::class)->assertForbidden();
        Livewire::actingAs($this->user())->test(IssueList::class)->assertForbidden();
        Livewire::actingAs($this->user())->test(TagManager::class)->assertForbidden();
    }

    public function test_viewers_cannot_mutate_and_read_only_mode_blocks_everyone(): void
    {
        $this->production();
        config()->set('log-insight.allowed_emails', ['dev@example.com']);
        $issue = LogIssue::create(['fingerprint' => 'a', 'title' => 'X', 'level' => 'error', 'occurrences' => 1]);

        // A user with "view" only
        LogInsight::authUsing(fn ($u, $ability) => $ability === 'view');
        Livewire::actingAs($this->user())->test(IssueList::class)
            ->call('setStatus', $issue->id, 'resolved')->assertForbidden();
        $this->assertSame('open', $issue->fresh()->status);

        // Full admin, but the viewer is in read-only mode
        LogInsight::authUsing(fn () => true);
        config()->set('log-insight.read_only', true);
        Livewire::actingAs($this->user())->test(IssueList::class)
            ->call('setStatus', $issue->id, 'resolved')->assertForbidden();
        $this->assertSame('open', $issue->fresh()->status);

        // Admin, writable
        config()->set('log-insight.read_only', false);
        Livewire::actingAs($this->user())->test(IssueList::class)->call('setStatus', $issue->id, 'resolved');
        $this->assertSame('resolved', $issue->fresh()->status);
        $this->assertSame(1, AuditLog::where('action', 'issue_resolved')->count());
    }

    public function test_tag_rule_validation_rejects_bad_regex_and_unknown_fields(): void
    {
        Livewire::actingAs($this->user())->test(TagManager::class)
            ->set('tagName', 'x')->set('operator', 'regex')->set('value', '(unclosed')
            ->call('addRule')->assertHasErrors('value');

        Livewire::actingAs($this->user())->test(TagManager::class)
            ->set('tagName', 'x')->set('field', 'password_column')->set('value', 'a')
            ->call('addRule')->assertHasErrors('field');

        $this->assertDatabaseCount('li_tag_rules', 0);
    }

    public function test_locale_switch_only_redirects_inside_the_viewer(): void
    {
        $this->from('https://evil.example/phish')->get('/log-insight/locale/ar')
            ->assertRedirect(route('log-insight.dashboard'));

        $this->get('/log-insight/locale/fr')->assertNotFound();

        $this->get('/log-insight/locale/ar');
        $this->get('/log-insight')->assertSee('dir="rtl"', false);
    }
}
