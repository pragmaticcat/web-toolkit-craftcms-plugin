<?php

namespace pragmatic\webtoolkit\domains\redirects\services;

/**
 * Tracks the persisted URI for each element site save until its after-save event.
 */
class EntryUriRedirectTracker
{
    /** @var array<string, string> URIs keyed by element and site IDs. */
    private array $uris = [];

    /**
     * Remember an element's existing URI before saving it for a site.
     *
     * @param int $elementId Element ID.
     * @param int $siteId Site ID.
     * @param string $uri Existing URI.
     * @return void
     */
    public function capture(int $elementId, int $siteId, string $uri): void
    {
        $this->uris[$this->key($elementId, $siteId)] = $uri;
    }

    /**
     * Consume the prior URI for one site save.
     *
     * @param int $elementId Element ID.
     * @param int $siteId Site ID.
     * @return string|null Prior URI, if captured.
     */
    public function consume(int $elementId, int $siteId): ?string
    {
        $key = $this->key($elementId, $siteId);
        $uri = $this->uris[$key] ?? null;
        unset($this->uris[$key]);
        return $uri;
    }

    /**
     * Build the tracker key.
     *
     * @param int $elementId Element ID.
     * @param int $siteId Site ID.
     * @return string Tracker key.
     */
    private function key(int $elementId, int $siteId): string
    {
        return $elementId . ':' . $siteId;
    }
}
