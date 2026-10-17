<?php

namespace pragmatic\webtoolkit\services;

use Craft;
use craft\helpers\App;
use pragmatic\webtoolkit\jobs\SendMonitorEventJob;

class MonitorService
{
    public function isConfigured(): bool
    {
        return $this->url() !== '' && $this->siteId() !== '' && $this->secret() !== '';
    }

    public function queue(string $type, array $payload = [], array $fingerprints = []): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        Craft::$app->getQueue()->push(new SendMonitorEventJob([
            'type' => $type,
            'payload' => $payload,
            'fingerprints' => $fingerprints,
        ]));
    }

    public function send(string $eventId, string $type, string $occurredAt, array $payload, array $fingerprints): void
    {
        $body = json_encode([
            'event_id' => $eventId,
            'type' => $type,
            'occurred_at' => $occurredAt,
            'payload' => $payload,
            'fingerprints' => $fingerprints,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp = (string)time();
        $signature = hash_hmac('sha256', $timestamp."\nPOST\n".$body, $this->secret());

        Craft::createGuzzleClient(['timeout' => 15])->request('POST', $this->url(), [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-PWT-Site' => $this->siteId(),
                'X-PWT-Timestamp' => $timestamp,
                'X-PWT-Signature' => $signature,
            ],
            'body' => $body,
        ]);
    }

    private function url(): string
    {
        return (string)App::parseEnv('$PWT_MONITOR_EVENTS_URL');
    }

    private function siteId(): string
    {
        return (string)App::parseEnv('$PWT_MONITOR_SITE_ID');
    }

    private function secret(): string
    {
        return (string)App::parseEnv('$PWT_MONITOR_SECRET');
    }
}
