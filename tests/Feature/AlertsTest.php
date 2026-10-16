<?php

namespace LogInsight\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use LogInsight\Alerts\AlertEvaluator;
use LogInsight\Models\LogEntry;
use LogInsight\Models\LogFile;
use LogInsight\Models\LogIssue;
use LogInsight\Tests\TestCase;

class AlertsTest extends TestCase
{
    private LogFile $file;
    private int $offset = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 12:00:00');
        $this->file = LogFile::create(['path' => '/x/laravel.log', 'type' => 'laravel']);

        config()->set('log-insight.alerts.enabled', true);
        config()->set('log-insight.alerts.channels', [
            'mail' => ['to' => ['ops@example.com']],
            'slack' => ['webhook_url' => 'https://hooks.slack.test/T/B/X'],
            'telegram' => ['bot_token' => '123:SECRET', 'chat_id' => '-100'],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function errors(int $count, string $when = '2026-10-15 11:55:00'): void
    {
        for ($i = 0; $i < $count; $i++) {
            LogEntry::create(['log_file_id' => $this->file->id, 'logged_at' => $when, 'level' => 'error', 'message' => 'x', 'byte_offset' => $this->offset++]);
        }
    }

    public function test_threshold_alert_goes_to_mail_slack_and_telegram(): void
    {
        config()->set('log-insight.alerts.rules', [['name' => 'Burst', 'type' => 'threshold', 'count' => 5, 'minutes' => 10, 'cooldown' => 0]]);
        Http::fake();
        config()->set('mail.default', 'array');
        $this->errors(5);

        $sent = app(AlertEvaluator::class)->evaluate();

        $this->assertSame(1, $sent);
        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://hooks.slack.test/') && str_contains($r['text'], '5 matching log entries'));
        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://api.telegram.org/bot123:SECRET/sendMessage') && $r['chat_id'] === '-100');

        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $mail = $messages[0]->getOriginalMessage();
        $this->assertSame('ops@example.com', $mail->getTo()[0]->getAddress());
        $this->assertStringContainsString('Burst', $mail->getSubject());
        $this->assertStringContainsString('5 matching log entries', $mail->getBody()->bodyToString());
    }

    public function test_below_threshold_or_outside_the_window_does_not_alert(): void
    {
        config()->set('log-insight.alerts.rules', [['name' => 'Burst', 'type' => 'threshold', 'count' => 5, 'minutes' => 10, 'cooldown' => 0]]);
        Http::fake();
        $this->errors(4);
        $this->errors(50, '2026-10-15 09:00:00');

        $this->assertSame(0, app(AlertEvaluator::class)->evaluate());
        Http::assertNothingSent();
    }

    public function test_cooldown_prevents_repeat_alerts(): void
    {
        config()->set('log-insight.alerts.rules', [['name' => 'Burst', 'type' => 'threshold', 'count' => 1, 'minutes' => 10, 'cooldown' => 30]]);
        Http::fake();
        $this->errors(3);

        $this->assertSame(1, app(AlertEvaluator::class)->evaluate());
        $this->assertSame(0, app(AlertEvaluator::class)->evaluate(), 'second run within the cooldown stays quiet');

        Carbon::setTestNow('2026-10-15 12:31:00');
        $this->errors(3, '2026-10-15 12:30:00');
        $this->assertSame(1, app(AlertEvaluator::class)->evaluate(), 'alerts again once the cooldown has passed');
    }

    public function test_new_issue_alert_sets_a_baseline_first_then_reports_only_new_issues(): void
    {
        config()->set('log-insight.alerts.rules', [['name' => 'New', 'type' => 'new_issue', 'cooldown' => 0]]);
        Http::fake();
        LogIssue::create(['fingerprint' => 'old', 'title' => 'Historic', 'level' => 'error', 'occurrences' => 1]);

        $this->assertSame(0, app(AlertEvaluator::class)->evaluate(), 'first run only records the baseline');
        Http::assertNothingSent();

        LogIssue::create(['fingerprint' => 'new', 'title' => 'Brand new failure', 'exception_class' => 'RuntimeException', 'level' => 'error', 'occurrences' => 1]);
        $this->assertSame(1, app(AlertEvaluator::class)->evaluate());
        Http::assertSent(fn ($r) => str_contains($r['text'] ?? '', 'Brand new failure') && ! str_contains($r['text'] ?? '', 'Historic'));

        $this->assertSame(0, app(AlertEvaluator::class)->evaluate(), 'already reported');
    }

    public function test_spike_alert_compares_with_the_same_hour_over_the_past_week(): void
    {
        config()->set('log-insight.alerts.rules', [['name' => 'Spike', 'type' => 'spike', 'factor' => 3, 'min' => 10, 'cooldown' => 0]]);
        Http::fake();
        // quiet history: ~1 error in the same hour on each of the previous 7 days
        for ($d = 1; $d <= 7; $d++) {
            $this->errors(1, Carbon::parse('2026-10-15 11:30:00')->subDays($d)->toDateTimeString());
        }
        $this->errors(15, '2026-10-15 11:30:00');

        $this->assertSame(1, app(AlertEvaluator::class)->evaluate());
        Http::assertSent(fn ($r) => str_contains($r['text'] ?? '', '15 errors in the last hour'));
    }

    public function test_one_failing_channel_does_not_stop_the_others_or_leak_the_bot_token(): void
    {
        config()->set('log-insight.alerts.rules', [['name' => 'Burst', 'type' => 'threshold', 'count' => 1, 'minutes' => 10, 'cooldown' => 0]]);
        config()->set('mail.default', 'array');
        Http::fake([
            'hooks.slack.test/*' => Http::response('boom', 500),
            'api.telegram.org/*' => Http::response('nope', 500), // the exception URL contains the bot token
        ]);
        $reported = [];
        $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class)->reportable(function (\Throwable $e) use (&$reported) {
            $reported[] = $e->getMessage();
        })->stop();
        $this->errors(2);

        $this->assertSame(1, app(AlertEvaluator::class)->evaluate());

        $this->assertCount(2, $reported, 'both failing channels were reported, and neither stopped the other');
        $this->assertCount(1, Mail::mailer('array')->getSymfonyTransport()->messages(), 'mail still went out');
        foreach ($reported as $message) {
            $this->assertStringNotContainsString('SECRET', $message);
        }
    }
}
