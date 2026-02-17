<?php

namespace pragmatic\webtoolkit\domains\mcp;

use pragmatic\webtoolkit\interfaces\FeatureProviderInterface;

class McpFeature implements FeatureProviderInterface
{
    public static function domainKey(): string { return 'mcp'; }
    public static function navLabel(): string { return 'MCP'; }
    public static function cpSubpath(): string { return 'mcp'; }
    public function cpRoutes(): array
    {
        return [
            'pragmatic-toolkit/mcp' => 'pragmatic-web-toolkit/domain/view?domain=mcp',
        ];
    }
    public function siteRoutes(): array
    {
        return [];
    }
    public function permissions(): array
    {
        return ['pragmatic-toolkit:mcp' => ['label' => 'Manage MCP']];
    }
    public function injectFrontendHtml(string $html): string
    {
        return $html;
    }
}
