<?php

namespace App\Alerts;

use App\Enums\ChannelType;
use App\Models\AlertRule;
use App\Models\NotificationChannel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * Envoi des notifications vers les canaux configurés.
 */
class Notifier
{
    private const ICONS = ['critical' => '🔴', 'warning' => '🟠', 'info' => '🔵'];

    /** @return list<array{channel: string, ok: bool, error: string|null, at: string}> */
    public function send(AlertRule $rule, string $title, string $message, string $severity, array $context = []): array
    {
        $deliveries = [];
        foreach ($rule->channels->where('enabled', true) as $channel) {
            $deliveries[] = $this->deliver($channel, $title, $message, $severity, ['rule' => $rule->name, 'type' => $rule->type->value] + $context);
        }

        return $deliveries;
    }

    public function deliver(NotificationChannel $channel, string $title, string $message, string $severity = 'info', array $context = []): array
    {
        $icon = self::ICONS[$severity] ?? '';
        $url = rtrim((string) config('app.url'), '/');
        $text = trim("{$icon} {$title}\n{$message}");
        $c = $channel->config ?? [];

        try {
            match ($channel->type) {
                ChannelType::Email => Mail::raw($text."\n\n".$url, function ($m) use ($c, $title) {
                    $m->to(array_map('trim', explode(',', (string) ($c['to'] ?? ''))))->subject('[Okovision] '.$title);
                }),
                ChannelType::Slack => Http::timeout(10)->post($c['webhook_url'], [
                    'text' => $text,
                    'blocks' => [
                        ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => "*{$icon} {$title}*\n{$message}"]],
                        ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => "<{$url}|Okovision>"]]],
                    ],
                ])->throw(),
                ChannelType::Telegram => $this->telegram($c, $text),
                ChannelType::Webhook => $this->webhook($c, [
                    'title' => $title, 'message' => $message, 'severity' => $severity,
                    'context' => $context, 'sent_at' => now()->toIso8601String(), 'app' => $url,
                ]),
            };
            $channel->update(['last_used_at' => now(), 'last_error' => null]);

            return ['channel' => $channel->name, 'ok' => true, 'error' => null, 'at' => now()->toIso8601String()];
        } catch (\Throwable $e) {
            $error = $this->redact($e->getMessage(), $c);
            $channel->update(['last_error' => mb_substr($error, 0, 500)]);

            return ['channel' => $channel->name, 'ok' => false, 'error' => $error, 'at' => now()->toIso8601String()];
        }
    }

    private function telegram(array $c, string $text): void
    {
        $response = Http::timeout(10)->post('https://api.telegram.org/bot'.$c['bot_token'].'/sendMessage', [
            'chat_id' => $c['chat_id'],
            'text' => $text,
            'disable_web_page_preview' => true,
        ]);

        if (! $response->json('ok')) {
            // Telegram renvoie un message explicite (« chat not found », « bots can't send messages to bots »…)
            throw new \RuntimeException('Telegram : '.($response->json('description') ?? 'HTTP '.$response->status()));
        }
    }

    /** Retire les secrets (jeton du bot, URL Slack) des messages d'erreur affichés et stockés. */
    private function redact(string $message, array $c): string
    {
        foreach (['bot_token', 'webhook_url', 'secret'] as $key) {
            if (! empty($c[$key])) {
                $message = str_replace((string) $c[$key], '***', $message);
            }
        }

        return $message;
    }

    private function webhook(array $c, array $payload): void
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = ['Content-Type' => 'application/json'];
        if (! empty($c['secret'])) {
            // Signature HMAC-SHA256 du corps, à vérifier côté récepteur
            $headers['X-Okovision-Signature'] = 'sha256='.hash_hmac('sha256', $body, $c['secret']);
        }

        Http::timeout(10)->withHeaders($headers)->withBody($body, 'application/json')
            ->send(strtoupper($c['method'] ?? 'POST'), $c['url'])->throw();
    }
}
