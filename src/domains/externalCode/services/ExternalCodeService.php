<?php

namespace pragmatic\webtoolkit\domains\externalCode\services;

use Craft;
use pragmatic\webtoolkit\PragmaticWebToolkit;

class ExternalCodeService
{
    private const KEY = 'externalCode';
    public const POSITIONS = ['head', 'beginBody', 'endBody'];

    public function all(): array
    {
        $stored = PragmaticWebToolkit::$plugin->domainSettingsStore->get(self::KEY);
        $snippets = array_values(array_filter((array)($stored['snippets'] ?? []), 'is_array'));
        usort($snippets, static fn(array $a, array $b): int => ((int)($a['sortOrder'] ?? 0) <=> (int)($b['sortOrder'] ?? 0)) ?: strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));
        return $snippets;
    }

    public function isEnabledForSite(array $snippet, int $siteId): bool
    {
        if (isset($snippet['enabledBySite']) && is_array($snippet['enabledBySite'])) {
            return !empty($snippet['enabledBySite'][(string)$siteId]);
        }
        if (empty($snippet['enabled'])) {
            return false;
        }
        $sites = array_map('intval', (array)($snippet['siteIds'] ?? []));
        return $sites === [] || in_array($siteId, $sites, true);
    }

    public function siteStates(array $snippet): array
    {
        $states = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $siteId = (int)$site->id;
            $states[$siteId] = $this->isEnabledForSite($snippet, $siteId);
        }
        return $states;
    }

    public function find(string $id): ?array
    {
        foreach ($this->all() as $snippet) {
            if (($snippet['id'] ?? null) === $id) {
                return $snippet;
            }
        }
        return null;
    }

    public function save(array $input, ?string $id = null): bool
    {
        $name = trim((string)($input['name'] ?? ''));
        $code = trim((string)($input['code'] ?? ''));
        $position = (string)($input['position'] ?? 'endBody');
        $category = trim((string)($input['category'] ?? ''));
        $knownSiteIds = array_map(static fn($site): int => (int)$site->id, Craft::$app->getSites()->getAllSites());

        if ($name === '' || $code === '' || !in_array($position, self::POSITIONS, true)) {
            return false;
        }
        if ($category !== '') {
            if (!PragmaticWebToolkit::$plugin->domains->isEnabled('cookies')) {
                return false;
            }
            $categories = PragmaticWebToolkit::$plugin->cookiesCategories->getAllCategories();
            if (!in_array($category, array_map(static fn($item): string => $item->handle, $categories), true)) {
                return false;
            }
        }

        $snippets = $this->all();
        if ($id !== null && $this->find($id) === null) {
            return false;
        }
        $postedStates = (array)($input['enabledBySite'] ?? []);
        $enabledBySite = [];
        foreach ($knownSiteIds as $siteId) {
            $enabledBySite[(string)$siteId] = !empty($postedStates[$siteId]);
        }
        $id ??= \craft\helpers\StringHelper::UUID();
        $row = [
            'id' => $id,
            'name' => $name,
            'code' => $code,
            'position' => $position,
            'category' => $category,
            'enabledBySite' => $enabledBySite,
            'sortOrder' => max(0, (int)($input['sortOrder'] ?? 0)),
        ];
        $replaced = false;
        foreach ($snippets as &$snippet) {
            if (($snippet['id'] ?? null) === $id) {
                $snippet = $row;
                $replaced = true;
                break;
            }
        }
        unset($snippet);
        if (!$replaced) {
            $snippets[] = $row;
        }
        return PragmaticWebToolkit::$plugin->domainSettingsStore->save(self::KEY, ['snippets' => $snippets]);
    }

    public function setSiteEnabled(string $id, int $siteId, bool $enabled): bool
    {
        if (Craft::$app->getSites()->getSiteById($siteId) === null) {
            return false;
        }
        $snippets = $this->all();
        foreach ($snippets as &$snippet) {
            if (($snippet['id'] ?? null) !== $id) {
                continue;
            }
            $states = $this->siteStates($snippet);
            $states[$siteId] = $enabled;
            $snippet['enabledBySite'] = $states;
            unset($snippet['enabled'], $snippet['siteIds']);
            unset($snippet);
            return PragmaticWebToolkit::$plugin->domainSettingsStore->save(self::KEY, ['snippets' => $snippets]);
        }
        unset($snippet);
        return false;
    }

    public function reorder(array $ids): bool
    {
        if (array_filter($ids, static fn($id): bool => !is_string($id) || $id === '') !== []) {
            return false;
        }
        $snippets = $this->all();
        $known = array_column($snippets, 'id');
        if (count($ids) !== count($known) || count(array_unique($ids)) !== count($ids) || array_diff($ids, $known) !== [] || array_diff($known, $ids) !== []) {
            return false;
        }
        $order = array_flip($ids);
        foreach ($snippets as &$snippet) {
            $snippet['sortOrder'] = $order[$snippet['id']] + 1;
        }
        unset($snippet);
        return PragmaticWebToolkit::$plugin->domainSettingsStore->save(self::KEY, ['snippets' => $snippets]);
    }

    public function delete(string $id): bool
    {
        if ($this->find($id) === null) {
            return false;
        }
        $snippets = array_values(array_filter($this->all(), static fn(array $row): bool => ($row['id'] ?? null) !== $id));
        return PragmaticWebToolkit::$plugin->domainSettingsStore->save(self::KEY, ['snippets' => $snippets]);
    }

    public function render(string $position): string
    {
        if (!in_array($position, self::POSITIONS, true)) {
            return '';
        }
        $siteId = (int)Craft::$app->getSites()->getCurrentSite()->id;
        $output = '';
        foreach ($this->all() as $snippet) {
            if (!$this->isEnabledForSite($snippet, $siteId) || ($snippet['position'] ?? null) !== $position) {
                continue;
            }
            $category = (string)($snippet['category'] ?? '');
            $code = (string)($snippet['code'] ?? '');
            if ($category === '') {
                $output .= "\n" . $code . "\n";
                continue;
            }
            if (!PragmaticWebToolkit::$plugin->domains->isEnabled('cookies')) {
                continue;
            }
            $categories = PragmaticWebToolkit::$plugin->cookiesCategories->getAllCategories($siteId);
            if (!in_array($category, array_map(static fn($item): string => $item->handle, $categories), true)) {
                continue;
            }
            $output .= $this->renderConditional($category, $code);
        }
        return $output;
    }

    public function renderConditional(string $category, string $code): string
    {
        $payload = json_encode(['category' => $category, 'code' => $code], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        return "\n<script type=\"application/json\" data-pwt-external-code>{$payload}</script>\n"
            . '<script>' . file_get_contents(__DIR__ . '/../assets/external-code.js') . '</script>';
    }
}
