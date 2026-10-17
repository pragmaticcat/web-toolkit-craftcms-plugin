<?php

namespace pragmatic\webtoolkit\jobs;

use craft\helpers\StringHelper;
use craft\queue\BaseJob;
use pragmatic\webtoolkit\PragmaticWebToolkit;

class SendMonitorEventJob extends BaseJob
{
    public string $eventId = '';
    public string $type = '';
    public string $occurredAt = '';
    public array $payload = [];
    public array $fingerprints = [];

    public function init(): void
    {
        parent::init();
        $this->eventId = $this->eventId ?: StringHelper::UUID();
        $this->occurredAt = $this->occurredAt ?: gmdate(DATE_ATOM);
    }

    public function execute($queue): void
    {
        PragmaticWebToolkit::$plugin->monitor->send($this->eventId, $this->type, $this->occurredAt, $this->payload, $this->fingerprints);
    }

    protected function defaultDescription(): ?string
    {
        return 'Sending '.$this->type.' event to Pragmatic Web Monitor';
    }
}
