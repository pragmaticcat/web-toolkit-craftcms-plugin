<?php

namespace pragmatic\webtoolkit\interfaces;

interface MigrationProviderInterface
{
    /**
     * @return array<int, string>
     */
    public function legacyPluginHandles(): array;

    /**
     * @return array<int, string>
     */
    public function requiredTables(): array;

    public function importLegacySettings(array $legacySettings, array $currentDomainSettings): array;
}
