<?php

namespace LogInsight\Alerts;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/** Delivers an alert to every configured channel (mail, Slack webhook, Telegram bot). */
class AlertNotifier
{
    public function send(string $title, string $body): void
    {
        $app = config('app.name', 'Laravel');
        $link = url(config('log-insight.route_prefix'));
        $text = "{$title}{$app}\n{$body}\n{$link}";
        $channels = config('log-insight.alerts.channels', []);

        $this->safely(function () use ($channels, $title, $text) {
            $to = $channels['mail']['to'] ?? [];
            if ($to) {
                Mail::raw($text, fn ($m) => $m->to($to)->subject(trim($title).' '.config('app.name')));
            }
        });

        $this->safely(function () use ($channels, $text) {
            if (! empty($channels['slack']['webhook_url'])) {
                Http::timeout(10)->post($channels['slack']['webhook_url'], ['text' => $text])->throw();
            }
        });

        $this->safely(function () use ($channels, $text) {
            $tg = $channels['telegram'] ?? [];
            if (! empty($tg['bot_token']) && ! empty($tg['chat_id'])) {
                Http::timeout(10)->post("https://api.telegram.org/bot{$tg['bot_token']}/sendMessage", [
                    'chat_id' => $tg['chat_id'],
                    'text' => mb_substr($text, 0, 4000),
                    'disable_web_page_preview' => true,
                ])->throw();
            }
        });
    }

    /** One broken channel must not stop the others (or the ingest job). */
    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            // Report the class + message only: the exception from Http->throw() can contain the bot token in the URL.
            report(new \RuntimeException('Log Insight alert delivery failed: '.preg_replace('#bot[^/]+/#', 'bot***/', $e->getMessage())));
        }
    }
}
