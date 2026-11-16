<?php

namespace pragmatic\webtoolkit\jobs;

use CraftCms\Cms\Support\Str;
use pragmatic\webtoolkit\PragmaticWebToolkit;

class SendMonitorEventJob extends BaseJob
{
    public string $eventId = '';
    public string $type = '';
    public string $occurredAt = '';
    public array $payload = [];
    public array $fingerprints = [];

    protected function init(): void
    {
        $this->eventId = $this->eventId ?: Str::uuid()->toString();
        $this->occurredAt = $this->occurredAt ?: gmdate(DATE_ATOM);
    }

    protected function execute(): void
    {
        PragmaticWebToolkit::$plugin->monitor->send($this->eventId, $this->type, $this->occurredAt, $this->payload, $this->fingerprints);
    }

    protected function defaultDescription(): string
    {
        return 'Sending '.$this->type.' event to Pragmatic Web Monitor';
    }
}
